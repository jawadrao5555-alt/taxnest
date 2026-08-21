<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch stock, step 1: adopt the old branch-less stock (Task 1354).
 *
 * Until now every PRA POS inventory row was written with `branch_id = NULL`
 * — one shared pile of goods for the whole company. Now that stock is kept per
 * branch, those legacy rows would belong to nobody: the head office would open
 * at zero and the shop's real stock would look like it had vanished.
 *
 * So for every PRA POS company that HAS branches, the branch-less
 * inventory_stocks / inventory_movements rows are moved onto its head office
 * (the "main shop"), which is exactly where that stock has physically been all
 * along. Companies without branches keep `branch_id = NULL` and are untouched.
 *
 * Scope guards (inventory tables are SHARED with DI/FBR — see
 * pos-inventory-mirror):
 *   - only companies that actually own `pos_products` rows, and
 *   - only stock/movement rows whose product_id is one of THOSE pos_products.
 * DI and FBR POS keep writing NULL-branch rows and are deliberately skipped.
 *
 * Idempotent: after the first run there are no NULL rows left to move, and a
 * merge (rather than a blind update) keeps the
 * (company_id, product_id, branch_id) unique index happy if a branch row
 * already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['branches', 'pos_products', 'inventory_stocks'] as $table) {
            if (!Schema::hasTable($table)) {
                return;
            }
        }
        if (!Schema::hasColumn('inventory_stocks', 'branch_id')) {
            return;
        }

        // PRA POS companies that have at least one branch.
        $companyIds = DB::table('branches')->distinct()->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $productIds = DB::table('pos_products')->where('company_id', $companyId)->pluck('id')->all();
            if (empty($productIds)) {
                continue; // DI / FBR POS company — its stock stays branch-less.
            }

            $headOffice = DB::table('branches')
                ->where('company_id', $companyId)
                ->orderByDesc('is_head_office')
                ->orderBy('id')
                ->value('id');
            if (!$headOffice) {
                continue;
            }

            $legacy = DB::table('inventory_stocks')
                ->where('company_id', $companyId)
                ->whereNull('branch_id')
                ->whereIn('product_id', $productIds)
                ->get();

            foreach ($legacy as $row) {
                $existing = DB::table('inventory_stocks')
                    ->where('company_id', $companyId)
                    ->where('product_id', $row->product_id)
                    ->where('branch_id', $headOffice)
                    ->first();

                if ($existing) {
                    DB::table('inventory_stocks')->where('id', $existing->id)->update([
                        'quantity' => (float) $existing->quantity + (float) $row->quantity,
                        'min_stock_level' => max((float) $existing->min_stock_level, (float) $row->min_stock_level),
                        'avg_purchase_price' => (float) $existing->avg_purchase_price ?: (float) $row->avg_purchase_price,
                        'last_purchase_price' => (float) $existing->last_purchase_price ?: (float) $row->last_purchase_price,
                        'updated_at' => now(),
                    ]);
                    DB::table('inventory_stocks')->where('id', $row->id)->delete();
                } else {
                    DB::table('inventory_stocks')->where('id', $row->id)->update([
                        'branch_id' => $headOffice,
                        'updated_at' => now(),
                    ]);
                }
            }

            // History follows the goods so a branch ledger never opens empty.
            if (Schema::hasTable('inventory_movements') && Schema::hasColumn('inventory_movements', 'branch_id')) {
                DB::table('inventory_movements')
                    ->where('company_id', $companyId)
                    ->whereNull('branch_id')
                    ->whereIn('product_id', $productIds)
                    ->update(['branch_id' => $headOffice]);
            }
        }
    }

    public function down(): void
    {
        // Data backfill — the original NULL/branch split is not recoverable.
    }
};
