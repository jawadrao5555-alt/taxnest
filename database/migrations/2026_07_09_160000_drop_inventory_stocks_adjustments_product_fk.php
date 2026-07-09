<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// inventory_stocks.product_id and inventory_adjustments.product_id were created
// with a foreign key referencing the DI `products` table, but the POS inventory
// module stores POS product ids (`pos_products`). The wrong FK made every
// InventoryStock/InventoryAdjustment insert fail with a 1452 constraint
// violation, which deductStockForInvoice() swallows as a warning — so POS
// sales silently never deducted stock. Same root cause was already fixed for
// inventory_movements (2026_03_30_125535); this completes the fix for the
// remaining two tables. Idempotent + guarded for safe re-run on PROD.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventory_stocks', 'inventory_adjustments'] as $tableName) {
            $constraint = $tableName . '_product_id_foreign';
            $exists = DB::select(
                "SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_name = ? AND table_name = ?",
                [$constraint, $tableName]
            );
            if (!empty($exists)) {
                Schema::table($tableName, function (Blueprint $table) use ($constraint) {
                    $table->dropForeign($constraint);
                });
            }
        }
    }

    public function down(): void
    {
        // Intentionally not re-adding the wrong FK (it pointed at the DI
        // `products` table and broke POS inventory writes).
    }
};
