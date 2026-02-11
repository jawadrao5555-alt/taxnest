<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PricingPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\PricingPlan::create([
            'name' => 'Basic',
            'invoice_limit' => 10,
            'price' => 1000.00,
        ]);

        \App\Models\PricingPlan::create([
            'name' => 'Professional',
            'invoice_limit' => 100,
            'price' => 5000.00,
        ]);

        \App\Models\PricingPlan::create([
            'name' => 'Enterprise',
            'invoice_limit' => 1000,
            'price' => 20000.00,
        ]);
    }
}
