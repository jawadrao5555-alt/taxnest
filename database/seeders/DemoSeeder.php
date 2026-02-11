<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Company;
use App\Models\Product;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceActivityLog;
use App\Models\FbrLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['ntn' => '9876543-2'],
            [
                'name' => 'Demo Traders Pvt Ltd',
                'email' => 'info@demotraders.pk',
                'phone' => '0321-9876543',
                'address' => 'I-8 Markaz, Islamabad',
                'fbr_token' => 'demo-fbr-token-xyz',
                'compliance_score' => 78,
            ]
        );

        User::updateOrCreate(
            ['email' => 'demo@taxnest.pk'],
            [
                'name' => 'Demo Company Admin',
                'password' => Hash::make('password123'),
                'role' => 'company_admin',
                'company_id' => $company->id,
            ]
        );

        $plan = PricingPlan::where('name', 'Professional')->first();
        if ($plan) {
            Subscription::updateOrCreate(
                ['company_id' => $company->id, 'active' => true],
                [
                    'pricing_plan_id' => $plan->id,
                    'start_date' => Carbon::now()->subDays(10),
                    'end_date' => Carbon::now()->addDays(350),
                ]
            );
        }

        Product::updateOrCreate(
            ['company_id' => $company->id, 'hs_code' => '15179090'],
            [
                'name' => 'Cooking Oil 1L',
                'pct_code' => '1517.9090',
                'default_tax_rate' => 18,
                'uom' => 'Litre',
                'schedule_type' => 'Standard',
                'default_price' => 450,
                'is_active' => true,
            ]
        );

        Product::updateOrCreate(
            ['company_id' => $company->id, 'hs_code' => '25232900'],
            [
                'name' => 'Cement Bag',
                'pct_code' => '2523.2900',
                'default_tax_rate' => 18,
                'uom' => 'Bag',
                'schedule_type' => 'Standard',
                'default_price' => 1250,
                'is_active' => true,
            ]
        );

        Product::updateOrCreate(
            ['company_id' => $company->id, 'hs_code' => '31021000'],
            [
                'name' => 'Fertilizer',
                'pct_code' => '3102.1000',
                'default_tax_rate' => 0,
                'uom' => 'Kg',
                'schedule_type' => 'Exempt',
                'sro_reference' => 'SRO 1125(I)/2011',
                'default_price' => 3800,
                'is_active' => true,
            ]
        );

        $draftInvoice = Invoice::updateOrCreate(
            ['company_id' => $company->id, 'invoice_number' => 'DEMO-INV-001'],
            [
                'buyer_name' => 'Karachi Electronics Ltd',
                'buyer_ntn' => '5566778-9',
                'status' => 'draft',
                'total_amount' => 2124,
                'share_uuid' => Str::uuid(),
            ]
        );

        InvoiceItem::updateOrCreate(
            ['invoice_id' => $draftInvoice->id, 'hs_code' => '15179090'],
            [
                'description' => 'Cooking Oil 1L',
                'quantity' => 4,
                'price' => 450,
                'tax' => 324,
            ]
        );

        $lockedInvoice = Invoice::updateOrCreate(
            ['company_id' => $company->id, 'invoice_number' => 'DEMO-INV-002'],
            [
                'buyer_name' => 'Lahore Builders Pvt Ltd',
                'buyer_ntn' => '3344556-7',
                'status' => 'locked',
                'total_amount' => 29500,
                'submission_mode' => 'smart',
                'fbr_invoice_id' => 'MOCK-FBR-0001',
                'share_uuid' => Str::uuid(),
                'qr_data' => json_encode([
                    'ntn' => '9876543-2',
                    'invoice_number' => 'DEMO-INV-002',
                    'fbr_invoice_id' => 'MOCK-FBR-0001',
                    'date' => Carbon::now()->subDays(2)->format('Y-m-d'),
                    'total' => 29500,
                ]),
                'integrity_hash' => hash('sha256', 'DEMO-INV-002|29500|9876543-2|' . Carbon::now()->format('Y-m-d')),
            ]
        );

        InvoiceItem::updateOrCreate(
            ['invoice_id' => $lockedInvoice->id, 'hs_code' => '25232900'],
            [
                'description' => 'Cement Bag',
                'quantity' => 20,
                'price' => 1250,
                'tax' => 4500,
            ]
        );

        InvoiceActivityLog::updateOrCreate(
            ['invoice_id' => $draftInvoice->id, 'action' => 'created'],
            [
                'company_id' => $company->id,
                'user_id' => User::where('email', 'demo@taxnest.pk')->first()->id,
                'changes_json' => ['buyer_name' => 'Karachi Electronics Ltd'],
                'ip_address' => '127.0.0.1',
            ]
        );

        InvoiceActivityLog::updateOrCreate(
            ['invoice_id' => $lockedInvoice->id, 'action' => 'locked'],
            [
                'company_id' => $company->id,
                'user_id' => null,
                'changes_json' => ['fbr_invoice_number' => 'MOCK-FBR-0001'],
                'ip_address' => '127.0.0.1',
            ]
        );

        FbrLog::updateOrCreate(
            ['invoice_id' => $lockedInvoice->id, 'status' => 'success'],
            [
                'request_payload' => json_encode(['demo' => true, 'mock' => true]),
                'response_payload' => json_encode(['status' => 'success', 'fbr_invoice_number' => 'MOCK-FBR-0001', 'mock' => true]),
                'response_time_ms' => 850,
                'retry_count' => 0,
            ]
        );

        SystemSetting::set('demo_mode', 'true', 'Enable demo safety mode - disables real PRAL API calls');
    }
}
