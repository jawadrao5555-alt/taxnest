<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PosCompanySeeder extends Seeder
{
    public function run(): void
    {
        $existing = DB::table('companies')->where('name', 'NestPOS Enterprise Store')->first();
        if ($existing) {
            return;
        }

        DB::beginTransaction();
        try {
            $companyId = DB::table('companies')->insertGetId([
                'name' => 'NestPOS Enterprise Store',
                'ntn' => '0000000000000',
                'email' => 'pos@nestpos.pk',
                'phone' => '03001234567',
                'address' => 'Main Boulevard, Lahore, Pakistan',
                'fbr_environment' => 'sandbox',
                'company_status' => 'active',
                'status' => 'approved',
                'is_internal_account' => false,
                'onboarding_completed' => true,
                'standard_tax_rate' => 16,
                'sector_type' => 'Retail',
                'province' => 'Punjab',
                'city' => 'Lahore',
                'owner_name' => 'POS Admin',
                'pra_reporting_enabled' => true,
                'pra_environment' => 'sandbox',
                'receipt_printer_size' => '80mm',
                'inventory_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $existingUser = DB::table('users')->where('email', 'posadmin@taxnest.com')->first();
            if ($existingUser) {
                DB::table('users')->where('id', $existingUser->id)->update([
                    'company_id' => $companyId,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('users')->insert([
                    'name' => 'POS Admin',
                    'email' => 'posadmin@taxnest.com',
                    'password' => Hash::make('Admin@12345'),
                    'company_id' => $companyId,
                    'role' => 'company_admin',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $products = [
                ['name' => 'Chai', 'price' => 60, 'description' => 'Doodh patti special', 'category' => 'Beverages', 'sku' => 'CHI-001', 'tax_rate' => 0, 'uom' => 'NOS'],
                ['name' => 'Samosa', 'price' => 35, 'description' => 'Crispy aloo samosa', 'category' => 'Snacks', 'sku' => 'SAM-001', 'tax_rate' => 16, 'uom' => 'NOS'],
                ['name' => 'Paratha', 'price' => 80, 'description' => 'Aloo paratha with butter', 'category' => 'Food', 'sku' => 'PAR-001', 'tax_rate' => 5, 'uom' => 'NOS'],
                ['name' => 'Lassi', 'price' => 100, 'description' => 'Mango lassi glass', 'category' => 'Beverages', 'sku' => 'LAS-001', 'tax_rate' => 0, 'uom' => 'NOS'],
                ['name' => 'Chicken Biryani', 'price' => 450, 'description' => 'Full plate chicken biryani', 'category' => 'Food', 'sku' => 'BIR-001', 'tax_rate' => 16, 'uom' => 'NOS'],
                ['name' => 'Cold Drink 500ml', 'price' => 120, 'description' => 'Pepsi/Coke 500ml', 'category' => 'Beverages', 'sku' => 'CD-001', 'tax_rate' => 16, 'uom' => 'NOS'],
                ['name' => 'Naan', 'price' => 25, 'description' => 'Tandoori naan', 'category' => 'Food', 'sku' => 'NAN-001', 'tax_rate' => 0, 'uom' => 'NOS'],
                ['name' => 'Mineral Water 1.5L', 'price' => 80, 'description' => 'Nestle Pure Life', 'category' => 'Beverages', 'sku' => 'MW-001', 'tax_rate' => 0, 'uom' => 'NOS'],
            ];

            foreach ($products as $p) {
                DB::table('pos_products')->insert(array_merge($p, [
                    'company_id' => $companyId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            $customers = [
                ['name' => 'Walk-in Customer', 'phone' => null, 'email' => null, 'address' => null],
                ['name' => 'Ahmed Ali', 'phone' => '03001234567', 'email' => 'ahmed@example.com', 'address' => 'Main Bazar, Lahore'],
                ['name' => 'Fatima Bibi', 'phone' => '03219876543', 'email' => null, 'address' => 'Model Town, Lahore'],
            ];

            foreach ($customers as $c) {
                DB::table('pos_customers')->insert(array_merge($c, [
                    'company_id' => $companyId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            DB::table('pos_terminals')->insert([
                'company_id' => $companyId,
                'terminal_name' => 'Main Counter',
                'terminal_code' => 'T001',
                'location' => 'Front Entrance',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
