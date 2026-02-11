<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure Pricing Plans exist
        $this->call(PricingPlanSeeder::class);
        $professionalPlan = PricingPlan::where('name', 'Professional')->first();

        // 2. Create Super Admin
        User::updateOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin123'),
                'role' => 'super_admin',
            ]
        );

        // 3. Create Company
        $company = Company::updateOrCreate(
            ['ntn' => '1234567-8'],
            [
                'name' => 'TaxNest Solutions Ltd',
                'email' => 'contact@taxnest.com',
                'phone' => '0300-1234567',
                'address' => 'I-10 Industrial Area, Islamabad',
                'fbr_token' => 'dummy-fbr-token-123',
            ]
        );

        // 4. Create Company Admin
        User::updateOrCreate(
            ['email' => 'company_admin@test.com'],
            [
                'name' => 'Company Admin',
                'password' => Hash::make('admin123'),
                'role' => 'company_admin',
                'company_id' => $company->id,
            ]
        );

        // 5. Create Employee
        User::updateOrCreate(
            ['email' => 'jawad@test.com'],
            [
                'name' => 'Jawad Employee',
                'password' => Hash::make('jawad123'),
                'role' => 'employee',
                'company_id' => $company->id,
            ]
        );

        // 6. Create Active Subscription
        Subscription::updateOrCreate(
            ['company_id' => $company->id, 'active' => true],
            [
                'pricing_plan_id' => $professionalPlan->id,
                'start_date' => Carbon::now()->subDays(5),
                'end_date' => Carbon::now()->addDays(25),
            ]
        );

        // 7. Create 3 Sample Invoices with Items
        $invoicesData = [
            [
                'buyer_name' => 'ABC Traders',
                'buyer_ntn' => '7654321-0',
                'status' => 'submitted',
                'total_amount' => 15000,
            ],
            [
                'buyer_name' => 'XYZ Services',
                'buyer_ntn' => '1122334-4',
                'status' => 'draft',
                'total_amount' => 8500,
            ],
            [
                'buyer_name' => 'Global Logistics',
                'buyer_ntn' => '9988776-6',
                'status' => 'locked',
                'total_amount' => 45000,
            ],
        ];

        foreach ($invoicesData as $index => $data) {
            $invoice = Invoice::create([
                'company_id' => $company->id,
                'invoice_number' => 'INV-' . Carbon::now()->format('Ymd') . '-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT),
                'buyer_name' => $data['buyer_name'],
                'buyer_ntn' => $data['buyer_ntn'],
                'status' => $data['status'],
                'total_amount' => $data['total_amount'],
            ]);

            // Create 2 items per invoice
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'hs_code' => '8471.3010',
                'description' => 'Professional Tax Consultation',
                'quantity' => 1,
                'price' => $data['total_amount'] * 0.8,
                'tax' => $data['total_amount'] * 0.2,
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'hs_code' => '9988.7766',
                'description' => 'Administrative Service Fee',
                'quantity' => 1,
                'price' => 2000,
                'tax' => 340,
            ]);
        }
    }
}
