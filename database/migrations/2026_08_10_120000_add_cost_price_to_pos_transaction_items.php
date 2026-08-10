<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munafa (profit) freeze for PRA POS — Aug 2026.
 * Snapshot the purchase cost (pos_products.cost_price at sale time) on every
 * sold PRA line so profit history stays correct even when purchase rates
 * change later. Mirrors the FBR POS freeze (Task 416).
 * Idempotent (hasColumn guards) — safe to re-run on cPanel PROD.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_transaction_items', 'cost_price')) {
            Schema::table('pos_transaction_items', function (Blueprint $table) {
                $table->decimal('cost_price', 12, 4)->nullable()->after('unit_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_transaction_items', 'cost_price')) {
            Schema::table('pos_transaction_items', function (Blueprint $table) {
                $table->dropColumn('cost_price');
            });
        }
    }
};
