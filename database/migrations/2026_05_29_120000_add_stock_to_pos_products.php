<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory visibility for PRA POS products.
 * - stock_quantity nullable: NULL = "not tracked" (no badge); 0+ = tracked count.
 * - low_stock_threshold default 10: alert when stock_quantity <= threshold.
 * Additive only — no FK/PK changes, no touch to invoices/cart/PRA.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_products', 'stock_quantity')) {
                $table->integer('stock_quantity')->nullable()->after('cost_price');
            }
            if (!Schema::hasColumn('pos_products', 'low_stock_threshold')) {
                $table->integer('low_stock_threshold')->default(10)->after('stock_quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            foreach (['stock_quantity', 'low_stock_threshold'] as $col) {
                if (Schema::hasColumn('pos_products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
