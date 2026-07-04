<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PricingPlanSeeder extends Seeder
{
    public function run(): void
    {
        // Digital Invoice (di) plans. Prices reflect the 50% Launch Offer:
        // `price` is the discounted price, `compare_at_price` the original "was" price.
        $plans = [
            [
                'name' => 'Trial',
                'product_type' => 'di',
                'invoice_limit' => 10,
                'user_limit' => 2,
                'branch_limit' => 1,
                'is_trial' => true,
                'price' => 0,
                'compare_at_price' => null,
                'features' => json_encode(['3-day free trial', '10 invoices', '2 users', '1 branch', 'FBR Integration', 'PDF Generation']),
            ],
            [
                'name' => 'Retail',
                'product_type' => 'di',
                'invoice_limit' => 100,
                'user_limit' => 2,
                'branch_limit' => 1,
                'is_trial' => false,
                'price' => 499,
                'compare_at_price' => 999,
                'features' => json_encode(['100 invoices/month', '2 users', '1 branch', 'FBR Integration', 'PDF Generation', 'Compliance Scoring']),
            ],
            [
                'name' => 'Business',
                'product_type' => 'di',
                'invoice_limit' => 700,
                'user_limit' => 5,
                'branch_limit' => 3,
                'is_trial' => false,
                'price' => 1499,
                'compare_at_price' => 2999,
                'features' => json_encode(['700 invoices/month', '5 users', '3 branches', 'FBR Integration', 'PDF Generation', 'Compliance Scoring', 'MIS Reports', 'Customer Ledger']),
            ],
            [
                'name' => 'Industrial',
                'product_type' => 'di',
                'invoice_limit' => 2500,
                'user_limit' => 15,
                'branch_limit' => -1,
                'is_trial' => false,
                'price' => 3499,
                'compare_at_price' => 6999,
                'features' => json_encode(['2,500 invoices/month', '15 users', 'Unlimited branches', 'FBR Integration', 'PDF Generation', 'Compliance Scoring', 'MIS Reports', 'Customer Ledger', 'Priority Support']),
            ],
            [
                'name' => 'Enterprise',
                'product_type' => 'di',
                'invoice_limit' => -1,
                'user_limit' => -1,
                'branch_limit' => -1,
                'is_trial' => false,
                'price' => 7500,
                'compare_at_price' => 15000,
                'features' => json_encode(['Unlimited invoices', 'Unlimited users', 'Unlimited branches', 'FBR Integration', 'PDF Generation', 'Compliance Scoring', 'MIS Reports', 'Customer Ledger', 'Priority Support', 'Dedicated Account Manager', 'Custom Integrations']),
            ],
        ];

        foreach ($plans as $plan) {
            \App\Models\PricingPlan::updateOrCreate(
                ['name' => $plan['name'], 'product_type' => $plan['product_type']],
                $plan
            );
        }
    }
}
