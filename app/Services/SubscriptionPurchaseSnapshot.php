<?php

namespace App\Services;

use App\Models\{Company, PricingPlan};

/** Evidence of the catalogue at assignment, not an alternative access gate. */
final class SubscriptionPurchaseSnapshot
{
    public static function capture(Company $company, PricingPlan $plan, array $priced, string $start, string $end, bool $paidRenewal): array
    {
        $terms = [];
        $fields = ['id', 'name', 'product_type', 'erps_vertical', 'is_trial', 'features', 'health_modules',
            'price', 'price_monthly', 'price_quarterly', 'price_semi_annual', 'price_yearly', 'compare_at_price',
            'invoice_limit', 'user_limit', 'branch_limit', 'max_terminals', 'max_users', 'max_products',
            'ai_page_limit', 'fair_use_limit', 'health_department_limit'];
        foreach ($plan->getAttributes() as $key => $value) {
            if (str_ends_with($key, '_enabled')) {
                $terms[$key] = $value === null ? null : (bool) $value;
            } elseif (in_array($key, $fields, true)) {
                $terms[$key] = $plan->getAttribute($key);
            }
        }
        ksort($terms);

        return [
            'version' => 1,
            'captured_at' => now()->toIso8601String(),
            'source' => $paidRenewal ? 'paid_assignment' : 'assignment',
            'company_id' => (int) $company->id,
            'plan' => $terms,
            'price' => [
                'currency' => 'PKR', 'cycle' => $priced['cycle'],
                'final_price' => number_format((float) $priced['final_price'], 2, '.', ''),
                'base_price' => number_format((float) $priced['base_price'], 2, '.', ''),
                'extra_branch_price' => number_format((float) $priced['extra_branch_price'], 2, '.', ''),
                'extra_branch_slots' => (int) $priced['extra_branch_slots'],
                'discount_percent' => number_format((float) $priced['discount_percent'], 2, '.', ''),
            ],
            'period' => ['start' => $start, 'end' => $end],
        ];
    }
}
