<?php

namespace App\Http\Controllers;

use App\Models\PricingPlan;
use App\Models\Subscription;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function plans()
    {
        $plans = PricingPlan::orderBy('price')->get();
        $currentSubscription = null;

        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
        if ($companyId) {
            $currentSubscription = Subscription::where('company_id', $companyId)
                ->where('active', true)
                ->with('pricingPlan')
                ->first();
        }

        return view('billing.plans', compact('plans', 'currentSubscription'));
    }

    public function subscribe(Request $request)
    {
        $plan = PricingPlan::findOrFail($request->plan_id);
        $companyId = app('currentCompanyId');

        Subscription::where('company_id', $companyId)->update(['active' => false]);

        Subscription::create([
            'company_id' => $companyId,
            'pricing_plan_id' => $plan->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'active' => true,
        ]);

        return redirect('/dashboard')->with('success', 'Subscribed to ' . $plan->name . ' plan successfully!');
    }
}
