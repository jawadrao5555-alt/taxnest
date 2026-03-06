<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;

class AdminSubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $query = Subscription::with(['company', 'pricingPlan'])->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('active', $request->status === 'active');
        }

        $subscriptions = $query->paginate(20)->appends($request->all());
        $companies = Company::orderBy('name')->get();
        $plans = PricingPlan::orderBy('price')->get();

        return view('saas-admin.subscriptions', compact('subscriptions', 'companies', 'plans'));
    }

    public function assign(Request $request)
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $plan = PricingPlan::findOrFail($request->pricing_plan_id);

        Subscription::where('company_id', $request->company_id)->where('active', true)->update(['active' => false]);

        $endDate = $request->billing_cycle === 'yearly' ? now()->addYear() : now()->addMonth();

        $sub = Subscription::create([
            'company_id' => $request->company_id,
            'pricing_plan_id' => $request->pricing_plan_id,
            'start_date' => now()->toDateString(),
            'end_date' => $endDate->toDateString(),
            'active' => true,
            'billing_cycle' => $request->billing_cycle,
            'final_price' => $plan->price,
        ]);

        AdminAuditLog::log(auth('admin')->id(), 'Subscription assigned', 'Subscription', $sub->id, [
            'company_id' => $request->company_id,
            'plan' => $plan->name,
        ]);

        return back()->with('success', 'Subscription assigned successfully.');
    }

    public function toggle($id)
    {
        $sub = Subscription::findOrFail($id);
        $sub->update(['active' => !$sub->active]);

        $action = $sub->active ? 'activated' : 'deactivated';
        AdminAuditLog::log(auth('admin')->id(), "Subscription {$action}", 'Subscription', $sub->id);
        return back()->with('success', "Subscription {$action}.");
    }
}
