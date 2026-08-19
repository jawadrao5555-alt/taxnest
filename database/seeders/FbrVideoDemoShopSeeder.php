<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * FBR POS video demo shop — fictional "Bismillah Karyana Store".
 * DEV ONLY: used to record marketing/tutorial B-roll for the FBR POS panel.
 * Mirrors VideoDemoShopSeeder (PRA) but for product_type=fbrpos.
 * Never record a real customer company. Idempotent (updateOrInsert by keys).
 */
class FbrVideoDemoShopSeeder extends Seeder
{
    public const COMPANY_NAME = 'Bismillah Karyana Store';
    public const LOGIN_EMAIL = 'fbrdemo@nestpos.pk';
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
        // 🔒 FAIL-CLOSED DEV GUARD — this seeder writes known demo credentials and
        // flips company FBR flags. APP_ENV is 'production' even in dev on this
        // project, so gate on the DATABASE NAME instead: only the local staging
        // MySQL is allowed. Never run on cPanel LIVE.
        $dbName = DB::connection()->getDatabaseName();
        if (!str_contains($dbName, 'staging')) {
            throw new \RuntimeException("FbrVideoDemoShopSeeder refused: database '{$dbName}' is not a staging DB. This seeder is DEV ONLY.");
        }

        DB::beginTransaction();
        try {
            $company = DB::table('companies')->where('name', self::COMPANY_NAME)->first();
            $companyData = [
                'owner_name' => 'Malik Sahab',
                'ntn' => '9999999999998',
                'email' => self::LOGIN_EMAIL,
                'phone' => '03000000001',
                'address' => 'Main Bazaar, Ichhra, Lahore',
                'city' => 'Lahore',
                'province' => 'Punjab',
                'sector_type' => 'Retail',
                'product_type' => 'fbrpos',
                'status' => 'approved',
                'company_status' => 'active',
                // Internal account: plan gates & quotas must never block a recording.
                'is_internal_account' => true,
                'onboarding_completed' => true,
                'standard_tax_rate' => 18,
                'fbr_pos_enabled' => true,
                'fbr_universal_enabled' => true,
                // FBR reporting ON but in fiscal_device+agent mode: store() queues the
                // bill 'pending' for the Desktop Agent and NEVER direct-submits — so
                // recording shows "FBR ko report ho raha hai" with zero regulator calls.
                'fbr_reporting_enabled' => true,
                'fbr_connection_mode' => 'fiscal_device',
                'agent_enabled' => true,
                // Inventory needs BOTH switches (feature flag + column).
                'inventory_enabled' => true,
                'feature_flags' => json_encode([
                    'inventory' => true,
                    'barcode' => true,
                    'customer_profile' => true,
                ]),
                'updated_at' => now(),
            ];
            if ($company) {
                $companyId = $company->id;
                DB::table('companies')->where('id', $companyId)->update($companyData);
            } else {
                $companyId = DB::table('companies')->insertGetId(
                    $companyData + ['name' => self::COMPANY_NAME, 'created_at' => now()]
                );
            }

            $userData = [
                'name' => 'Malik Sahab',
                'password' => Hash::make(self::loginPassword()),
                'company_id' => $companyId,
                'role' => 'company_admin',
                'pos_role' => null,
                'is_active' => true,
                'updated_at' => now(),
            ];
            $user = DB::table('users')->where('email', self::LOGIN_EMAIL)->first();
            if ($user) {
                DB::table('users')->where('id', $user->id)->update($userData);
            } else {
                DB::table('users')->insert($userData + ['email' => self::LOGIN_EMAIL, 'created_at' => now()]);
            }

            // Lifetime override subscription — SubscriptionAccessService::hasAccess
            // has NO is_internal_account bypass; without this row every final bill
            // 403s at plan.limit middleware ("no active subscription").
            $planId = DB::table('pricing_plans')->where('product_type', 'fbrpos')->orderBy('id')->value('id');
            if ($planId && !DB::table('subscriptions')->where('company_id', $companyId)->where('active', true)->exists()) {
                DB::table('subscriptions')->insert([
                    'company_id' => $companyId,
                    'pricing_plan_id' => $planId,
                    'billing_cycle' => 'monthly',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addYears(10)->toDateString(),
                    'active' => true,
                    'override_type' => 'lifetime',
                    'override_granted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Karyana product set — UoM variety on purpose (KG/LTR/BTL/PKT/DOZ/BAG/PCS).
            // [name, uom, sale price, cost, tax%, stock qty, min level]
            $products = [
                ['Cheeni',                'KG',  165,  150, 0,  42,   10],
                ['Chawal Basmati',        'KG',  370,  338, 0,  65,   15],
                ['Aata 10kg',             'BAG', 1250, 1180, 0, 18,   6],
                ['Cooking Oil 1L',        'BTL', 560,  515, 0,  30,   12],
                ['Doodh Pack 1L',         'PCS', 220,  201, 0,  24,   10],
                ['Anday',                 'DOZ', 330,  305, 0,  15,   5],
                ['Chai Patti 950g',       'PKT', 1450, 1345, 18, 12,  4],
                ['Pepsi 1.5L',            'BTL', 180,  152, 18, 8,    12],
                ['Mineral Water 1.5L',    'BTL', 80,   61,  18, 36,   12],
                ['Surf Excel 1kg',        'PKT', 380,  344, 18, 14,   6],
                ['Lifebuoy Soap',         'PCS', 95,   81,  18, 48,   12],
                ['Biscuit Family Pack',   'PKT', 150,  127, 18, 3,    10],
                ['Lays Chips',            'PKT', 70,   57,  18, 40,   15],
                ['Daal Chana',            'KG',  340,  312, 0,  22,   8],
                ['Nimko 250g',            'PKT', 160,  133, 18, 16,   6],
            ];
            $productIds = [];
            foreach ($products as $i => [$name, $uom, $price, $cost, $tax, $qty, $min]) {
                $sku = 'BKS-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
                DB::table('products')->updateOrInsert(
                    ['company_id' => $companyId, 'sku' => $sku],
                    [
                        'name' => $name,
                        'hs_code' => '0000.0000', // fictional — hs_code is NOT NULL in the base schema
                        'uom' => $uom,
                        'default_price' => $price,
                        'cost_price' => $cost,
                        'default_tax_rate' => $tax,
                        'is_price_editable' => true,
                        'is_active' => true,
                        'show_on_sale' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $pid = DB::table('products')->where('company_id', $companyId)->where('sku', $sku)->value('id');
                $productIds[$name] = ['id' => $pid, 'price' => $price, 'cost' => $cost, 'tax' => $tax, 'uom' => $uom];
                // NULL branch_id breaks updateOrInsert idempotency on MySQL
                // (NULL never matches in unique lookups) — match IS NULL explicitly.
                $stockRow = DB::table('inventory_stocks')
                    ->where('company_id', $companyId)->where('product_id', $pid)
                    ->whereNull('branch_id')->first();
                $stockData = [
                    'quantity' => $qty,
                    'min_stock_level' => $min,
                    'avg_purchase_price' => $cost,
                    'last_purchase_price' => $cost,
                    'updated_at' => now(),
                ];
                if ($stockRow) {
                    DB::table('inventory_stocks')->where('id', $stockRow->id)->update($stockData);
                } else {
                    DB::table('inventory_stocks')->insert($stockData + [
                        'company_id' => $companyId, 'product_id' => $pid, 'branch_id' => null, 'created_at' => now(),
                    ]);
                }
            }

            // Khata customers (list page tiles + balances).
            $khata = [
                ['Haji Imran',      '03214567890', 2450],
                ['Shabbir Bhai',    '03009876543', 1800],
                ['Master Sadiq',    '03331234567', 950],
                ['Baji Nasreen',    '03451112233', 600],
            ];
            $customerIds = [];
            foreach ($khata as [$name, $phone, $balance]) {
                DB::table('pos_customers')->updateOrInsert(
                    ['company_id' => $companyId, 'phone' => $phone],
                    [
                        'name' => $name,
                        'type' => 'individual',
                        'is_active' => true,
                        'khata_balance' => $balance,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $cid = DB::table('pos_customers')->where('company_id', $companyId)->where('phone', $phone)->value('id');
                $customerIds[$name] = $cid;
            }
            // A little wasooli history so the green tile isn't zero.
            if (!DB::table('fbr_customer_ledgers')->where('company_id', $companyId)->where('entry_type', 'wasooli')->exists()) {
                foreach ([['Haji Imran', 1000, 3], ['Master Sadiq', 500, 5]] as [$name, $amt, $daysAgo]) {
                    DB::table('fbr_customer_ledgers')->insert([
                        'company_id' => $companyId,
                        'customer_id' => $customerIds[$name],
                        'entry_type' => 'wasooli',
                        'amount' => $amt,
                        'balance_after' => 0,
                        'note' => 'Naqad wasooli',
                        'created_at' => now()->subDays($daysAgo),
                        'updated_at' => now()->subDays($daysAgo),
                    ]);
                }
            }

            // Sales history (last 7 days) — feeds dashboard KPIs + Munafa report.
            // invoice_mode 'fbr' + fbr_status NULL = final bills, reporting-off pattern.
            if (!DB::table('fbr_pos_transactions')->where('company_id', $companyId)->exists()) {
                mt_srand(42);
                $names = array_keys($productIds);
                $seq = 1;
                for ($day = 7; $day >= 1; $day--) {
                    $billCount = mt_rand(3, 5);
                    for ($b = 0; $b < $billCount; $b++) {
                        $at = now()->subDays($day)->setTime(mt_rand(10, 21), mt_rand(0, 59));
                        $lineCount = mt_rand(2, 4);
                        $subtotal = 0; $taxTotal = 0; $items = [];
                        $picked = (array) array_rand(array_flip($names), $lineCount);
                        foreach ($picked as $pname) {
                            $p = $productIds[$pname];
                            $qty = in_array($p['uom'], ['KG', 'LTR'], true) ? mt_rand(5, 30) / 10 : mt_rand(1, 3);
                            $lineEx = round($p['price'] * $qty, 2);
                            $lineTax = round($lineEx * $p['tax'] / 100, 2);
                            $subtotal += $lineEx; $taxTotal += $lineTax;
                            $items[] = [
                                'product_id' => $p['id'],
                                'item_name' => $pname,
                                'uom' => $p['uom'],
                                'quantity' => $qty,
                                'unit_price' => $p['price'],
                                'cost_price' => $p['cost'],
                                'discount' => 0,
                                'item_discount' => 0,
                                'tax_rate' => $p['tax'],
                                'tax_amount' => $lineTax,
                                'subtotal' => $lineEx,
                                'total' => $lineEx + $lineTax,
                                'is_tax_exempt' => $p['tax'] == 0,
                                'created_at' => $at,
                                'updated_at' => $at,
                            ];
                        }
                        $txnId = DB::table('fbr_pos_transactions')->insertGetId([
                            'company_id' => $companyId,
                            'invoice_number' => 'BKS' . $at->format('ymd') . str_pad((string) $seq++, 3, '0', STR_PAD_LEFT),
                            'invoice_mode' => 'fbr',
                            'transaction_type' => 'sale',
                            'subtotal' => round($subtotal, 2),
                            'discount_amount' => 0,
                            'tax_amount' => round($taxTotal, 2),
                            'total_amount' => round($subtotal + $taxTotal, 2),
                            'payment_method' => mt_rand(1, 10) <= 8 ? 'cash' : 'credit',
                            'status' => 'completed',
                            'fbr_status' => 'submitted', // green badges on dashboard footage
                            'created_at' => $at,
                            'updated_at' => $at,
                        ]);
                        DB::table('fbr_pos_transactions')->where('id', $txnId)
                            ->update(['fbr_invoice_number' => '7000' . str_pad((string) $txnId, 9, '0', STR_PAD_LEFT)]);
                        foreach ($items as $it) {
                            DB::table('fbr_pos_transaction_items')->insert($it + ['transaction_id' => $txnId]);
                        }
                    }
                }
            }

            DB::commit();
            $this->command?->info("FBR video demo shop ready: company #{$companyId} (" . self::LOGIN_EMAIL . ')');
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
