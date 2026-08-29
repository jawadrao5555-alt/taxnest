<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Task 231 — Urdu tutorial-video pipeline demo shop.
 *
 * A clean, polished, PURELY FICTIONAL general store used ONLY for screen
 * recordings of tutorial videos. Never contains real customer data.
 * Idempotent: re-running refreshes products/customers to the canonical set.
 *
 * Login (dev only): videodemo@nestpos.pk / password from VIDEO_DEMO_PASS env
 */
class VideoDemoShopSeeder extends Seeder
{
    public const COMPANY_NAME = 'Al-Noor General Store';
    public const LOGIN_EMAIL = 'videodemo@nestpos.pk';
    /** Dev-only demo password comes from env — repo is public, never hardcode (Aug 2026). */
    public static function loginPassword(): string
    {
        $p = trim((string) env('VIDEO_DEMO_PASS', ''));
        if ($p === '') {
            throw new \RuntimeException('VIDEO_DEMO_PASS env var is not set — add it to .env (dev-only) before seeding the video demo shops.');
        }

        return $p;
    }

    public function run(): void
    {
        // Fail closed. This seeder rewrites a company, its login and its whole
        // catalogue — a stray `db:seed --class=VideoDemoShopSeeder` anywhere
        // near a real database must do nothing. APP_ENV is 'production' even in
        // the dev workspace, so it proves nothing; the recording pipeline opts
        // in explicitly instead (same guard as VideoStockCheckSeeder).
        if (!filter_var(env('VIDEO_PIPELINE_ALLOW', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->command?->error('Refusing to run: set VIDEO_PIPELINE_ALLOW=1 (recording pipeline only).');
            return;
        }

        DB::beginTransaction();
        try {
            $company = DB::table('companies')->where('name', self::COMPANY_NAME)->first();
            // An existing row with this name must still be the internal demo
            // account before we overwrite anything inside it.
            if ($company && !($company->is_internal_account ?? false)) {
                DB::rollBack();
                $this->command?->error("Refusing to run: company #{$company->id} is not an internal account.");

                return;
            }
            if ($company) {
                $companyId = $company->id;
            } else {
                $companyId = DB::table('companies')->insertGetId([
                    'name' => self::COMPANY_NAME,
                    'owner_name' => 'Demo Owner',
                    'ntn' => '9999999999999',
                    'email' => self::LOGIN_EMAIL,
                    'phone' => '03000000000',
                    'address' => 'Shop 12, Liberty Market, Lahore',
                    'city' => 'Lahore',
                    'province' => 'Punjab',
                    'sector_type' => 'Retail',
                    'product_type' => 'pos',
                    'pos_type' => 'retail',
                    'status' => 'approved',
                    'company_status' => 'active',
                    // Internal account: all plan gates & quotas pass — demo shop
                    // must never hit paywalls mid-recording.
                    'is_internal_account' => true,
                    'onboarding_completed' => true,
                    'pos_setup_completed' => true,
                    'use_universal_pos' => true,
                    'standard_tax_rate' => 16,
                    // PRA reporting OFF for the video: no regulator calls, no
                    // QR/fiscal chatter — pure billing walkthrough.
                    'pra_reporting_enabled' => false,
                    'inventory_enabled' => false,
                    'feature_flags' => json_encode([
                        'inventory' => false,
                        'barcode' => true,
                        'customer_profile' => true,
                    ]),
                    'receipt_printer_size' => '80mm',
                    'receipt_footer_note' => 'Shukriya! Phir tashreef laiye.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Scope by company too: if that email ever belongs to a real user
            // on some other company, we must not reassign or re-password it.
            $user = DB::table('users')->where('email', self::LOGIN_EMAIL)->first();
            if ($user && (int) ($user->company_id ?? 0) !== 0 && (int) $user->company_id !== (int) $companyId) {
                DB::rollBack();
                $this->command?->error('Refusing to run: ' . self::LOGIN_EMAIL . ' already belongs to another company.');

                return;
            }
            $userData = [
                'name' => 'Demo Cashier',
                'password' => Hash::make(self::loginPassword()),
                'company_id' => $companyId,
                'role' => 'company_admin',
                'pos_role' => 'pos_admin',
                'is_active' => true,
                'updated_at' => now(),
            ];
            if ($user) {
                DB::table('users')->where('id', $user->id)->update($userData);
            } else {
                DB::table('users')->insert($userData + ['email' => self::LOGIN_EMAIL, 'created_at' => now()]);
            }

            // Canonical product set — realistic Pakistani general-store items.
            $products = [
                ['Chawal Basmati 5kg',      'Grocery',   1850, 0,  'GRC-001'],
                ['Cheeni 1kg',              'Grocery',    165, 0,  'GRC-002'],
                ['Aata 10kg',               'Grocery',   1250, 0,  'GRC-003'],
                ['Cooking Oil 1L',          'Grocery',    560, 16, 'GRC-004'],
                ['Daal Chana 1kg',          'Grocery',    340, 0,  'GRC-005'],
                ['Surf Excel 1kg',          'Household',  380, 16, 'HH-001'],
                ['Lifebuoy Soap',           'Household',   95, 16, 'HH-002'],
                ['Shampoo Sachet (12)',     'Household',  120, 16, 'HH-003'],
                ['Doodh 1L',                'Dairy',      220, 0,  'DRY-001'],
                ['Dahi 500g',               'Dairy',      140, 0,  'DRY-002'],
                ['Anday (Darjan)',          'Dairy',      330, 0,  'DRY-003'],
                ['Pepsi 1.5L',              'Beverages',  180, 16, 'BEV-001'],
                ['Mineral Water 1.5L',      'Beverages',   80, 0,  'BEV-002'],
                ['Chai Patti 950g',         'Beverages', 1450, 16, 'BEV-003'],
                ['Lays Chips',              'Snacks',      70, 16, 'SNK-001'],
                ['Biscuit Family Pack',     'Snacks',     150, 16, 'SNK-002'],
                ['Chocolate Bar',           'Snacks',     120, 16, 'SNK-003'],
                ['Nimko 250g',              'Snacks',     160, 16, 'SNK-004'],
            ];

            foreach ($products as [$name, $cat, $price, $tax, $sku]) {
                DB::table('pos_products')->updateOrInsert(
                    ['company_id' => $companyId, 'sku' => $sku],
                    [
                        'name' => $name,
                        'category' => $cat,
                        'price' => $price,
                        'tax_rate' => $tax,
                        'uom' => 'NOS',
                        'is_active' => true,
                        'show_on_sale' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            // Fictional demo customers only.
            $customers = [
                ['Walk-in Customer', null],
                ['Bilal Sahab', '03001112233'],
                ['Khala Shakeela', '03214445566'],
            ];
            foreach ($customers as [$name, $phone]) {
                DB::table('pos_customers')->updateOrInsert(
                    ['company_id' => $companyId, 'name' => $name],
                    [
                        'phone' => $phone,
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            // Active Unlimited subscription — invoice store rejects companies
            // without one even for internal accounts.
            $planId = DB::table('pricing_plans')->where('product_type', 'pos')->where('name', 'Unlimited')->value('id');
            if ($planId && !DB::table('subscriptions')->where('company_id', $companyId)->where('active', true)->exists()) {
                DB::table('subscriptions')->insert([
                    'company_id' => $companyId,
                    'pricing_plan_id' => $planId,
                    'billing_cycle' => 'annual',
                    'final_price' => 0,
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addYears(5)->toDateString(),
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Mark all "What's New" updates as seen — the popup would otherwise
            // block the very first click of every screen recording.
            $demoUserId = DB::table('users')->where('email', self::LOGIN_EMAIL)->value('id');
            $unseen = DB::table('app_updates')
                ->whereNotIn('id', DB::table('app_update_seens')->where('user_id', $demoUserId)->pluck('app_update_id'))
                ->pluck('id');
            foreach ($unseen as $updateId) {
                DB::table('app_update_seens')->insert([
                    'app_update_id' => $updateId,
                    'user_id' => $demoUserId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();
            $this->command?->info("Video demo shop ready (company_id={$companyId}). Login: " . self::LOGIN_EMAIL);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
