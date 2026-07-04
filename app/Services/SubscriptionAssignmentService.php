<?php

namespace App\Services;

use App\Models\PricingPlan;
use App\Models\Subscription;

/**
 * Single source of truth for assigning a paid subscription to a company.
 *
 * Used by both the admin "Assign Subscription" form and the payment-proof
 * approval flow, so the two paths can never drift apart. Deactivates any
 * existing active subscription, then creates a fresh active one.
 */
class SubscriptionAssignmentService
{
    /**
     * @param  string  $billingCycle  'monthly' | 'yearly'
     */
    public static function assign(int $companyId, int $pricingPlanId, string $billingCycle = 'monthly'): Subscription
    {
        $plan = PricingPlan::findOrFail($pricingPlanId);

        Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->update(['active' => false]);

        $endDate = $billingCycle === 'yearly' ? now()->addYear() : now()->addMonth();

        return Subscription::create([
            'company_id' => $companyId,
            'pricing_plan_id' => $pricingPlanId,
            'start_date' => now()->toDateString(),
            'end_date' => $endDate->toDateString(),
            'active' => true,
            'billing_cycle' => $billingCycle,
            'final_price' => $plan->sale_price,
        ]);
    }
}
