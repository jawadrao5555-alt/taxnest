<?php

namespace App\Http\Controllers;

use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Branch;
use App\Services\SecurityLogService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BillingController extends Controller
{
    public function plans()
    {
        // Digital Invoice panel shows ONLY DI plans (POS/FBR POS have their own
        // billing pages), and only the packages still on sale — retired plans
        // keep their rows for existing subscriptions but must not be buyable.
        $plans = PricingPlan::where('is_trial', false)
            ->where('product_type', 'di')
            ->when(
                \Illuminate\Support\Facades\Schema::hasColumn('pricing_plans', 'is_public'),
                fn ($q) => $q->where('is_public', true)
            )
            ->orderBy('price')
            ->get();

        // Only configured DI periods are quoted; cards and checkout share the server rates.
        $planPricing = [];
        foreach ($plans as $plan) {
            foreach (\App\Services\SubscriptionAssignmentService::cyclesForPlan($plan) as $cycle) {
                $planPricing[$plan->id][$cycle] = Subscription::priceForPlanCycle($plan, $cycle);
            }
        }

        $currentSubscription = null;
        $usageData = null;
        $aiPages = null;
        $aiReaderAllowed = false;
        $aiTopupPending = false;

        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
        $pendingSelection = null;
        $pendingSelectionPlan = null;
        $billingHistory = null;
        $billingDocumentRoutes = ['invoice' => 'billing.documents.invoice', 'receipt' => 'billing.documents.receipt'];
        if ($companyId) {
            $billingHistory = \App\Services\SubscriptionBillingHistory::forCompany((int) $companyId);
            $currentSubscription = Subscription::where('company_id', $companyId)
                ->where('active', true)
                ->with('pricingPlan')
                ->first();

            $company = \App\Models\Company::find($companyId);
            if ($company) {
                $selectedId = (int) session(\App\Services\PendingSubscriptionSelection::key((int) $companyId).'.quote.plan_id');
                $selected = $plans->firstWhere('id', $selectedId);
                if ($selected) {
                    $pendingSelection = \App\Services\PendingSubscriptionSelection::quote($company, $selected);
                    $pendingSelectionPlan = $pendingSelection ? $selected : null;
                }
                $aiPages = \App\Services\AiPageCreditService::summary($company);
                $aiReaderAllowed = \App\Services\DiFeatureService::planAllows($company, 'ai_reader');
                $aiTopupPending = \Illuminate\Support\Facades\Schema::hasTable('payment_proofs')
                    && \App\Models\PaymentProof::aiPagesKind()
                        ->where('company_id', $company->id)
                        ->where('status', 'pending')
                        ->exists();
            }

            if ($currentSubscription) {
                // Quota is per calendar month and only FBR-submitted invoices
                // count — the page must show the same number the gate enforces.
                $invoiceCount = \App\Services\PlanLimitService::monthlyInvoiceCount($companyId);
                $limit = $currentSubscription->entitlementPlan()->invoice_limit;

                // An admin override replaces the package limit, so showing the
                // package number here would be a lie on the one screen a shop
                // checks before buying.
                if ($company && $company->invoice_limit_override !== null) {
                    $limit = (int) $company->invoice_limit_override;
                }
                $usagePercent = ($limit > 0 && $limit !== -1) ? round(($invoiceCount / $limit) * 100, 1) : ($limit === -1 ? 0 : 0);
                $daysLeft = Carbon::parse($currentSubscription->end_date)->isFuture()
                    ? (int) now()->startOfDay()->diffInDays(Carbon::parse($currentSubscription->end_date)->startOfDay())
                    : 0;
                $totalDays = Carbon::parse($currentSubscription->start_date)->diffInDays(Carbon::parse($currentSubscription->end_date));

                $userCount = User::where('company_id', $companyId)->where('is_active', true)->count();
                $branchCount = Branch::where('company_id', $companyId)->count();
                $userLimit = $currentSubscription->entitlementPlan()->user_limit;
                $branchLimit = $currentSubscription->entitlementPlan()->branch_limit;

                $trialInfo = null;
                if ($currentSubscription->trial_ends_at) {
                    $trialInfo = [
                        'is_trial' => $currentSubscription->isTrialActive(),
                        'is_expired' => $currentSubscription->isTrialExpired(),
                        'days_left' => $currentSubscription->trial_ends_at->isFuture()
                            ? (int) now()->startOfDay()->diffInDays($currentSubscription->trial_ends_at->copy()->startOfDay())
                            : 0,
                        'ends_at' => $currentSubscription->trial_ends_at->format('M d, Y'),
                    ];
                }

                $usageData = [
                    'invoice_count' => $invoiceCount,
                    'invoice_limit' => $limit,
                    'invoice_limit_display' => $limit === -1 ? 'Unlimited' : $limit,
                    'quota_resets_on' => \App\Services\PlanLimitService::quotaResetsOn(),
                    'has_override' => $company && $company->invoice_limit_override !== null,
                    'usage_percent' => $limit === -1 ? 0 : min(100, $usagePercent),
                    'days_left' => $daysLeft,
                    'total_days' => $totalDays > 0 ? $totalDays : 30,
                    'is_expiring_soon' => $daysLeft <= 7 && Carbon::parse($currentSubscription->end_date)->isFuture(),
                    'is_expired' => Carbon::parse($currentSubscription->end_date)->isPast(),
                    'needs_upgrade' => ($limit !== -1 && $usagePercent >= 80),
                    'trial' => $trialInfo,
                    'user_count' => $userCount,
                    'user_limit' => $userLimit,
                    'user_limit_display' => $userLimit === -1 ? 'Unlimited' : $userLimit,
                    'branch_count' => $branchCount,
                    'branch_limit' => $branchLimit,
                    'branch_limit_display' => $branchLimit === -1 ? 'Unlimited' : $branchLimit,
                    'billing_cycle' => $currentSubscription->billing_cycle ?? 'monthly',
                    'discount_percent' => $currentSubscription->discount_percent ?? 0,
                    'final_price' => $currentSubscription->final_price,
                ];
            }
        }

        // DI periods use their configured prices; other products remain annual.
        // Savings and totals share the same server quote.
        $billingCycles = [
            'monthly' => ['label' => 'Monthly', 'discount' => 0],
            'quarterly' => ['label' => 'Quarterly', 'discount' => 0],
            'semi_annual' => ['label' => 'Semi-Annual', 'discount' => 0],
            'annual' => ['label' => 'Annual', 'discount' => 0],
        ];
        foreach (array_keys($billingCycles) as $cycle) {
            $best = 0;
            foreach ($planPricing as $byCycle) {
                $best = max($best, (float) ($byCycle[$cycle]['discount_percent'] ?? 0));
            }
            // Floor: never advertise a bigger saving than a customer can get.
            $billingCycles[$cycle]['discount'] = (int) floor($best);
        }

        return view('billing.plans', compact(
            'plans',
            'currentSubscription',
            'usageData',
            'billingCycles',
            'planPricing',
            'aiPages',
            'aiReaderAllowed',
            'aiTopupPending',
            'billingHistory',
            'billingDocumentRoutes',
            'pendingSelection',
            'pendingSelectionPlan'
        ));
    }

    public function subscribe(Request $request)
    {
        abort_unless(in_array((string) auth('web')->user()?->role, ['company_admin', 'super_admin'], true), 403);
        $request->validate([
            'plan_id' => 'required|exists:pricing_plans,id',
            // DI's configured periods; 'yearly' remains a legacy annual alias.
            'billing_cycle' => 'nullable|in:monthly,quarterly,semi_annual,annual,yearly',
        ]);

        $plan = PricingPlan::findOrFail($request->plan_id);
        $companyId = app('currentCompanyId');
        // Never silently charge annual when a configured DI short period was selected.
        $cycle = \App\Services\SubscriptionAssignmentService::cycleForPlan($plan, $request->billing_cycle);

        if ($plan->is_trial) {
            return back()->with('error', 'Trial plan cannot be subscribed to directly.');
        }

        if ($plan->product_type !== 'di') {
            return back()->with('error', 'This plan is not available for Digital Invoice accounts.');
        }

        // Retired packages keep working for the companies already on them, but
        // nobody may subscribe to one — the plan id in this POST is attacker
        // controlled, so the check has to live here, not only on the page.
        if (!\App\Services\DiPlanComparisonService::isSellablePlan($plan)) {
            return back()->with('error', 'That package is no longer available. Please choose one of the current packages.');
        }

        $quote = \Illuminate\Support\Facades\DB::transaction(function () use ($companyId, $plan, $cycle) {
            $company = \App\Models\Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $plan = PricingPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($plan->is_trial || $plan->product_type !== 'di' || !\App\Services\DiPlanComparisonService::isSellablePlan($plan)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['plan_id' => 'Please choose a current Digital Invoice package.']);
            }
            $quote = \App\Services\PendingSubscriptionSelection::quote($company, $plan, $cycle)
                ?? \App\Services\DistributorDiscountService::quote($company, $plan, $cycle, true);
            \App\Services\PendingSubscriptionSelection::remember($company, $quote);
            return $quote;
        }, 3);

        return redirect()->route('billing.plans')->with('info',
            'Package selected. Upload your payment receipt for review. Your current package stays in place until approval.');
    }

    /**
     * "Meri AI pages kahan gayin?" — the shop's own page ledger.
     *
     * Reads the same rows the credit service writes, so a batch that ate 40
     * pages and the refund that gave them back are both visible to the shop,
     * not just to us.
     */
    public function aiPagesLedger(Request $request)
    {
        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
        $company = $companyId ? \App\Models\Company::find($companyId) : null;

        if (!$company) {
            return redirect()->route('billing.plans');
        }

        // Same gate as the top-up panel: a package without the reader has no
        // page ledger to show.
        if (!\App\Services\DiFeatureService::planAllows($company, 'ai_reader')) {
            return redirect()->route('billing.plans')
                ->with('error', 'AI Reader is not part of your current package.');
        }

        $summary = \App\Services\AiPageCreditService::summary($company);

        $entries = \App\Models\AiPageLedger::where('company_id', $company->id)
            ->latest('id')
            ->paginate(30);

        // Who ran it, resolved in one query instead of per row.
        $userNames = \App\Models\User::whereIn('id', $entries->pluck('user_id')->filter()->unique())
            ->pluck('name', 'id');

        return view('billing.ai-pages', compact('summary', 'entries', 'userNames'));
    }

    public function calculatePrice(Request $request)
    {
        $request->validate(['plan_id' => 'required|integer', 'billing_cycle' => 'nullable|in:monthly,quarterly,semi_annual,annual,yearly']);
        $plan = PricingPlan::find($request->plan_id);
        // Quoting a retired package would hand back a price for something the
        // subscribe path refuses — same allowlist here.
        if (!$plan || !\App\Services\DiPlanComparisonService::isSellablePlan($plan)) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        $cycle = \App\Services\SubscriptionAssignmentService::cycleForPlan($plan, $request->billing_cycle);
        $pricing = Subscription::priceForPlanCycle($plan, $cycle);
        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
        $pricing = $this->applyDistributorFirstPurchaseDiscount($pricing, $companyId ? \App\Models\Company::find($companyId) : null, $cycle);

        return response()->json($pricing);
    }

    /** Customer-visible quote; never discounts renewals, add-ons, or nonannual sales. */
    private function applyDistributorFirstPurchaseDiscount(array $pricing, ?\App\Models\Company $company, string $cycle): array
    {
        if (!$company || !in_array($cycle, ['annual','yearly'], true)) return $pricing;
        $planId = request()->input('plan_id');
        $plan = $planId ? \App\Models\PricingPlan::find($planId) : null;
        if (!$plan) return $pricing;
        $quote = \App\Services\DistributorDiscountService::quote($company, $plan, $cycle, true);
        $pricing['distributor_discount_percent']=$quote['discount_percent'];
        $pricing['distributor_discount_amount']=$quote['discount_amount'];
        $pricing['final_price']=$quote['net_quote'];
        return $pricing;
    }

    /**
     * Retired Sep 2026 with the three-package restructure.
     *
     * The builder priced itself off its own formula and its own discount
     * ladder, so a shop could subscribe to a package the catalogue had never
     * heard of. Old links land back on the real packages instead of 404-ing.
     */
    public function customPlanBuilder()
    {
        if (!in_array(auth()->user()->role, ['super_admin', 'company_admin'])) {
            abort(403);
        }

        return redirect()->route('billing.plans')
            ->with('info', 'Custom plans are no longer built here — pick one of the three packages, or contact support if you need different limits.');
    }

    public function calculateCustomPlan(Request $request)
    {
        return response()->json([
            'error' => 'Custom plans are no longer available. Choose a current package, or contact support.',
        ], 410);
    }

    public function subscribeCustomPlan(Request $request)
    {
        return redirect()->route('billing.plans')
            ->with('info', 'Custom plans are no longer built here — pick one of the three packages, or contact support if you need different limits.');
    }
}
