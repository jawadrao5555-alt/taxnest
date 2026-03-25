<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceActivityLog;
use App\Models\FbrLog;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PricingPlanSeeder::class);
        $professionalPlan = PricingPlan::where('name', 'Business')->first();

        User::updateOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin123'),
                'role' => 'super_admin',
            ]
        );

        $company = Company::updateOrCreate(
            ['ntn' => '1234567-8'],
            [
                'name' => 'TaxNest Solutions Ltd',
                'email' => 'contact@taxnest.com',
                'phone' => '0300-1234567',
                'address' => 'I-10 Industrial Area, Islamabad',
                'fbr_token' => 'dummy-fbr-token-123',
                'compliance_score' => 85,
            ]
        );

        $companyAdmin = User::updateOrCreate(
            ['email' => 'company_admin@test.com'],
            [
                'name' => 'Company Admin',
                'password' => Hash::make('admin123'),
                'role' => 'company_admin',
                'company_id' => $company->id,
            ]
        );

        $employee = User::updateOrCreate(
            ['email' => 'jawad@test.com'],
            [
                'name' => 'Jawad Employee',
                'password' => Hash::make('jawad123'),
                'role' => 'employee',
                'company_id' => $company->id,
            ]
        );

        Subscription::updateOrCreate(
            ['company_id' => $company->id, 'active' => true],
            [
                'pricing_plan_id' => $professionalPlan->id,
                'start_date' => Carbon::now()->subDays(5),
                'end_date' => Carbon::now()->addDays(25),
            ]
        );

        $invoicesData = [
            [
                'buyer_name' => 'ABC Traders',
                'buyer_ntn' => '7654321-0',
                'status' => 'draft',
                'total_amount' => 15000,
                'days_ago' => 1,
            ],
            [
                'buyer_name' => 'XYZ Services',
                'buyer_ntn' => '1122334-4',
                'status' => 'draft',
                'total_amount' => 8500,
                'days_ago' => 3,
            ],
            [
                'buyer_name' => 'Global Logistics',
                'buyer_ntn' => '9988776-6',
                'status' => 'locked',
                'total_amount' => 45000,
                'days_ago' => 10,
            ],
        ];

        foreach ($invoicesData as $index => $data) {
            $createdAt = Carbon::now()->subDays($data['days_ago']);
            $invoice = Invoice::create([
                'company_id' => $company->id,
                'invoice_number' => 'INV-' . Carbon::now()->format('Ymd') . '-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT),
                'buyer_name' => $data['buyer_name'],
                'buyer_ntn' => $data['buyer_ntn'],
                'status' => $data['status'],
                'total_amount' => $data['total_amount'],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            if ($data['status'] === 'locked') {
                $invoice->load('items');
                $taxAmount = $data['total_amount'] * 0.2;
                $hashData = implode('|', [
                    $invoice->invoice_number,
                    $invoice->total_amount,
                    $taxAmount,
                    $invoice->company_id,
                    $invoice->created_at->toIso8601String(),
                ]);
                $invoice->integrity_hash = hash('sha256', $hashData);
                $invoice->save();
            }

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

            InvoiceActivityLog::create([
                'invoice_id' => $invoice->id,
                'company_id' => $company->id,
                'user_id' => $companyAdmin->id,
                'action' => 'created',
                'changes_json' => ['buyer_name' => $data['buyer_name'], 'total_amount' => $data['total_amount']],
                'ip_address' => '127.0.0.1',
                'created_at' => $createdAt,
            ]);

            if ($data['status'] === 'locked') {
                InvoiceActivityLog::create([
                    'invoice_id' => $invoice->id,
                    'company_id' => $company->id,
                    'user_id' => $companyAdmin->id,
                    'action' => 'locked',
                    'ip_address' => '127.0.0.1',
                    'created_at' => $createdAt->copy()->addHours(2),
                ]);
            }

            if ($data['status'] === 'locked') {
                InvoiceActivityLog::create([
                    'invoice_id' => $invoice->id,
                    'company_id' => $company->id,
                    'user_id' => null,
                    'action' => 'locked',
                    'changes_json' => ['fbr_invoice_number' => 'FBR-' . time()],
                    'ip_address' => '127.0.0.1',
                    'created_at' => $createdAt->copy()->addHours(3),
                ]);

                FbrLog::create([
                    'invoice_id' => $invoice->id,
                    'request_payload' => json_encode(['test' => true]),
                    'response_payload' => json_encode(['status' => 'success']),
                    'status' => 'success',
                    'response_time_ms' => 1250,
                    'retry_count' => 0,
                ]);
            }
        }

        $this->call(DemoSeeder::class);
    }
}
