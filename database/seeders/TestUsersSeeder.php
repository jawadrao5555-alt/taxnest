<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Company;
use Illuminate\Support\Facades\Hash;

class TestUsersSeeder extends Seeder
{
    public function run(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // LEGACY Test Company / users — kept for dev fixtures only.
        // Lookup by NTN (the actual unique constraint) so re-seeds on prod
        // do NOT collide with existing rows that may have a different name.
        // firstOrCreate (not updateOrCreate) so we never overwrite real prod data.
        // ─────────────────────────────────────────────────────────────────
        $company = Company::firstOrCreate(
            ['ntn' => '1234567-8'],
            [
                'name' => 'Test Company',
                'email' => 'test@company.com',
                'phone' => '03000000000',
                'address' => 'Test Address',
                'fbr_token' => 'dummy-token',
            ]
        );

        User::firstOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('admin123'),
                'role' => 'super_admin',
                'company_id' => $company->id,
            ]
        );

        User::firstOrCreate(
            ['email' => 'jawad@test.com'],
            [
                'name' => 'Jawad',
                'password' => Hash::make('jawad123'),
                'role' => 'employee',
                'company_id' => $company->id,
            ]
        );

        // ─────────────────────────────────────────────────────────────────
        // FBR POS Test Company + User
        // Mandatory because /fbr-pos/login enforces:
        //   user.company.product_type === 'fbrpos' AND fbr_pos_enabled === true
        // updateOrCreate so the critical flags are GUARANTEED even if a row
        // with NTN 7777777-7 pre-existed without them.
        // ─────────────────────────────────────────────────────────────────
        $fbrCompany = Company::updateOrCreate(
            ['ntn' => '7777777-7'],
            [
                'name' => 'FBR POS Test Company',
                'email' => 'fbrtest@taxnest.com',
                'phone' => '03007777777',
                'address' => 'FBR Test Address, Lahore',
                'company_status' => 'active',
                'product_type' => 'fbrpos',
                'pos_type' => 'general',
                'restaurant_mode' => false,
                'fbr_pos_enabled' => true,
                'fbr_pos_environment' => 'sandbox',
                'fbr_reporting_enabled' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'fbrtest@taxnest.com'],
            [
                'name' => 'FBR POS Test',
                'password' => Hash::make('Admin@12345'),
                'role' => 'company_admin',
                'company_id' => $fbrCompany->id,
            ]
        );

        // ─────────────────────────────────────────────────────────────────
        // Grant LIFETIME override to FBR POS test company so QA can use
        // every billable feature without going through plan checkout.
        // SubscriptionAccessService treats override_type='lifetime' as
        // unconditionally allowed (regardless of pricing_plan_id / end_date).
        // Requires migration 2026_04_25_180000 (nullable columns).
        // ─────────────────────────────────────────────────────────────────
        if (\Illuminate\Support\Facades\Schema::hasTable('subscriptions')) {
            \App\Models\Subscription::updateOrCreate(
                ['company_id' => $fbrCompany->id, 'active' => true],
                [
                    'pricing_plan_id' => null,
                    'billing_cycle' => 'monthly',
                    'discount_percent' => 0,
                    'final_price' => 0,
                    'start_date' => now()->toDateString(),
                    'end_date' => null,
                    'override_type' => 'lifetime',
                    'override_until' => null,
                    'free_invoice_limit' => null,
                    'override_reason' => 'FBR POS Test User — lifetime free for QA',
                    'override_by' => null,
                ]
            );
        }
    }
}
