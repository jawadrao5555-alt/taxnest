<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 (F4) — Kitchen Display upgrades.
 *
 * Separate kitchen-side lifecycle on restaurant_orders. The existing `status`
 * column drives TABLES + cashier billing and must NOT be touched by kitchen
 * staff — so the KDS gets its own parallel state:
 *
 *   kitchen_status: NULL = new (just sent), 'preparing', 'ready', 'cleared'
 *   kitchen_started_at / kitchen_ready_at / kitchen_cleared_at = audit timestamps
 *
 * 'cleared' removes the order from the KDS board (scan or manual button) but the
 * order itself stays held/preparing for the cashier to settle.
 * Idempotent: hasColumn guards per column (PROD schema drift self-heal pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurant_orders', 'kitchen_status')) {
                $table->string('kitchen_status', 20)->nullable()->after('status');
            }
            if (!Schema::hasColumn('restaurant_orders', 'kitchen_started_at')) {
                $table->timestamp('kitchen_started_at')->nullable()->after('kitchen_status');
            }
            if (!Schema::hasColumn('restaurant_orders', 'kitchen_ready_at')) {
                $table->timestamp('kitchen_ready_at')->nullable()->after('kitchen_started_at');
            }
            if (!Schema::hasColumn('restaurant_orders', 'kitchen_cleared_at')) {
                $table->timestamp('kitchen_cleared_at')->nullable()->after('kitchen_ready_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            foreach (['kitchen_cleared_at', 'kitchen_ready_at', 'kitchen_started_at', 'kitchen_status'] as $col) {
                if (Schema::hasColumn('restaurant_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
