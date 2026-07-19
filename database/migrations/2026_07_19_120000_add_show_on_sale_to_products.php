<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS sale-screen visibility: adds show_on_sale to the FBR `products`
 * table (mirrors pos_products.show_on_sale on the PRA side). Default TRUE so
 * every existing product stays visible. Idempotent (hasColumn guard) so it is
 * safe to re-run on prod where schema drift has happened before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'show_on_sale')) {
                $table->boolean('show_on_sale')->default(true)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'show_on_sale')) {
                $table->dropColumn('show_on_sale');
            }
        });
    }
};
