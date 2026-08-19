<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

/**
 * Task 232 — enrich the video demo shop (Al-Noor General Store) with realistic
 * history so day-close & reports tutorial videos show full screens:
 *   - 14 days of completed sales (reporting-OFF finals: invoice_mode='pra' + pra_status=NULL)
 *   - inventory ON (both switches), inventory_stocks mirrored, movement history,
 *     a few low-stock products
 *   - opening-cash rows for the last week + today
 *   - a second cashier for cashier-breakdown tables
 *
 * Idempotent: skips sales generation if demo history already exists.
 * Dev/demo use only — company is the fictional internal demo shop.
 */
class VideoDemoHistorySeeder extends Seeder
{
    public function run(): void
    {
        $company = DB::table('companies')->where('name', VideoDemoShopSeeder::COMPANY_NAME)->first();
        if (!$company) {
            $this->command?->error('Run VideoDemoShopSeeder first.');
            return;
        }
        $companyId = $company->id;
        $adminId = DB::table('users')->where('email', VideoDemoShopSeeder::LOGIN_EMAIL)->value('id');

        // Second cashier for breakdown tables.
        $cashierEmail = 'videocashier@nestpos.pk';
        $cashier = DB::table('users')->where('email', $cashierEmail)->first();
        if (!$cashier) {
            DB::table('users')->insert([
                'name' => 'Imran (Cashier)',
                'email' => $cashierEmail,
                'password' => Hash::make(VideoDemoShopSeeder::loginPassword()),
                'company_id' => $companyId,
                'role' => 'employee',
                'pos_role' => 'pos_cashier',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $cashierId = DB::table('users')->where('email', $cashierEmail)->value('id');

        // ── Inventory ON: BOTH switches (feature flag + column) ──
        $flags = json_decode($company->feature_flags ?? '{}', true) ?: [];
        $flags['inventory'] = true;
        DB::table('companies')->where('id', $companyId)->update([
            'inventory_enabled' => true,
            'feature_flags' => json_encode($flags),
            'updated_at' => now(),
        ]);

        $products = DB::table('pos_products')->where('company_id', $companyId)->where('is_active', true)->get();

        // Opening stock per product (only if no stock row yet).
        foreach ($products as $p) {
            $exists = DB::table('inventory_stocks')->where('company_id', $companyId)->where('product_id', $p->id)->whereNull('branch_id')->exists();
            if ($exists) continue;
            $opening = random_int(60, 220);
            $cost = round($p->price * 0.8, 2);
            DB::table('inventory_stocks')->insert([
                'company_id' => $companyId, 'product_id' => $p->id, 'branch_id' => null,
                'quantity' => $opening, 'min_stock_level' => $p->low_stock_threshold ?: 10,
                'avg_purchase_price' => $cost, 'last_purchase_price' => $cost,
                'created_at' => now()->subDays(15), 'updated_at' => now()->subDays(15),
            ]);
            DB::table('inventory_movements')->insert([
                'company_id' => $companyId, 'product_id' => $p->id, 'branch_id' => null,
                'type' => 'opening', 'quantity' => $opening, 'unit_price' => $cost,
                'total_price' => round($cost * $opening, 2), 'balance_after' => $opening,
                'reference_type' => null, 'reference_id' => null, 'reference_number' => null,
                'notes' => 'Opening stock', 'created_by' => $adminId,
                'created_at' => now()->subDays(15), 'updated_at' => now()->subDays(15),
            ]);
            // cost_price for profit analytics
            DB::table('pos_products')->where('id', $p->id)->update(['cost_price' => $cost]);
        }

        // ── Sales history ──
        $already = DB::table('pos_transactions')->where('company_id', $companyId)->where('status', 'completed')->count();
        if ($already >= 50) {
            $this->command?->info("History already present ({$already} bills) — skipping sales generation.");
        } else {
            $customers = DB::table('pos_customers')->where('company_id', $companyId)->whereNotNull('phone')->pluck('name', 'id');
            $prodArr = $products->all();
            $serial = 0;
            $lastNum = DB::table('pos_transactions')->where('company_id', $companyId)
                ->where('invoice_number', 'like', 'POS-' . now()->format('Y') . '-%')
                ->selectRaw("MAX(CAST(SUBSTR(invoice_number, 10) AS UNSIGNED)) as m")->value('m');
            $serial = (int) $lastNum;

            for ($d = 13; $d >= 0; $d--) {
                $day = Carbon::today()->subDays($d);
                $isToday = $d === 0;
                $billCount = $isToday ? 14 : random_int(16, 30);
                // Weekend (Sat/Sun) bump
                if (in_array($day->dayOfWeek, [0, 6])) $billCount = (int) round($billCount * 1.3);

                for ($b = 0; $b < $billCount; $b++) {
                    // Business hours 9am–10pm, evening-weighted; today caps at "now - 30min"
                    $hour = [9,10,11,11,12,12,13,14,15,16,17,17,18,18,19,19,20,20,21,22][array_rand(range(0, 19))];
                    $hour = random_int(0, 9) < 5 ? random_int(17, 21) : random_int(9, 16);
                    $ts = $day->copy()->setTime($hour, random_int(0, 59), random_int(0, 59));
                    if ($isToday && $ts->gt(now()->subMinutes(30))) {
                        $ts = now()->subMinutes(random_int(30, 240));
                    }

                    // Items
                    $nItems = random_int(1, 5);
                    $picked = (array) array_rand($prodArr, min($nItems, count($prodArr)));
                    $subtotal = 0.0; $taxTotal = 0.0; $items = [];
                    foreach ($picked as $idx) {
                        $p = $prodArr[$idx];
                        $qty = in_array($p->category, ['Snacks', 'Beverages', 'Dairy']) ? random_int(1, 4) : random_int(1, 2);
                        $lineSub = round($p->price * $qty, 2);
                        $lineTax = round($lineSub * ($p->tax_rate / 100), 2);
                        $subtotal += $lineSub; $taxTotal += $lineTax;
                        $items[] = ['p' => $p, 'qty' => $qty, 'sub' => $lineSub, 'tax' => $lineTax];
                    }

                    // Occasional bill discount
                    $discount = random_int(0, 9) === 0 ? (float) (5 * random_int(2, 10)) : 0.0;
                    $discount = min($discount, $subtotal * 0.2);
                    $taxRnd = (float) round($taxTotal);           // whole-rupee header (PRA convention)
                    $total = (float) round($subtotal - $discount + $taxRnd);

                    $method = random_int(0, 9) < 7 ? 'cash' : (random_int(0, 4) === 0 ? 'jazzcash' : 'debit_card');
                    $custId = null; $custName = null;
                    if (random_int(0, 4) === 0 && $customers->count()) {
                        $custId = $customers->keys()->random();
                        $custName = $customers[$custId];
                    }

                    $serial++;
                    $txId = DB::table('pos_transactions')->insertGetId([
                        'company_id' => $companyId,
                        'invoice_number' => sprintf('POS-%s-%05d', $ts->format('Y'), $serial),
                        'invoice_mode' => 'pra',        // reporting-OFF final: pra + NULL status
                        'pra_status' => null,
                        'status' => 'completed',
                        'customer_id' => $custId,
                        'customer_name' => $custName,
                        'subtotal' => round($subtotal, 2),
                        'discount_type' => 'amount',
                        'discount_value' => $discount,
                        'discount_amount' => $discount,
                        'tax_amount' => $taxRnd,
                        'total_amount' => $total,
                        'payment_method' => $method,
                        'cash_received' => $method === 'cash' ? ceil($total / 100) * 100 : $total,
                        'change_due' => $method === 'cash' ? ceil($total / 100) * 100 - $total : 0,
                        'created_by' => random_int(0, 2) === 0 ? $cashierId : $adminId,
                        'business_date' => $ts->toDateString(),
                        'created_at' => $ts,
                        'updated_at' => $ts,
                    ]);

                    foreach ($items as $it) {
                        DB::table('pos_transaction_items')->insert([
                            'transaction_id' => $txId,
                            'item_type' => 'product',
                            'item_id' => $it['p']->id,
                            'item_name' => $it['p']->name,
                            'quantity' => $it['qty'],
                            'unit_price' => $it['p']->price,
                            'subtotal' => $it['sub'],
                            'is_tax_exempt' => $it['p']->tax_rate == 0,
                            'tax_rate' => $it['p']->tax_rate,
                            'tax_amount' => $it['tax'],
                            'created_at' => $ts, 'updated_at' => $ts,
                        ]);
                        // Inventory: deduct + sale movement
                        DB::table('inventory_stocks')->where('company_id', $companyId)->where('product_id', $it['p']->id)->whereNull('branch_id')
                            ->decrement('quantity', $it['qty']);
                        $bal = DB::table('inventory_stocks')->where('company_id', $companyId)->where('product_id', $it['p']->id)->whereNull('branch_id')->value('quantity');
                        DB::table('inventory_movements')->insert([
                            'company_id' => $companyId, 'product_id' => $it['p']->id, 'branch_id' => null,
                            'type' => 'sale', 'quantity' => $it['qty'], 'unit_price' => $it['p']->price,
                            'total_price' => $it['sub'], 'balance_after' => $bal,
                            'reference_type' => 'pos_transaction', 'reference_id' => $txId,
                            'reference_number' => sprintf('POS-%s-%05d', $ts->format('Y'), $serial),
                            'notes' => null, 'created_by' => $adminId,
                            'created_at' => $ts, 'updated_at' => $ts,
                        ]);
                    }

                    DB::table('pos_payments')->insert([
                        'transaction_id' => $txId,
                        'payment_method' => $method,
                        'amount' => $total,
                        'created_at' => $ts, 'updated_at' => $ts,
                    ]);
                }
            }
            $this->command?->info("Seeded sales history up to serial {$serial}.");
        }

        // Mirror stock into pos_products.stock_quantity + force a few LOW-STOCK items.
        $lowSkus = ['GRC-002', 'DRY-001', 'SNK-001'];
        foreach ($products as $p) {
            $qty = DB::table('inventory_stocks')->where('company_id', $companyId)->where('product_id', $p->id)->whereNull('branch_id')->value('quantity');
            if ($qty === null) continue;
            if (in_array($p->sku, $lowSkus)) {
                $threshold = max(10, (int) $p->low_stock_threshold);
                $target = random_int(2, max(3, $threshold - 2));
                if ($qty > $target) {
                    DB::table('inventory_stocks')->where('company_id', $companyId)->where('product_id', $p->id)->whereNull('branch_id')
                        ->update(['quantity' => $target, 'updated_at' => now()]);
                    DB::table('inventory_movements')->insert([
                        'company_id' => $companyId, 'product_id' => $p->id, 'branch_id' => null,
                        'type' => 'adjustment_out', 'quantity' => $qty - $target, 'unit_price' => 0,
                        'total_price' => 0, 'balance_after' => $target,
                        'reference_type' => null, 'reference_id' => null, 'reference_number' => null,
                        'notes' => 'Stock count correction', 'created_by' => $adminId,
                        'created_at' => now()->subHours(20), 'updated_at' => now()->subHours(20),
                    ]);
                    $qty = $target;
                }
            }
            DB::table('pos_products')->where('id', $p->id)->update(['stock_quantity' => $qty]);
        }

        // Mark history as PRA-submitted (fake fiscal numbers, video-only): the
        // reports "PRA" tab and day-close PRA-health cards filter on
        // pra_status/pra_invoice_number — reporting-OFF NULL rows would leave
        // those screens empty. No regulator call is ever made for these rows.
        $pending = DB::table('pos_transactions')->where('company_id', $companyId)
            ->where('status', 'completed')->whereNull('pra_status')->orderBy('id')->get(['id']);
        foreach ($pending as $i => $row) {
            DB::table('pos_transactions')->where('id', $row->id)->update([
                'pra_status' => 'submitted',
                'pra_invoice_number' => (string) (52000000000000 + $row->id),
            ]);
        }

        // Opening cash for last 7 days + today.
        for ($d = 7; $d >= 0; $d--) {
            $day = Carbon::today()->subDays($d)->toDateString();
            DB::table('pos_day_openings')->updateOrInsert(
                ['company_id' => $companyId, 'business_date' => $day],
                ['opening_cash' => 5000, 'entered_by' => $adminId, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $this->command?->info('Video demo shop history ready.');
    }
}
