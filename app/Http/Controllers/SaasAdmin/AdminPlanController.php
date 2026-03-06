<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\PricingPlan;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;

class AdminPlanController extends Controller
{
    public function index()
    {
        $plans = PricingPlan::orderBy('price')->get();
        return view('saas-admin.plans', compact('plans'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'invoice_limit' => 'required|integer|min:0',
            'max_terminals' => 'nullable|integer|min:0',
            'max_users' => 'nullable|integer|min:0',
            'max_products' => 'nullable|integer|min:0',
            'inventory_enabled' => 'boolean',
            'reports_enabled' => 'boolean',
        ]);

        $plan = PricingPlan::create(array_merge($request->all(), [
            'price_monthly' => $request->price,
        ]));

        AdminAuditLog::log(auth('admin')->id(), 'Plan created', 'PricingPlan', $plan->id, ['name' => $plan->name]);
        return back()->with('success', "Plan '{$plan->name}' created.");
    }

    public function update(Request $request, $id)
    {
        $plan = PricingPlan::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'invoice_limit' => 'required|integer|min:0',
            'max_terminals' => 'nullable|integer|min:0',
            'max_users' => 'nullable|integer|min:0',
            'max_products' => 'nullable|integer|min:0',
        ]);

        $plan->update(array_merge($request->all(), [
            'price_monthly' => $request->price,
            'inventory_enabled' => $request->boolean('inventory_enabled'),
            'reports_enabled' => $request->boolean('reports_enabled'),
        ]));

        AdminAuditLog::log(auth('admin')->id(), 'Plan updated', 'PricingPlan', $plan->id, ['name' => $plan->name]);
        return back()->with('success', "Plan '{$plan->name}' updated.");
    }
}
