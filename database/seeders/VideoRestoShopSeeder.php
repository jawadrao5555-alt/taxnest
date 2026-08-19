<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant demo shop for tutorial-video recordings (companion to
 * VideoDemoShopSeeder's Al-Noor General Store). PURELY FICTIONAL — dev only.
 * The original resto demo shop was created ad-hoc and lost in a DB reset;
 * this committed seeder recreates it idempotently with the exact names the
 * recorded scenarios expect (T-3/T-5 tables, Beef Pulao, Imran Ali, etc).
 *
 * Logins (dev only, password from VIDEO_DEMO_PASS or DEV_POS_PASS env):
 *   videoresto@nestpos.pk   — pos_admin
 *   videowaiter@nestpos.pk  — pos_waiter
 *   videokitchen@nestpos.pk — pos_kitchen
 *   videorider@nestpos.pk   — pos_rider (linked to rider "Imran Ali")
 */
class VideoRestoShopSeeder extends Seeder
{
    public const COMPANY_NAME = 'Lahore Darbar Restaurant';
    public const LOGIN_EMAIL = 'videoresto@nestpos.pk';
    /**
     * Dev-only demo password comes from env — repo is public, never hardcode.
     * VIDEO_DEMO_PASS is optional because the normal development QA credential
     * already provides DEV_POS_PASS for browser POS checks.
     */
    public static function loginPassword(): string
    {
        $p = trim((string) env('VIDEO_DEMO_PASS', env('DEV_POS_PASS', '')));
        if ($p === '') {
            throw new \RuntimeException('Set VIDEO_DEMO_PASS or DEV_POS_PASS before seeding the video demo shops.');
        }

        return $p;
    }

    public function run(): void
    {
        DB::beginTransaction();
        try {
            $flags = [
                'inventory' => true, 'barcode' => true, 'customer_profile' => true,
                'kot' => true, 'tables' => true, 'kitchen' => true,
                'kitchen_notes' => true, 'recipes' => true, 'delivery' => true,
            ];
            $company = DB::table('companies')->where('name', self::COMPANY_NAME)->first();
            $companyData = [
                'owner_name' => 'Demo Owner',
                'email' => self::LOGIN_EMAIL,
                'phone' => '03000000001',
                'address' => 'Main Boulevard, Gulberg, Lahore',
                'city' => 'Lahore',
                'province' => 'Punjab',
                'sector_type' => 'Food',
                'product_type' => 'pos',
                'pos_type' => 'restaurant',
                'status' => 'approved',
                'company_status' => 'active',
                'is_internal_account' => true,
                'onboarding_completed' => true,
                'pos_setup_completed' => true,
                'use_universal_pos' => true,
                'restaurant_mode' => true,
                'standard_tax_rate' => 16,
                'pra_reporting_enabled' => false,
                'inventory_enabled' => true,
                'feature_flags' => json_encode($flags),
                'receipt_printer_size' => '80mm',
                'receipt_footer_note' => 'Shukriya! Phir tashreef laiye.',
                'updated_at' => now(),
            ];
            foreach (['pos_use_legacy_restaurant' => false, 'dine_in_auto_kot' => true] as $col => $val) {
                if (Schema::hasColumn('companies', $col)) {
                    $companyData[$col] = $val;
                }
            }
            if ($company) {
                $companyId = $company->id;
                DB::table('companies')->where('id', $companyId)->update($companyData);
            } else {
                $companyId = DB::table('companies')->insertGetId($companyData + [
                    'name' => self::COMPANY_NAME,
                    'ntn' => '9999999999996',
                    'created_at' => now(),
                ]);
            }

            // ── Users ──
            $users = [
                ['Demo Owner (Resto)', self::LOGIN_EMAIL, 'company_admin', 'pos_admin'],
                ['Rashid (Waiter)', 'videowaiter@nestpos.pk', 'employee', 'pos_waiter'],
                ['Bashir (Kitchen)', 'videokitchen@nestpos.pk', 'employee', 'pos_kitchen'],
                ['Imran Ali (Rider)', 'videorider@nestpos.pk', 'employee', 'pos_rider'],
            ];
            $userIds = [];
            foreach ($users as [$name, $email, $role, $posRole]) {
                $data = [
                    'name' => $name, 'company_id' => $companyId, 'role' => $role,
                    'pos_role' => $posRole, 'is_active' => true,
                    'password' => Hash::make(self::loginPassword()), 'updated_at' => now(),
                ];
                $u = DB::table('users')->where('email', $email)->first();
                if ($u) {
                    DB::table('users')->where('id', $u->id)->update($data);
                    $userIds[$email] = $u->id;
                } else {
                    $userIds[$email] = DB::table('users')->insertGetId($data + ['email' => $email, 'created_at' => now()]);
                }
            }

            // ── Unlimited subscription (invoice store requires one) ──
            $planId = DB::table('pricing_plans')->where('product_type', 'pos')->where('name', 'Unlimited')->value('id');
            if ($planId && !DB::table('subscriptions')->where('company_id', $companyId)->where('active', true)->exists()) {
                DB::table('subscriptions')->insert([
                    'company_id' => $companyId, 'pricing_plan_id' => $planId,
                    'billing_cycle' => 'annual', 'final_price' => 0,
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addYears(5)->toDateString(),
                    'active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // ── Menu ──
            $products = [
                ['Beef Pulao',           'Rice',    450,  'RIC-001'],
                ['Chicken Biryani',      'Rice',    380,  'RIC-002'],
                ['Chicken Karahi (Full)', 'Karahi', 1450, 'KAR-001'],
                ['Chicken Karahi (Half)', 'Karahi',  780, 'KAR-002'],
                ['Chicken Tikka',        'BBQ',     320,  'BBQ-001'],
                ['Seekh Kabab',          'BBQ',     250,  'BBQ-002'],
                ['Malai Boti',           'BBQ',     420,  'BBQ-003'],
                ['Garlic Naan',          'Bread',    90,  'BRD-001'],
                ['Roghni Naan',          'Bread',    60,  'BRD-002'],
                ['Zinger Burger',        'Fast Food', 480, 'FF-001'],
                ['Fresh Lime',           'Drinks',  120,  'DRK-001'],
                ['Soft Drink 345ml',     'Drinks',  100,  'DRK-002'],
                ['Kheer',                'Dessert', 180,  'DST-001'],
            ];
            foreach ($products as [$name, $cat, $price, $sku]) {
                DB::table('pos_products')->updateOrInsert(
                    ['company_id' => $companyId, 'sku' => $sku],
                    [
                        'name' => $name, 'category' => $cat, 'price' => $price,
                        'tax_rate' => 16, 'uom' => 'NOS', 'is_active' => true,
                        'show_on_sale' => true, 'updated_at' => now(), 'created_at' => now(),
                    ]
                );
            }

            // ── Tables T-1..T-8 (on a Main Hall floor) ──
            DB::table('restaurant_floors')->updateOrInsert(
                ['company_id' => $companyId, 'name' => 'Main Hall'],
                ['is_active' => true, 'updated_at' => now(), 'created_at' => now()]
            );
            $floorId = DB::table('restaurant_floors')->where('company_id', $companyId)->where('name', 'Main Hall')->value('id');
            // Legacy cleanup: earlier versions stored 'T-1'..'T-8'; normalize in
            // place (preserves ids/FKs) so re-running never duplicates tables.
            foreach (range(1, 8) as $i) {
                DB::table('restaurant_tables')
                    ->where('company_id', $companyId)
                    ->where('table_number', 'T-' . $i)
                    ->update(['table_number' => (string) $i]);
            }
            foreach (range(1, 8) as $i) {
                DB::table('restaurant_tables')->updateOrInsert(
                    // Bare number — the app UI prepends "T-" itself (waiter/KDS/universal).
                    // 'T-'.$i here rendered as "T-T-3" on those screens.
                    ['company_id' => $companyId, 'table_number' => (string) $i],
                    [
                        'floor_id' => $floorId,
                        'seats' => $i <= 4 ? 4 : ($i <= 6 ? 6 : 8),
                        'status' => 'available', 'is_active' => true,
                        'sort_order' => $i, 'updated_at' => now(), 'created_at' => now(),
                    ]
                );
            }

            // ── Kitchen stations (KDS station filter demo) ──
            DB::table('pos_stations')->updateOrInsert(
                ['company_id' => $companyId, 'name' => 'BBQ Counter'],
                ['categories' => json_encode(['BBQ']), 'is_active' => true, 'sort' => 1,
                 'updated_at' => now(), 'created_at' => now()]
            );

            // ── Ingredients + recipes ──
            $ingredients = [
                ['Basmati Chawal', 'kg',   320, 40, 10],
                ['Chicken',        'kg',   650, 25, 8],
                ['Cooking Oil',    'litre', 560, 30, 10],
                ['Garam Masala',   'pack', 180, 15, 5],
                ['Naan Atta',      'kg',   130, 50, 15],
                ['Dahi',           'kg',   220, 12, 5],
            ];
            $ingIds = [];
            foreach ($ingredients as [$name, $unit, $cost, $stock, $min]) {
                DB::table('ingredients')->updateOrInsert(
                    ['company_id' => $companyId, 'name' => $name],
                    ['unit' => $unit, 'cost_per_unit' => $cost, 'current_stock' => $stock,
                     'min_stock_level' => $min, 'is_active' => true,
                     'updated_at' => now(), 'created_at' => now()]
                );
                $ingIds[$name] = DB::table('ingredients')->where('company_id', $companyId)->where('name', $name)->value('id');
            }
            $recipes = [
                ['RIC-001', 'Basmati Chawal', 0.25], ['RIC-001', 'Cooking Oil', 0.05],
                ['KAR-001', 'Chicken', 1.0], ['KAR-001', 'Cooking Oil', 0.15], ['KAR-001', 'Garam Masala', 0.2],
                ['BRD-001', 'Naan Atta', 0.12],
            ];
            foreach ($recipes as [$sku, $ing, $qty]) {
                $pid = DB::table('pos_products')->where('company_id', $companyId)->where('sku', $sku)->value('id');
                if ($pid && !empty($ingIds[$ing])) {
                    DB::table('product_recipes')->updateOrInsert(
                        ['company_id' => $companyId, 'product_id' => $pid, 'ingredient_id' => $ingIds[$ing]],
                        ['quantity_needed' => $qty, 'updated_at' => now(), 'created_at' => now()]
                    );
                }
            }

            // ── Customers ──
            foreach ([
                ['Walk-in Customer', null, null],
                ['Ahmed Sahab', '03001234567', 'House 45, Gulberg III, Lahore'],
                ['Chaudhry Sahab', '03219876543', 'DHA Phase 5, Lahore'],
            ] as [$name, $phone, $address]) {
                DB::table('pos_customers')->updateOrInsert(
                    ['company_id' => $companyId, 'name' => $name],
                    ['phone' => $phone, 'address' => $address, 'is_active' => true,
                     'updated_at' => now(), 'created_at' => now()]
                );
            }

            // ── Riders (Imran on duty with live location + trail for tracking video) ──
            $riderRows = [
                ['Imran Ali', '03111222333', $userIds['videorider@nestpos.pk'], 31.5204, 74.3487],
                ['Waqas Ahmed', '03444555666', null, 31.5102, 74.3441],
            ];
            foreach ($riderRows as [$name, $phone, $uid, $lat, $lng]) {
                DB::table('pos_riders')->updateOrInsert(
                    ['company_id' => $companyId, 'name' => $name],
                    ['phone' => $phone, 'user_id' => $uid, 'is_active' => true,
                     'on_duty' => true, 'duty_started_at' => now()->subHours(2),
                     'last_lat' => $lat, 'last_lng' => $lng, 'last_located_at' => now(),
                     'updated_at' => now(), 'created_at' => now()]
                );
            }
            $imranId = DB::table('pos_riders')->where('company_id', $companyId)->where('name', 'Imran Ali')->value('id');
            if ($imranId) {
                DB::table('pos_rider_locations')->where('company_id', $companyId)->delete();
                $lat = 31.5310; $lng = 74.3390;
                for ($i = 0; $i < 18; $i++) {
                    $lat -= 0.0006 + (($i % 3) * 0.0001);
                    $lng += 0.0005 + (($i % 2) * 0.0002);
                    DB::table('pos_rider_locations')->insert([
                        'company_id' => $companyId, 'rider_id' => $imranId,
                        'lat' => $lat, 'lng' => $lng, 'accuracy_m' => 8,
                        'recorded_at' => now()->subMinutes(36 - 2 * $i),
                        'client_ts_ms' => (int) (now()->subMinutes(36 - 2 * $i)->valueOf()),
                        'is_offline' => false, 'created_at' => now(),
                    ]);
                }
            }

            // ── Mark What's New seen (popup would block the first click) ──
            foreach ($userIds as $uid) {
                $unseen = DB::table('app_updates')
                    ->whereNotIn('id', DB::table('app_update_seens')->where('user_id', $uid)->pluck('app_update_id'))
                    ->pluck('id');
                foreach ($unseen as $updateId) {
                    DB::table('app_update_seens')->insert([
                        'app_update_id' => $updateId, 'user_id' => $uid,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();
            $this->command?->info("Resto demo shop ready (company_id={$companyId}). Login: " . self::LOGIN_EMAIL);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
