<?php

namespace App\Http\Controllers;

use App\Models\PricingPlan;
use App\Models\Subscription;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function plans()
    {
        $plans = PricingPlan::all();
        return view('billing.plans', compact('plans'));
    }

    public function subscribe(Request $request)
    {
        $plan = PricingPlan::findOrFail($request->plan_id);

        Subscription::updateOrCreate(
            ['company_id' => app('currentCompanyId')],
            [
                'pricing_plan_id' => $plan->id,
                'start_date' => now(),
                'end_date' => now()->addMonth(),
                'active' => true
            ]
        );

        return redirect('/dashboard');
    }
}
