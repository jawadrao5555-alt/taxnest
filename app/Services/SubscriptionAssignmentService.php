<?php

namespace App\Services;

use App\Models\PricingPlan;
use App\Models\Company;
use App\Models\Subscription;
use App\Services\DiPlanComparisonService;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for assigning a paid subscription to a company.
 *
 * Used by the admin "Assign Subscription" form AND the payment-proof approval
 * flow, so the two paths can never drift apart. Deactivates any existing active
 * subscription, then creates a fresh active one with a product-type-aware price
 * and a cycle-correct expiry date.
 */
class SubscriptionAssignmentService
{
    /**
     * Canonical cycle set used across the app. Legacy admin forms sent 'yearly';
     * the rest of the codebase (BillingController, Subscription model) uses
     * 'annual'. Normalize so nothing downstream (getMonthsForCycle, getCycleLabel)
     * silently falls back to Monthly.
     */
    public static function normalizeCycle(?string $cycle): string
    {
        $cycle = $cycle ?: 'monthly';
        if ($cycle === 'yearly') {
            return 'annual';
        }
        return in_array($cycle, ['monthly', 'quarterly', 'semi_annual', 'annual'], true)
            ? $cycle
            : 'monthly';
    }

    /**
     * Product-type-aware price computation. Price semantics differ per product:
     *  - di:               sale_price is the MONTHLY base  → apply the cycle discount.
     *  - pos / standalone: sale_price is ALREADY the ANNUAL total (6% baked in) → use as-is (annual only).
     *  - fbrpos:           sale_price is MONTHLY           → annual = sale_price × 12 × 0.94 (6% off).
     *
     * Pass $company to get the TOTAL the shop actually pays: base package +
     * paid extra-branch slots (Rs 10,000/branch/year, PRA POS only). Every
     * surface that shows or charges a renewal total must pass it, so the shop
     * never sees two different numbers.
     *
     * @return array{cycle:string, final_price:float, discount_percent:float, base_price:float, extra_branch_price:float, extra_branch_slots:int}
     */
    public static function computePrice(PricingPlan $plan, string $cycle, ?\App\Models\Company $company = null): array
    {
        $priced = self::computeBasePrice($plan, $cycle);

        $addon = BranchAddonService::addonForCycle($company, $plan, $priced['cycle']);

        return [
            'cycle' => $priced['cycle'],
            'final_price' => $priced['final_price'] + $addon,
            'discount_percent' => $priced['discount_percent'],
            'base_price' => $priced['final_price'],
            'extra_branch_price' => $addon,
            'extra_branch_slots' => $addon > 0 ? BranchAddonService::slots($company) : 0,
        ];
    }

    /**
     * Package ki apni qeemat (add-on ke baghair) — computePrice ka core.
     *
     * @return array{cycle:string, final_price:float, discount_percent:float}
     */
    private static function computeBasePrice(PricingPlan $plan, string $cycle): array
    {
        $type = $plan->product_type ?? 'di';
        $cycle = self::normalizeCycle($cycle);

        if ($type === 'pos' || $type === 'standalone') {
            // Quarterly (Aug 2026, owner-approved): PRA POS ('pos') ONLY, and only
            // when the plan carries an explicit quarterly price (price_quarterly).
            // 'standalone' stays forced-annual even if a quarterly price is ever
            // set on such a plan by mistake. Sale campaigns intentionally
            // discount the ANNUAL price only — quarterly is already the premium path.
            if ($type === 'pos' && $cycle === 'quarterly' && (float) ($plan->price_quarterly ?? 0) > 0) {
                return [
                    'cycle' => 'quarterly',
                    'final_price' => round((float) $plan->price_quarterly),
                    'discount_percent' => 0.0,
                ];
            }

            // Monthly (Aug 2026, owner-approved): PRA POS ONLY, and only when the
            // plan carries an explicit monthly price. Like quarterly it is priced
            // ABOVE the annual pro-rata (~+10%) on purpose, and like quarterly a
            // plan without the price silently falls back to annual below rather
            // than charging a cycle the owner never set.
            if ($type === 'pos' && $cycle === 'monthly' && (float) ($plan->price_monthly ?? 0) > 0) {
                return [
                    'cycle' => 'monthly',
                    'final_price' => round((float) $plan->price_monthly),
                    'discount_percent' => 0.0,
                ];
            }
            return [
                'cycle' => 'annual',
                'final_price' => round((float) $plan->sale_price),
                'discount_percent' => 6.0,
            ];
        }

        if ($type === 'fbrpos') {
            return [
                'cycle' => 'annual',
                'final_price' => round((float) $plan->sale_price * 12 * 0.94),
                'discount_percent' => 6.0,
            ];
        }

        // di (and any unknown type) → the plan's own cycle rate when it has one
        // (Sep 2026 packages), else the monthly base with the cycle discount.
        $pricing = Subscription::priceForPlanCycle($plan, $cycle);

        return [
            'cycle' => $cycle,
            'final_price' => $pricing['final_price'],
            'discount_percent' => $pricing['discount_percent'],
        ];
    }

    /**
     * @param  string  $billingCycle  monthly | quarterly | semi_annual | annual (legacy 'yearly' ok)
     */
    public static function assign(int $companyId, int $pricingPlanId, string $billingCycle = 'monthly'): Subscription
    {
        $plan = PricingPlan::findOrFail($pricingPlanId);
        if ($plan->product_type === 'pos' && !$plan->is_trial
            && !PosPlanComparisonService::isSellablePlan($plan)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'pricing_plan_id' => 'That retired POS package can no longer be assigned.',
            ]);
        }

        return DB::transaction(function () use ($companyId, $pricingPlanId, $billingCycle, $plan) {
            // This is the subscription-replacement mutex. Add-on approval takes
            // the same company lock, so it can never attach a paid feature to a
            // package renewal that is being replaced at the same moment.
            $company = Company::whereKey($companyId)->lockForUpdate()->firstOrFail();

            // Product-type-aware pricing also normalizes/forces the correct cycle
            // (e.g. POS is annual-only), so the expiry below always matches the charge.
            // The company is passed so a renewal is charged base package + the
            // extra-branch slots it has already bought (Rs 10,000/branch/year).
            $priced = self::computePrice($plan, $billingCycle, $company);
            $cycle = $priced['cycle'];
            $months = Subscription::getMonthsForCycle($cycle);

            Subscription::where('company_id', $companyId)
                ->where('active', true)
                ->lockForUpdate()
                ->get();
            Subscription::where('company_id', $companyId)
                ->where('active', true)
                ->update(['active' => false]);

            $subscription = Subscription::create([
                'company_id' => $companyId,
                'pricing_plan_id' => $pricingPlanId,
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonths($months)->toDateString(),
                'active' => true,
                'billing_cycle' => $cycle,
                'discount_percent' => $priced['discount_percent'],
                'final_price' => $priced['final_price'],
            ]);

            // Affiliate program: every admin-recorded payment of a referred company
            // earns its consultant a commission. Internally duplicate-safe and
            // failure-safe (never blocks the assignment itself).
            ConsultantService::recordCommissionForSubscription($subscription);

            return $subscription;
        });
    }

    /**
     * Owner rule (Jul 2026): a shop picks its package before signing up
     * (companies.requested_plan_id); admin approval activates EXACTLY that
     * plan. Called from BOTH admin approve paths
     * (SaasAdmin\AdminCompanyController::approve + AdminController::approveCompany)
     * so they can never drift apart.
     *
     * Task 1484 — FBR POS and Digital Invoice signups now record a package too,
     * so the charged period must follow the product, not a hardcoded year:
     * Digital Invoice on the cycle the visitor picked on the pricing page
     * (monthly by default), PRA POS and FBR POS yearly as before.
     *
     * No-ops when: no requested plan (legacy registrations), the plan was
     * deleted/trial, or a paid (non-trial) subscription is already active
     * (admin assigned one manually first — never stomp it).
     */
    public static function assignRequestedPlanOnApproval(\App\Models\Company $company): ?Subscription
    {
        $planId = $company->requested_plan_id ?? null;
        if (!$planId) {
            return null;
        }

        $plan = PricingPlan::find($planId);
        if (!$plan || $plan->is_trial
            || ($plan->product_type === 'pos' && !PosPlanComparisonService::isSellablePlan($plan))
            // A signup that predates the Sep 2026 DI restructure can still be
            // sitting in the approval queue asking for a package that is no
            // longer sold. Approving must not quietly resurrect it — the admin
            // assigns one of the current packages by hand instead.
            || ($plan->product_type === 'di' && !DiPlanComparisonService::isSellablePlan($plan))) {
            return null;
        }

        $hasPaidActive = Subscription::where('company_id', $company->id)
            ->where('active', true)
            ->whereHas('pricingPlan', function ($q) {
                $q->where('is_trial', false);
            })
            ->exists();
        if ($hasPaidActive) {
            return null;
        }

        // assign() deactivates the 3-day trial row and prices the plan for this
        // cycle → end_date = start + the cycle's months. After that the shop
        // must renew (expiry is enforced by PlanLimitService).
        $cycle = RequestedPackageService::cycleForPlan($plan, $company->requested_billing_cycle ?? null);

        return self::assign($company->id, $plan->id, $cycle);
    }
}
