<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 FBR POS — quantity precision upgrade: DECIMAL(15,3) → DECIMAL(10,4)
 *
 * Why: VALUE MODE rounds qty to 4 decimal places in PHP (round($value/$price, 4)),
 * but the DB column was (15,3) so the 4th decimal was being truncated on save.
 *
 * Trade-off: max integer digits drops 12 → 6 (max qty per line: 999,999.9999).
 * For POS line-items this is plenty; no real bill has > 999K units of one item.
 *
 * Scope: ONLY fbr_pos_transaction_items.quantity. NOT touching:
 *   - returned_quantity (still 15,3 — no value-mode use case)
 *   - PRA POS / DI item tables
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fbr_pos_transaction_items', 'quantity')) {
            return;
        }
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE fbr_pos_transaction_items MODIFY COLUMN quantity DECIMAL(10,4) NOT NULL DEFAULT 1');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE fbr_pos_transaction_items ALTER COLUMN quantity TYPE numeric(10,4) USING quantity::numeric(10,4)');
            DB::statement('ALTER TABLE fbr_pos_transaction_items ALTER COLUMN quantity SET DEFAULT 1');
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('fbr_pos_transaction_items', 'quantity')) {
            return;
        }
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE fbr_pos_transaction_items MODIFY COLUMN quantity DECIMAL(15,3) NOT NULL DEFAULT 1');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE fbr_pos_transaction_items ALTER COLUMN quantity TYPE numeric(15,3) USING quantity::numeric(15,3)');
            DB::statement('ALTER TABLE fbr_pos_transaction_items ALTER COLUMN quantity SET DEFAULT 1');
        }
    }
};
