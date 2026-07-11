<?php

namespace App\Services;

use App\Models\PricingPlan;
use App\Models\Subscription;

/**
 * Single source of truth for assigning a paid subscription to a company.
 *
 * Used by the admin "Assign Subscription" form AND the payment-proof approval
 * flow, so the two paths can never drift apart. Deactivates any existing active
 * subscription, then creates a fresh active one with a product-type-aware price
 * and a cycle-correct expiry date.
 */
class SubscriptionAssignmentService
{
    /**
     * Canonical cycle set used across the app. Legacy admin forms sent 'yearly';
     * the rest of the codebase (BillingController, Subscription model) uses
     * 'annual'. Normalize so nothing downstream (getMonthsForCycle, getCycleLabel)
     * silently falls back to Monthly.
     */
    public static function normalizeCycle(?string $cycle): string
    {
        $cycle = $cycle ?: 'monthly';
        if ($cycle === 'yearly') {
            return 'annual';
        }
        return in_array($cycle, ['monthly', 'quarterly', 'semi_annual', 'annual'], true)
            ? $cycle
            : 'monthly';
    }

    /**
     * Product-type-aware price computation. Price semantics differ per product:
     *  - di:               sale_price is the MONTHLY base  → apply the cycle discount.
     *  - pos / standalone: sale_price is ALREADY the ANNUAL total (6% baked in) → use as-is (annual only).
     *  - fbrpos:           sale_price is MONTHLY           → annual = sale_price × 12 × 0.94 (6% off).
     *
     * @return array{cycle:string, final_price:float, discount_percent:float}
     */
    public static function computePrice(PricingPlan $plan, string $cycle): array
    {
        $type = $plan->product_type ?? 'di';
        $cycle = self::normalizeCycle($cycle);

        if ($type === 'pos' || $type === 'standalone') {
            return [
                'cycle' => 'annual',
                'final_price' => round((float) $plan->sale_price),
                'discount_percent' => 6.0,
            ];
        }

        if ($type === 'fbrpos') {
            return [
                'cycle' => 'annual',
                'final_price' => round((float) $plan->sale_price * 12 * 0.94),
                'discount_percent' => 6.0,
            ];
        }

        // di (and any unknown type) → monthly base with the cycle's discount applied.
        $pricing = Subscription::calculateFinalPrice((float) $plan->sale_price, $cycle);

        return [
            'cycle' => $cycle,
            'final_price' => $pricing['final_price'],
            'discount_percent' => $pricing['discount_percent'],
        ];
    }

    /**
     * @param  string  $billingCycle  monthly | quarterly | semi_annual | annual (legacy 'yearly' ok)
     */
    public static function assign(int $companyId, int $pricingPlanId, string $billingCycle = 'monthly'): Subscription
    {
        $plan = PricingPlan::findOrFail($pricingPlanId);

        // Product-type-aware pricing also normalizes/forces the correct cycle
        // (e.g. POS is annual-only), so the expiry below always matches the charge.
        $priced = self::computePrice($plan, $billingCycle);
        $cycle = $priced['cycle'];
        $months = Subscription::getMonthsForCycle($cycle);

        Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->update(['active' => false]);

        return Subscription::create([
            'company_id' => $companyId,
            'pricing_plan_id' => $pricingPlanId,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths($months)->toDateString(),
            'active' => true,
            'billing_cycle' => $cycle,
            'discount_percent' => $priced['discount_percent'],
            'final_price' => $priced['final_price'],
        ]);
    }
}
