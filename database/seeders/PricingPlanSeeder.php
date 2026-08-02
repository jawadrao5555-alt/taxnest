<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PricingPlanSeeder extends Seeder
{
    public function run(): void
    {
        // Digital Invoice (di) plans. Prices reflect the 50% Launch Offer:
        // `price` is the discounted price, `compare_at_price` the original "was" price.
        // NOTE: `features` must be a plain PHP array — PricingPlan casts it to
        // JSON. Passing json_encode()'d strings double-encodes and breaks the
        // landing/billing feature lists.
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
                'features' => ['3-day free trial', '10 invoices', '2 users', '1 branch', 'FBR Integration', 'PDF Generation'],
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
                'features' => ['100 invoices/month', '2 users', '1 branch', 'FBR Integration', 'PDF Generation', 'Compliance Scoring'],
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
                'features' => ['700 invoices/month', '5 users', '3 branches', 'FBR Integration', 'PDF Generation', 'Compliance Scoring', 'MIS Reports', 'Customer Ledger'],
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
                'features' => ['2,500 invoices/month', '15 users', 'Unlimited branches', 'FBR Integration', 'PDF Generation', 'Compliance Scoring', 'MIS Reports', 'Customer Ledger', 'Priority Support'],
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
                'features' => ['Unlimited invoices', 'Unlimited users', 'Unlimited branches', 'FBR Integration', 'PDF Generation', 'Compliance Scoring', 'MIS Reports', 'Customer Ledger', 'Priority Support', 'Dedicated Account Manager', 'Custom Integrations'],
            ],
            // Premium (Aug 2026): top tier bundling the premium feature set —
            // white-label, public API, AI reader, recurring invoices (see
            // DiFeatureService::PLAN_FEATURES). Monthly price (DI convention),
            // no launch offer. Prod gets this row via the idempotent
            // 2026_08_03_020000_add_di_premium_plan migration, not this seeder.
            [
                'name' => 'Premium',
                'product_type' => 'di',
                'invoice_limit' => -1,
                'user_limit' => -1,
                'branch_limit' => -1,
                'is_trial' => false,
                'price' => 12999,
                'compare_at_price' => null,
                'features' => ['Everything in Enterprise — unlimited invoices, users & branches', 'White-label branding on invoice PDFs & share pages', 'Public REST API & webhooks for ERP integration', 'AI Invoice Reader — draft invoices from PDF, Excel or photo', 'Recurring invoices, payment reminders & customer statements', 'Priority support & dedicated account manager'],
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
