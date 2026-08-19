<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * DI video demo company — "Al-Farooq Traders" (fictional B2B wholesaler).
 * DEV ONLY: used to record marketing B-roll for the Digital Invoice panel.
 * Mirrors FbrVideoDemoShopSeeder pattern.
 * Idempotent (purge-and-reseed invoices, updateOrInsert company/user/subscription).
 * Never run on cPanel LIVE — guarded by staging DB name check.
 */
class DiVideoDemoShopSeeder extends Seeder
{
    public const COMPANY_NAME   = 'Al-Farooq Traders';
    public const LOGIN_EMAIL    = 'didemo@nestpos.pk';
    /** Dev-only demo password comes from env — repo is public, never hardcode (Aug 2026). */
    public static function loginPassword(): string
    {
        $p = trim((string) env('VIDEO_DEMO_PASS', ''));
        if ($p === '') {
            throw new \RuntimeException('VIDEO_DEMO_PASS env var is not set — add it to .env (dev-only) before seeding the video demo shops.');
        }

        return $p;
    }

    // Fake 7-digit NTN — invoice numbers: 3700001DI00001 … 3700001DI00017
    private const FAKE_NTN = '3700001';

    public function run(): void
    {
        // ── FAIL-CLOSED DEV GUARD ───────────────────────────────────────────────
        $dbName = DB::connection()->getDatabaseName();
        if (!str_contains($dbName, 'staging')) {
            throw new \RuntimeException(
                "DiVideoDemoShopSeeder refused: database '{$dbName}' is not a staging DB. This seeder is DEV ONLY."
            );
        }

        DB::beginTransaction();
        try {
            // ── Company ─────────────────────────────────────────────────────────
            $existing = DB::table('companies')->where('name', self::COMPANY_NAME)->first();
            $companyData = [
                'owner_name'           => 'Al-Farooq Sahab',
                'ntn'                  => self::FAKE_NTN,
                'email'                => self::LOGIN_EMAIL,
                'phone'                => '03000000002',
                'address'              => 'Gulberg III, Lahore',
                'city'                 => 'Lahore',
                'province'             => 'Punjab',
                'sector_type'          => 'Wholesale',
                'product_type'         => 'di',
                'status'               => 'approved',
                'company_status'       => 'active',
                'is_internal_account'  => true,
                'onboarding_completed' => true,
                'standard_tax_rate'    => 18,
                // Counter starts at 18 — 15 submitted (00001–00015) + 2 draft (00016–00017)
                'next_invoice_number'  => 18,
                'updated_at'           => now(),
            ];
            if ($existing) {
                $companyId = $existing->id;
                DB::table('companies')->where('id', $companyId)->update($companyData);
            } else {
                $companyId = DB::table('companies')->insertGetId(
                    $companyData + ['name' => self::COMPANY_NAME, 'created_at' => now()]
                );
            }

            // ── User ────────────────────────────────────────────────────────────
            $existingUser = DB::table('users')->where('email', self::LOGIN_EMAIL)->first();
            $userData = [
                'name'       => 'Al-Farooq Sahab',
                'password'   => Hash::make(self::loginPassword()),
                'company_id' => $companyId,
                'role'       => 'company_admin',
                'is_active'  => true,
                'updated_at' => now(),
            ];
            if ($existingUser) {
                $userId = $existingUser->id;
                DB::table('users')->where('id', $userId)->update($userData);
            } else {
                $userId = DB::table('users')->insertGetId(
                    $userData + ['email' => self::LOGIN_EMAIL, 'created_at' => now()]
                );
            }

            // ── Lifetime subscription ────────────────────────────────────────────
            // SubscriptionAccessService has NO is_internal_account bypass —
            // without this row every invoice store() 403s at plan.limit middleware.
            $planId = DB::table('pricing_plans')
                ->where('product_type', 'di')
                ->orderBy('id')
                ->value('id');
            if ($planId && !DB::table('subscriptions')
                ->where('company_id', $companyId)
                ->where('active', true)
                ->exists()) {
                DB::table('subscriptions')->insert([
                    'company_id'          => $companyId,
                    'pricing_plan_id'     => $planId,
                    'billing_cycle'       => 'monthly',
                    'start_date'          => now()->toDateString(),
                    'end_date'            => now()->addYears(10)->toDateString(),
                    'active'              => true,
                    'override_type'       => 'lifetime',
                    'override_granted_at' => now(),
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
            }

            // ── Buyers ──────────────────────────────────────────────────────────
            $buyers = [
                ['Tariq Electronics Pvt Ltd', '4100001001'],   // [0]
                ['Zara Fabrics & Co',          '6100002001'],   // [1]
                ['Hassan Brothers Imports',    '3100003001'],   // [2]
            ];

            // ── Product pools by type ────────────────────────────────────────────
            $pools = [
                'electronics' => [
                    ['8504.40', 'UPS 1000VA Inverter Unit',         10, 8000,  18],
                    ['8525.80', 'CCTV Camera HD 2MP',               20, 3500,  18],
                    ['9405.40', 'LED Panel Light 24W',              50, 1500,  18],
                ],
                'fabrics' => [
                    ['5208.21', 'Cotton Fabric Plain Weave 60"',   500, 180,   0],
                    ['5407.61', 'Polyester Fabric Roll 58"',       300, 220,   0],
                ],
                'imports' => [
                    ['8413.70', 'Industrial Water Pump 5HP',         5, 35000, 18],
                    ['8431.49', 'Conveyor Belt Parts & Rollers',     1, 95000, 18],
                ],
            ];

            // ── Purge old seeded invoices (idempotent) ───────────────────────────
            $oldIds = DB::table('invoices')->where('company_id', $companyId)->pluck('id');
            if ($oldIds->count()) {
                DB::table('invoice_items')->whereIn('invoice_id', $oldIds)->delete();
                DB::table('invoices')->where('company_id', $companyId)->delete();
            }

            // ── 15 submitted invoices (status=locked, fbr_status=production) ─────
            // [buyer_idx, type, ex_tax, tax, days_ago]
            $submitted = [
                [0, 'electronics', 100000, 18000, 30],
                [0, 'electronics',  55000,  9900, 28],
                [1, 'fabrics',      75000,     0, 27],
                [1, 'fabrics',      40000,     0, 25],
                [2, 'imports',     120000, 21600, 24],
                [0, 'electronics',  88000, 15840, 22],
                [1, 'fabrics',      62000,     0, 20],
                [2, 'imports',      95000, 17100, 18],
                [0, 'electronics',  44000,  7920, 15],
                [1, 'fabrics',      33000,     0, 13],
                [2, 'imports',     150000, 27000, 11],
                [0, 'electronics',  71000, 12780,  9],
                [1, 'fabrics',      48000,     0,  7],
                [2, 'imports',      82000, 14760,  5],
                [0, 'electronics',  60000, 10800,  3],
            ];

            foreach ($submitted as $idx => [$bIdx, $type, $exTax, $tax, $dAgo]) {
                $num    = str_pad($idx + 1, 5, '0', STR_PAD_LEFT);
                $invNum = self::FAKE_NTN . 'DI' . $num;
                $total  = $exTax + $tax;
                [$bName, $bNtn] = $buyers[$bIdx];
                $date   = now()->subDays($dAgo)->toDateString();

                $qrData = json_encode([
                    'fbr_invoice_number' => $invNum,
                    'invoiceDate'        => $date,
                    'totalValues'        => $total,
                    'sellerNTN'          => self::FAKE_NTN,
                    'buyerNTN'           => $bNtn,
                ]);

                $invoiceId = DB::table('invoices')->insertGetId([
                    'company_id'               => $companyId,
                    'invoice_number'           => $invNum,
                    'internal_invoice_number'  => $invNum,
                    'fbr_invoice_number'       => $invNum,
                    'status'                   => 'locked',
                    'fbr_status'               => 'production',
                    'buyer_name'               => $bName,
                    'buyer_ntn'                => $bNtn,
                    'buyer_registration_type'  => 'Registered',
                    'supplier_province'        => 'Punjab',
                    'destination_province'     => 'Punjab',
                    'document_type'            => 'Sale Invoice',
                    'invoice_date'             => $date,
                    'total_value_excluding_st' => $exTax,
                    'total_sales_tax'          => $tax,
                    'total_amount'             => $total,
                    'net_receivable'           => $total,
                    'qr_data'                  => $qrData,
                    'share_uuid'               => Str::uuid()->toString(),
                    'submitted_at'             => now()->subDays($dAgo)->addHours(2),
                    'created_at'               => now()->subDays($dAgo),
                    'updated_at'               => now()->subDays($dAgo)->addHours(2),
                ]);

                $this->seedItems($invoiceId, $pools[$type], $dAgo);
            }

            // ── 2 draft invoices (for create/submit B-roll) ─────────────────────
            $drafts = [
                [0, 'electronics', 70000, 12600],
                [1, 'fabrics',     50000,     0],
            ];
            foreach ($drafts as $di => [$bIdx, $type, $exTax, $tax]) {
                $num    = str_pad(16 + $di, 5, '0', STR_PAD_LEFT);
                $invNum = self::FAKE_NTN . 'DI' . $num;
                $total  = $exTax + $tax;
                [$bName, $bNtn] = $buyers[$bIdx];

                $invoiceId = DB::table('invoices')->insertGetId([
                    'company_id'               => $companyId,
                    'invoice_number'           => $invNum,
                    'internal_invoice_number'  => $invNum,
                    'fbr_invoice_number'       => null,
                    'status'                   => 'draft',
                    'fbr_status'               => null,
                    'buyer_name'               => $bName,
                    'buyer_ntn'                => $bNtn,
                    'buyer_registration_type'  => 'Registered',
                    'supplier_province'        => 'Punjab',
                    'destination_province'     => 'Punjab',
                    'document_type'            => 'Sale Invoice',
                    'invoice_date'             => now()->toDateString(),
                    'total_value_excluding_st' => $exTax,
                    'total_sales_tax'          => $tax,
                    'total_amount'             => $total,
                    'net_receivable'           => $total,
                    'created_at'               => now()->subHours(1),
                    'updated_at'               => now()->subHours(1),
                ]);

                $this->seedItems($invoiceId, $pools[$type], 0);
            }

            DB::commit();
            $this->command->info('DiVideoDemoShopSeeder OK — company_id=' . $companyId . ', user_id=' . $userId);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function seedItems(int $invoiceId, array $pool, int $daysAgo): void
    {
        // Use first 2 items from pool — enough to show variety without clutter.
        $items = array_slice($pool, 0, 2);
        $ts    = now()->subDays($daysAgo);

        foreach ($items as [$hs, $desc, $qty, $unitPrice, $taxRate]) {
            $lineTotal = round($unitPrice * $qty, 2);
            $lineTax   = round($lineTotal * $taxRate / 100, 2);
            DB::table('invoice_items')->insert([
                'invoice_id'  => $invoiceId,
                'hs_code'     => $hs,
                'description' => $desc,
                'quantity'    => $qty,
                'price'       => $unitPrice,
                'tax'         => $lineTax,
                'created_at'  => $ts,
                'updated_at'  => $ts,
            ]);
        }
    }
}
