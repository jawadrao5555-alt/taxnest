<?php

namespace App\Services;

use App\Models\PricingPlan;
use App\Models\Subscription;

/**
 * Guarantees that a freshly-created company ALWAYS ends up with a usable
 * subscription row (trial plan when one exists, otherwise a plan-less row
 * carrying trial_ends_at so SubscriptionAccessService still has a bounded
 * trial window instead of nothing at all).
 *
 * Why: the payment-proof free-access hole existed only because a company
 * could end up with NO subscription row. hasAccess() now fails closed on
 * bare plan-less rows, but the healthier guarantee is that no signup path
 * (DI, PRA POS, FBR POS, admin-created) can leave a company without a
 * trial/paid subscription row.
 */
class TrialSubscriptionService
{
    /**
     * Attach the standard trial subscription to a company. Idempotent: if the
     * company already has an active subscription, nothing new is created.
     */
    public static function ensureTrial(int $companyId, string $productType, int $days = 3): Subscription
    {
        $existing = Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->orderByDesc('id')
            ->first();
        if ($existing) {
            return $existing;
        }

        // Prefer the product's own trial plan; fall back to any trial plan so a
        // missing seed row never leaves the company subscription-less.
        $trialPlan = PricingPlan::where('product_type', $productType)
            ->where('is_trial', true)
            ->first()
            ?? PricingPlan::where('is_trial', true)->first();

        return Subscription::create([
            'company_id' => $companyId,
            'pricing_plan_id' => $trialPlan?->id,
            'billing_cycle' => 'monthly',
            'discount_percent' => 0,
            'final_price' => 0,
            'start_date' => now(),
            'end_date' => now()->addDays($days),
            'trial_ends_at' => now()->addDays($days),
            'active' => true,
        ]);
    }
}
