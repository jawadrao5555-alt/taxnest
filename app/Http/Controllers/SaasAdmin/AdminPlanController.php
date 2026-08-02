<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\PricingPlan;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Collection;

class AdminPlanController extends Controller
{
    public function index()
    {
        // Defensive: if the product_type column has not been migrated on this
        // database yet, fall back to bucketing every plan as Digital Invoice
        // instead of crashing with a 500 (the safety migration adds the column).
        if (Schema::hasColumn('pricing_plans', 'product_type')) {
            $diPlans = PricingPlan::where('product_type', 'di')->orderBy('price')->get();
            $posPlans = PricingPlan::where('product_type', 'pos')->orderBy('price')->get();
            $fbrposPlans = PricingPlan::where('product_type', 'fbrpos')->orderBy('price')->get();
        } else {
            $diPlans = PricingPlan::orderBy('price')->get();
            $posPlans = new Collection();
            $fbrposPlans = new Collection();
        }

        return view('saas-admin.plans', compact('diPlans', 'posPlans', 'fbrposPlans'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'price_quarterly' => 'nullable|numeric|min:0',
            'invoice_limit' => 'required|integer|min:-1',
            'product_type' => 'required|in:di,pos,fbrpos',
            'max_terminals' => 'nullable|integer|min:-1',
            'max_users' => 'nullable|integer|min:-1',
            'max_products' => 'nullable|integer|min:-1',
            'inventory_enabled' => 'boolean',
            'reports_enabled' => 'boolean',
            'features_text' => 'nullable|string',
        ]);

        $features = array_filter(array_map('trim', explode("\n", $request->input('features_text', ''))));

        // Explicit field list (never mass-assign the whole request) so sensitive
        // columns like is_trial can't be injected via crafted POST data.
        $plan = PricingPlan::create([
            'name' => $data['name'],
            'product_type' => $data['product_type'],
            'price' => $data['price'],
            'price_monthly' => in_array($data['product_type'], ['di', 'fbrpos']) ? $data['price'] : null,
            'price_quarterly' => $data['price_quarterly'] ?? null,
            'invoice_limit' => $data['invoice_limit'],
            'max_terminals' => $request->input('max_terminals'),
            'max_users' => $request->input('max_users'),
            'max_products' => $request->input('max_products'),
            'inventory_enabled' => $request->boolean('inventory_enabled'),
            'reports_enabled' => $request->boolean('reports_enabled'),
            'features' => $features,
        ]);

        AdminAuditLog::log(auth('admin')->id(), 'Plan created', 'PricingPlan', $plan->id, ['name' => $plan->name, 'product_type' => $plan->product_type]);
        return back()->with('success', "Plan '{$plan->name}' created.");
    }

    public function update(Request $request, $id)
    {
        $plan = PricingPlan::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'price_quarterly' => 'nullable|numeric|min:0',
            'invoice_limit' => 'required|integer|min:-1',
            'product_type' => 'required|in:di,pos,fbrpos',
            'max_terminals' => 'nullable|integer|min:-1',
            'max_users' => 'nullable|integer|min:-1',
            'max_products' => 'nullable|integer|min:-1',
            'features_text' => 'nullable|string',
        ]);

        $features = array_filter(array_map('trim', explode("\n", $request->input('features_text', ''))));

        // Explicit field list (never mass-assign the whole request) so sensitive
        // columns like is_trial can't be injected via crafted POST data.
        $plan->update([
            'name' => $data['name'],
            'product_type' => $data['product_type'],
            'price' => $data['price'],
            'price_monthly' => in_array($data['product_type'], ['di', 'fbrpos']) ? $data['price'] : null,
            'price_quarterly' => $data['price_quarterly'] ?? null,
            'invoice_limit' => $data['invoice_limit'],
            'max_terminals' => $request->input('max_terminals'),
            'max_users' => $request->input('max_users'),
            'max_products' => $request->input('max_products'),
            'inventory_enabled' => $request->boolean('inventory_enabled'),
            'reports_enabled' => $request->boolean('reports_enabled'),
            'features' => $features,
        ]);

        AdminAuditLog::log(auth('admin')->id(), 'Plan updated', 'PricingPlan', $plan->id, ['name' => $plan->name, 'product_type' => $plan->product_type]);
        return back()->with('success', "Plan '{$plan->name}' updated.");
    }
}
