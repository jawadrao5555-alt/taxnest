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
        // Create Company
        $company = Company::firstOrCreate([
            'name' => 'Test Company',
        ], [
            'ntn' => '1234567-8',
            'email' => 'test@company.com',
            'phone' => '03000000000',
            'address' => 'Test Address',
            'fbr_token' => 'dummy-token'
        ]);

        // Admin User
        User::updateOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('admin123'),
                'role' => 'super_admin',
                'company_id' => $company->id,
            ]
        );

        // Normal User
        User::updateOrCreate(
            ['email' => 'jawad@test.com'],
            [
                'name' => 'Jawad',
                'password' => Hash::make('jawad123'),
                'role' => 'employee',
                'company_id' => $company->id,
            ]
        );

        // ═══ FBR POS Test Company + User ═══
        // Mandatory because /fbr-pos/login enforces company.product_type='fbrpos' AND fbr_pos_enabled=true
        // Without this, NO test user can login on FBR POS panel.
        $fbrCompany = Company::firstOrCreate(
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
        // Force-update key flags in case row pre-existed without them
        $fbrCompany->update([
            'company_status' => 'active',
            'product_type' => 'fbrpos',
            'fbr_pos_enabled' => true,
            'fbr_pos_environment' => $fbrCompany->fbr_pos_environment ?: 'sandbox',
            'fbr_reporting_enabled' => true,
        ]);

        User::updateOrCreate(
            ['email' => 'fbrtest@taxnest.com'],
            [
                'name' => 'FBR POS Test',
                'password' => Hash::make('Admin@12345'),
                'role' => 'company_admin',
                'company_id' => $fbrCompany->id,
            ]
        );
    }
}
