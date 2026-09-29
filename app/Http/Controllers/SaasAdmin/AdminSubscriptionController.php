<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\AdminAuditLog;
use App\Services\PlanSellabilityService;
use App\Services\SubscriptionAssignmentService;
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

        return view('saas-admin.subscriptions', compact('companiesPage', 'companySubscriptions', 'companies', 'plans'));
    }

    public function assign(Request $request)
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
            // Annual-only since 23 Aug 2026 (owner). 'yearly' stays accepted as
            // the legacy spelling of the same thing.
            'billing_cycle' => 'required|in:annual,yearly',
        ]);

        $plan = PricingPlan::findOrFail($request->pricing_plan_id);
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
        $outcome = DB::transaction(function () use ($id) {
            $target = Subscription::findOrFail($id);
            Company::withTrashed()->whereKey($target->company_id)->lockForUpdate()->firstOrFail();
            $sub = Subscription::with('pricingPlan')->whereKey($id)->lockForUpdate()->firstOrFail();

            if (Subscription::where('company_id', $sub->company_id)->where('active', true)->count() > 1) {
                return ['error' => 'Multiple active subscriptions need review before changing this company. No records were changed.'];
            }

            if (!$sub->active) {
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
