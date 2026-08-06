<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munafa (profit) report — Aug 2026.
 * Snapshot the purchase cost (avg_purchase_price at sale time) on every sold
 * line so profit history stays correct even when purchase rates change later.
 * Idempotent (hasColumn guards) — safe to re-run on cPanel PROD.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fbr_pos_transaction_items', 'cost_price')) {
            Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
                $table->decimal('cost_price', 12, 4)->nullable()->after('unit_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fbr_pos_transaction_items', 'cost_price')) {
            Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
                $table->dropColumn('cost_price');
            });
        }
    }
};
