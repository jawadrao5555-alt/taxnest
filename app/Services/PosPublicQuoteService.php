<?php

namespace App\Services;

use App\Models\PricingPlan;

/** Presentation-only upgrade comparison. It never grants access or changes a plan. */
class PosPublicQuoteService
{
    public static function preservesPackage(PricingPlan $current, PricingPlan $candidate): bool
    {
        $gates = array_unique(array_merge(PosFeatureService::PLAN_GATES, ['restaurant_enabled', 'inventory_enabled']));
        foreach ($gates as $gate) {
            if ($current->{$gate} && ! $candidate->{$gate}) {
                return false;
            }
        }
        foreach (['invoice_limit', 'user_limit', 'branch_limit', 'max_terminals', 'max_products'] as $column) {
            $before = $current->{$column};
            $after = $candidate->{$column};
            $beforeUnlimited = PosPlanComparisonService::isUnlimited($before);
            $afterUnlimited = PosPlanComparisonService::isUnlimited($after);
            if (! $afterUnlimited && ($beforeUnlimited || (int) $after < (int) $before)) {
                return false;
            }
        }

        return true;
    }
}
