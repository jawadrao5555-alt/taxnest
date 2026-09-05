<?php

namespace App\Services;

use App\Models\PricingPlan;
use App\Support\NestErps;
use App\Support\ProductCatalog;

/**
 * "Is this package still on the shelf?" — one product-aware answer.
 *
 * Every product line retires packages the same way: the row STAYS in
 * pricing_plans (old subscriptions, proofs and receipts must keep pointing at
 * what was really bought) and a per-product allowlist takes it off sale. Each
 * line owns its own list:
 *
 *   pos     → PosPlanComparisonService     (Pro / Pro Max retired, 23 Aug 2026)
 *   fbrpos  → FbrPosPlanComparisonService  (Pro retired, 23 Aug 2026)
 *   di      → DiPlanComparisonService      (Sep 2026 restructure)
 *
 * Before this class every buying path had to remember to call the RIGHT one,
 * and most of them only ever learned about 'pos'. A stale ?plan=Pro link, an
 * admin assignment, an approval of a months-old pending signup or a payment
 * proof could each quietly resurrect a retired package on a live shop. Every
 * such path now asks THIS class instead, so a newly retired package is off
 * every path the day its allowlist changes.
 *
 * TRIALS are not "sold" and are never retired here — a trial row is assigned by
 * the system, and each product's own trial rules govern it.
 */
class PlanSellabilityService
{
    /**
     * A package that may no longer be sold, assigned, requested or approved.
     * Unknown product lines (and trials) are never treated as retired — only a
     * line that actually keeps an allowlist can retire anything.
     */
    public static function isRetired(?PricingPlan $plan): bool
    {
        if (!$plan || $plan->is_trial) {
            return false;
        }

        $type = $plan->product_type ?? null;

        // Nest ERPS keeps NO retirement allowlist yet: the line is sold on
        // enquiry, so every package the admin prices is on the shelf. This arm
        // is spelled out rather than left to `default` so the umbrella can
        // never reach a Digital Invoice answer by accident, and so the day the
        // owner retires a Nest ERPS package there is one obvious place to
        // plug its comparison service in.
        if (NestErps::isProductType($type)) {
            return false;
        }

        return match ($type) {
            'pos'    => !PosPlanComparisonService::isSellablePlan($plan),
            'fbrpos' => !FbrPosPlanComparisonService::isSellablePlan($plan),
            'di'     => !DiPlanComparisonService::isSellablePlan($plan),
            default  => false,
        };
    }

    /** The inverse, for read paths that build a list of what may be offered. */
    public static function isSellable(?PricingPlan $plan): bool
    {
        return $plan !== null && !self::isRetired($plan);
    }

    /** Admin-facing refusal, naming the product line so the reason is obvious. */
    public static function retiredMessage(?PricingPlan $plan): string
    {
        return 'That retired ' . self::productLabel($plan) . ' package can no longer be assigned.';
    }

    /** Customer-facing refusal on a package the shop picked itself. */
    public static function pickCurrentMessage(?PricingPlan $plan): string
    {
        return 'Please select a current ' . self::productLabel($plan) . ' package.';
    }

    public static function productLabel(?PricingPlan $plan): string
    {
        $type = $plan->product_type ?? null;

        // Nest ERPS names its vertical underneath the product ("Nest ERPS — Healthcare").
        if (NestErps::isProductType($type)) {
            return NestErps::label(NestErps::verticalOf($plan));
        }

        return ProductCatalog::label($type);
    }
}
