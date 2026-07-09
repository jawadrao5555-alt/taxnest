<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill inventory_stocks for POS companies that already had the inventory
 * module enabled before the mirror-sync fix. Products created earlier only
 * wrote pos_products.stock_quantity — the module's authoritative
 * inventory_stocks row was never seeded, so dashboards showed 0 / Unknown.
 *
 * Idempotent: only inserts where no (company, product, branch NULL) row exists.
 * DI and POS are isolated by company; this touches ONLY pos_products of
 * companies with inventory_enabled = 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        $companies = DB::table('companies')
            ->where('inventory_enabled', 1)
            ->pluck('id');

        foreach ($companies as $companyId) {
            $products = DB::table('pos_products')
                ->where('company_id', $companyId)
                ->whereNotNull('stock_quantity')
                ->get(['id', 'stock_quantity', 'low_stock_threshold', 'cost_price']);

            foreach ($products as $p) {
                $exists = DB::table('inventory_stocks')
                    ->where('company_id', $companyId)
                    ->where('product_id', $p->id)
                    ->whereNull('branch_id')
                    ->exists();
                if ($exists) {
                    continue;
                }

                DB::table('inventory_stocks')->insert([
                    'company_id' => $companyId,
                    'product_id' => $p->id,
                    'branch_id' => null,
                    'quantity' => (float) $p->stock_quantity,
                    'min_stock_level' => (float) ($p->low_stock_threshold ?? 0),
                    'max_stock_level' => null,
                    'avg_purchase_price' => (float) ($p->cost_price ?? 0),
                    'last_purchase_price' => (float) ($p->cost_price ?? 0),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ((float) $p->stock_quantity > 0) {
                    DB::table('inventory_movements')->insert([
                        'company_id' => $companyId,
                        'product_id' => $p->id,
                        'branch_id' => null,
                        'type' => 'opening',
                        'quantity' => (float) $p->stock_quantity,
                        'balance_after' => (float) $p->stock_quantity,
                        'reference_type' => 'backfill',
                        'notes' => 'Opening stock backfilled from product quantity',
                        'created_by' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Data backfill — no safe automatic reversal.
    }
};
