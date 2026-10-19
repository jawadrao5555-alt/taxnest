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
        foreach (['invoice_limit', 'user_limit', 'branch_limit', 'max_terminals', 'max_products', 'max_users'] as $column) {
            $before = (int) ($current->{$column} ?? 0);
            $after = (int) ($candidate->{$column} ?? 0);
            if ($after !== -1 && ($before === -1 || $after < $before)) {
                return false;
            }
        }

        return true;
    }
}
