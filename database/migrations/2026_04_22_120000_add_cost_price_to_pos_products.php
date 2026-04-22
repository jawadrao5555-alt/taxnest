<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profit + BI engine: add cost_price to pos_products.
 * - Default 0 keeps existing data correct (profit == revenue when cost is 0).
 * - No PK changes, no FK changes, no touch to invoices/PRA/cart.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_products', 'cost_price')) {
                $table->decimal('cost_price', 12, 2)->default(0)->after('price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            if (Schema::hasColumn('pos_products', 'cost_price')) {
                $table->dropColumn('cost_price');
            }
        });
    }
};
