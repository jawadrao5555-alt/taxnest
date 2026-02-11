<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PricingPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Trial',
                'invoice_limit' => 20,
                'user_limit' => 2,
                'branch_limit' => 1,
                'is_trial' => true,
                'price' => 0,
                'features' => json_encode(['14-day free trial', '20 invoices', '2 users', '1 branch', 'FBR Integration', 'PDF Generation']),
            ],
            [
                'name' => 'Retail',
                'invoice_limit' => 100,
                'user_limit' => 2,
                'branch_limit' => 1,
                'is_trial' => false,
                'price' => 999,
                'features' => json_encode(['100 invoices/month', '2 users', '1 branch', 'FBR Integration', 'PDF Generation', 'Compliance Scoring']),
            ],
            [
                'name' => 'Business',
                'invoice_limit' => 700,
                'user_limit' => 5,
                'branch_limit' => 3,
                'is_trial' => false,
                'price' => 2999,
                'features' => json_encode(['700 invoices/month', '5 users', '3 branches', 'FBR Integration', 'PDF Generation', 'Compliance Scoring', 'MIS Reports', 'Customer Ledger']),
            ],
            [
                'name' => 'Industrial',
                'invoice_limit' => 2500,
                'user_limit' => 15,
                'branch_limit' => -1,
                'is_trial' => false,
                'price' => 6999,
                'features' => json_encode(['2,500 invoices/month', '15 users', 'Unlimited branches', 'FBR Integration', 'PDF Generation', 'Compliance Scoring', 'MIS Reports', 'Customer Ledger', 'Priority Support']),
            ],
            [
                'name' => 'Enterprise',
                'invoice_limit' => -1,
                'user_limit' => -1,
                'branch_limit' => -1,
                'is_trial' => false,
                'price' => 15000,
                'features' => json_encode(['Unlimited invoices', 'Unlimited users', 'Unlimited branches', 'FBR Integration', 'PDF Generation', 'Compliance Scoring', 'MIS Reports', 'Customer Ledger', 'Priority Support', 'Dedicated Account Manager', 'Custom Integrations']),
            ],
        ];

        foreach ($plans as $plan) {
            \App\Models\PricingPlan::updateOrCreate(
                ['name' => $plan['name']],
                $plan
            );
        }
    }
}
