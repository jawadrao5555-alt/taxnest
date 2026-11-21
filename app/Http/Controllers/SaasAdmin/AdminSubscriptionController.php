<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Services\AdminPackageSettlement;
use App\Services\DistributorDiscountService;
use App\Services\PlanSellabilityService;
use App\Services\SubscriptionAssignmentService;
use App\Services\PlanCompanyCompatibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSubscriptionController extends Controller
{
    public function index(Request $request)
    {
        // Paginate companies, not historical subscription rows. A renewal,
        // trial conversion or plan change must not repeat the company in this
        // operational list; its underlying financial history remains intact.
        $query = Company::withTrashed()->whereHas('subscriptions');

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->whereHas('subscriptions', fn ($q) => $q->where('active', true));
            } elseif ($request->status === 'inactive') {
                $query->whereDoesntHave('subscriptions', fn ($q) => $q->where('active', true));
            }
        }

        $companiesPage = $query
            ->addSelect(['latest_subscription_id' => Subscription::select('id')
                ->whereColumn('company_id', 'companies.id')->orderByDesc('id')->limit(1)])
            ->orderByDesc('latest_subscription_id')
            ->paginate(20)->appends($request->all());
        $companySubscriptions = Subscription::with('pricingPlan')
            ->whereIn('company_id', $companiesPage->pluck('id'))
            ->orderByDesc('active')->orderByDesc('id')->get()
            ->groupBy('company_id');
        $companies = Company::orderBy('name')->get();
        $plans = PricingPlan::orderBy('price')->get()
            ->reject(fn (PricingPlan $plan) => PlanSellabilityService::isRetired($plan))
            ->values();

        $assignmentPlans = $plans->map(fn (PricingPlan $plan) => [
            'id' => (int) $plan->id,
            'cycles' => SubscriptionAssignmentService::cyclesForPlan($plan),
            'label' => $plan->name . ' · ' . PlanSellabilityService::productLabel($plan)
                . ' · PKR ' . number_format(SubscriptionAssignmentService::computePrice($plan, 'annual')['base_price']) . '/year (base)',
            'company_ids' => $companies->filter(fn (Company $company) => PlanCompanyCompatibility::matches($company, $plan))
                ->pluck('id')->map(fn ($id) => (int) $id)->values(),
        ])->values();

        return view('saas-admin.subscriptions', compact('companiesPage', 'companySubscriptions', 'companies', 'plans', 'assignmentPlans'));
    }

    public function quote(Request $request)
    {
        AdminUser::requireBillingAdministrator();
        $data = $request->validate([
            'company_id' => 'required|integer',
            'pricing_plan_id' => 'required|integer',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,annual,yearly',
        ]);
        $company = Company::findOrFail($data['company_id']);
        $plan = PricingPlan::findOrFail($data['pricing_plan_id']);
        PlanCompanyCompatibility::assertMatches($company, $plan);
        if (PlanSellabilityService::isRetired($plan)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['pricing_plan_id' => PlanSellabilityService::retiredMessage($plan)]);
        }
        $cycle = SubscriptionAssignmentService::cycleForPlan($plan, $data['billing_cycle']);
        $activeId = Subscription::where('company_id', $company->id)->where('active', true)->max('id') ?? 0;
        $quote = DistributorDiscountService::quote($company, $plan, $cycle, false);

        return response()->json([
            'amount' => $quote['net_quote'],
            'is_trial' => (bool) $plan->is_trial,
            'expected_subscription_id' => (int) $activeId,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function assign(Request $request)
    {
        $admin = AdminUser::requireBillingAdministrator();
        $request->validate([
            'assignment_mode' => 'sometimes|required|in:received,unpaid',
            'company_id' => 'required|exists:companies,id',
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
            // Annual-only since 23 Aug 2026 (owner). 'yearly' stays accepted as
            // the legacy spelling of the same thing.
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,annual,yearly',
        ]);

        if ($request->input('assignment_mode') === 'received') {
            $payment = $request->validate([
                'verified_received_amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
                'payment_method' => 'required|in:cash,card',
                'payment_date' => 'required|date_format:Y-m-d|before_or_equal:' . now('Asia/Karachi')->toDateString(),
                'reference' => 'nullable|string|max:100',
                'idempotency_key' => 'required|uuid',
                'expected_subscription_id' => 'required|integer|min:0',
            ]);
            $invoice = AdminPackageSettlement::assign(
                (int) $request->company_id, (int) $request->pricing_plan_id,
                $request->billing_cycle, $payment, (int) $admin->id
            );
            $receipt = $invoice->payments()->whereNotNull('receipt_number')->firstOrFail();

            return redirect()->route('saas.admin.subscriptions')
                ->with('success', 'Package assigned and payment recorded.')
                ->with('package_sale_documents', [
                    'company' => $invoice->company_id,
                    'invoice' => $invoice->id,
                    'payment' => $receipt->id,
                    'receipt_number' => $receipt->receipt_number,
                ]);
        }

        $plan = PricingPlan::findOrFail($request->pricing_plan_id);
        if (!in_array(SubscriptionAssignmentService::normalizeCycle($request->billing_cycle), SubscriptionAssignmentService::cyclesForPlan($plan), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['billing_cycle' => 'Choose a configured billing period for this package.']);
        }
        if (PlanSellabilityService::isRetired($plan)) {
            return back()->with('error', PlanSellabilityService::retiredMessage($plan));
        }

        $sub = SubscriptionAssignmentService::assign(
            (int) $request->company_id,
            (int) $request->pricing_plan_id,
            $request->billing_cycle,
            reuseCurrentPlan: true
        );

        if (!$sub->wasRecentlyCreated) {
            return back()->with('success', 'This company already has that active package. No new subscription was created. Use the payment renewal flow for a new paid term.');
        }
        AdminAuditLog::log(auth('admin')->id(), 'Subscription assigned', 'Subscription', $sub->id, [
            'company_id' => $request->company_id,
            'plan' => $plan->name,
        ]);

        return back()->with('success', 'Subscription assigned successfully.');
    }

    public function toggle($id)
    {
        \App\Models\AdminUser::requireBillingAdministrator();
        $outcome = DB::transaction(function () use ($id) {
            $target = Subscription::findOrFail($id);
            Company::withTrashed()->whereKey($target->company_id)->lockForUpdate()->firstOrFail();
            $sub = Subscription::with('pricingPlan')->whereKey($id)->lockForUpdate()->firstOrFail();

            if (Subscription::where('company_id', $sub->company_id)->where('active', true)->count() > 1) {
                return ['error' => 'Multiple active subscriptions need review before changing this company. No records were changed.'];
            }

            if (!$sub->active) {
                PlanCompanyCompatibility::assertMatches(
                    Company::withTrashed()->findOrFail($sub->company_id), $sub->pricingPlan
                );
                if (PlanSellabilityService::isRetired($sub->pricingPlan)) {
                    return ['error' => 'That historical package is retired and cannot be reactivated.'];
                }
                if ($sub->isExpired() || $sub->isTrialExpired()) {
                    return ['error' => 'An expired subscription cannot be reactivated. Assign a current package instead.'];
                }
                if (Subscription::where('company_id', $sub->company_id)->where('active', true)->exists()) {
                    return ['error' => 'This company already has an active subscription. Deactivate it before activating another.'];
                }
                if (Subscription::where('company_id', $sub->company_id)->where('id', '>', $sub->id)->exists()) {
                    return ['error' => 'A historical subscription cannot be reactivated. Assign a current package instead.'];
                }
            }
            $sub->update(['active' => !$sub->active]);
            $action = $sub->active ? 'activated' : 'deactivated';
            AdminAuditLog::log(auth('admin')->id(), "Subscription {$action}", 'Subscription', $sub->id);
            return ['success' => "Subscription {$action}."];
        });

        return back()->with(key($outcome), current($outcome));
    }
}
