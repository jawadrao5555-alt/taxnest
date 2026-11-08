<?php

namespace App\Services;

use App\Models\PricingPlan;
use App\Models\Company;
use App\Models\Subscription;
use App\Services\DiPlanComparisonService;
use App\Services\PlanSellabilityService;
use App\Support\NestErps;
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

    /** POS/FBR POS/ERPS remain annual-only; DI short periods require configured prices. */
    public const SELLABLE_CYCLES = ['annual'];

    /** The cycle any new purchase / renewal / approval is charged on. */
    public static function purchaseCycle(?string $requested = null, ?string $productType = null): string
    {
        if ($productType !== 'di' || $requested === null || $requested === '') return 'annual';
        if (!in_array($requested, ['monthly', 'quarterly', 'semi_annual', 'annual', 'yearly'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['billing_cycle' => 'Choose a supported billing period.']);
        }
        return self::normalizeCycle($requested);
    }

    /** DI short cycles require an explicitly configured price; other panels remain annual. */
    public static function cyclesForPlan(PricingPlan $plan): array
    {
        if ($plan->product_type !== 'di') return self::SELLABLE_CYCLES;
        return array_values(array_filter(['monthly', 'quarterly', 'semi_annual', 'annual'],
            fn ($cycle) => $cycle === 'annual' || $plan->explicitCyclePrice($cycle) !== null));
    }

    public static function cycleForPlan(PricingPlan $plan, ?string $requested): string
    {
        $cycle = self::purchaseCycle($requested, $plan->product_type);
        if (!in_array($cycle, self::cyclesForPlan($plan), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'billing_cycle' => 'That billing cycle has no configured price for this package.',
            ]);
        }
        return $cycle;
    }

    /**
     * Product-type-aware price computation. Price semantics differ per product:
     *  - di:               sale_price is the MONTHLY base  → apply the cycle discount.
     *  - pos / standalone: sale_price is ALREADY the ANNUAL total (6% baked in) → use as-is (annual only).
     *  - fbrpos:           sale_price is ALREADY the ANNUAL total              → use as-is (annual only).
     *  - health:           sale_price is ALREADY the ANNUAL total              → use as-is (annual only).
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

        // Keep non-DI annual prices intact; DI can select only a configured short period.
        $cycle = self::cycleForPlan($plan, $cycle);

        if ($type === 'pos' || $type === 'fbrpos' || $type === 'standalone' || NestErps::isProductType($type)) {
            // Both POS lines, standalone and Nest ERPS store the ANNUAL total
            // in `price` (6% already baked in) — use it as-is, never × 12.
            return [
                'cycle' => 'annual',
                'final_price' => round((float) $plan->sale_price),
                'discount_percent' => 6.0,
            ];
        }

        // di (and any unknown type) → the plan's own annual rate when it has
        // one (Sep 2026 packages), else the monthly base × 12 with the annual
        // discount. Unchanged arithmetic: only the cycle choice is gone.
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
    public static function assign(int $companyId, int $pricingPlanId, string $billingCycle = 'annual', bool $reuseCurrentPlan = false, bool $paidRenewal = false, ?array $approvedQuote = null): Subscription
    {
        return DB::transaction(function () use ($companyId, $pricingPlanId, $billingCycle, $reuseCurrentPlan, $paidRenewal, $approvedQuote) {
            // This is the subscription-replacement mutex. Add-on approval takes
            // the same company lock, so it can never attach a paid feature to a
            // package renewal that is being replaced at the same moment.
            $company = Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            // Price and stored plan terms must describe the same catalogue
            // revision, including when an admin edits a plan concurrently.
            $plan = PricingPlan::whereKey($pricingPlanId)->lockForUpdate()->firstOrFail();
            if (PlanSellabilityService::isRetired($plan)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'pricing_plan_id' => PlanSellabilityService::retiredMessage($plan),
                ]);
            }
            // Validate under the company lock, before touching the current
            // subscription, payment attribution or commission history.
            PlanCompanyCompatibility::assertMatches($company, $plan);

            // The admin Assign button is idempotent for a still-current plan.
            // Paid proof approval deliberately passes the default false: each
            // genuine renewal retains its own payment and period snapshot.
            if ($reuseCurrentPlan) {
                $current = Subscription::where('company_id', $companyId)
                    ->where('active', true)->orderByDesc('id')->first();
                if ($current
                    && (int) $current->pricing_plan_id === $pricingPlanId
                    && self::normalizeCycle($current->billing_cycle) === self::purchaseCycle($billingCycle, $plan->product_type)
                    && !$current->isExpired() && !$current->isTrialExpired()) {
                    return $current;
                }
            }

            // Product-type-aware pricing also normalizes/forces the correct cycle
            // (e.g. POS is annual-only), so the expiry below always matches the charge.
            // The company is passed so a renewal is charged base package + the
            // extra-branch slots it has already bought (Rs 10,000/branch/year).
            $priced = self::computePrice($plan, $billingCycle, $company);
            $cycle = $priced['cycle'];
            if ($approvedQuote !== null) {
                $error = DistributorDiscountService::validateSnapshot($approvedQuote, $company, $plan, $cycle);
                if ($error) throw \Illuminate\Validation\ValidationException::withMessages(['billing_cycle' => $error]);
                $priced['discount_percent'] = (float) ($approvedQuote['pricing_snapshot']['discount_percent'] ?? $priced['discount_percent']);
                $priced['base_price'] = round((float) $approvedQuote['gross_package_price'] - (float) $approvedQuote['discount_amount'], 2);
                $priced['extra_branch_price'] = round((float) ($approvedQuote['undiscounted_addon_amount'] ?? 0), 2);
                $priced['final_price'] = round((float) $approvedQuote['net_quote'], 2);
            }
            $months = Subscription::getMonthsForCycle($cycle);

            $active = Subscription::where('company_id', $companyId)
                ->where('active', true)
                ->lockForUpdate()
                ->get();
            if ($paidRenewal && $active->count() > 1) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'pricing_plan_id' => 'Multiple active subscriptions need review before renewing this company.',
                ]);
            }

            $endDate = now()->addMonthsNoOverflow($months);
            $previous = $active->first();
            if ($paidRenewal && $previous
                && (int) $previous->pricing_plan_id === $pricingPlanId
                && self::normalizeCycle($previous->billing_cycle) === $cycle
                && !$previous->trial_ends_at
                && !$previous->hasActiveOverride()
                && (float) $previous->final_price > 0
                && $previous->end_date?->greaterThan(now()->startOfDay())) {
                $endDate = $previous->end_date->copy()->addMonthsNoOverflow($months);
            }
            Subscription::where('company_id', $companyId)
                ->where('active', true)
                ->update(['active' => false]);

            $attributes = [
                'company_id' => $companyId,
                'pricing_plan_id' => $pricingPlanId,
                'start_date' => now()->toDateString(),
                'end_date' => $endDate->toDateString(),
                'active' => true,
                'billing_cycle' => $cycle,
                'discount_percent' => $priced['discount_percent'],
                'final_price' => $priced['final_price'],
            ];
            // Additive schema rollout: older installations retain their
            // existing assignment path until the migration is applied.
            if (\Illuminate\Support\Facades\Schema::hasColumn('subscriptions', 'purchase_snapshot')) {
                $attributes['purchase_snapshot'] = SubscriptionPurchaseSnapshot::capture(
                    $company, $plan, $priced, $attributes['start_date'], $attributes['end_date'], $paidRenewal, $approvedQuote['plan_snapshot'] ?? null
                );
            }
            $subscription = Subscription::create($attributes);

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
        // A signup can sit in the approval queue for months, asking for a
        // package that has since been retired (DI Sep 2026, both POS lines
        // 23 Aug 2026). Approving must not quietly resurrect it — the admin
        // assigns one of the current packages by hand instead.
        if (!$plan || $plan->is_trial || PlanSellabilityService::isRetired($plan)
            || !PlanCompanyCompatibility::matches($company, $plan)) {
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
