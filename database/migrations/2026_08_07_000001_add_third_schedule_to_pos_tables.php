<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third Schedule flag (Aug 2026) — products whose manufacturer has already
 * paid tax at the retail price level; POS must never add tax again.
 *
 * Column: is_third_schedule (boolean, default false)
 *   pos_products              — master product flag
 *   pos_transaction_items     — billing snapshot (PRA POS)
 *   fbr_pos_transaction_items — billing snapshot (FBR POS)
 *
 * All three use hasColumn guards per prod-schema-drift pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_products') && !Schema::hasColumn('pos_products', 'is_third_schedule')) {
            Schema::table('pos_products', function (Blueprint $table) {
                $table->boolean('is_third_schedule')->default(false)->after('is_tax_exempt');
            });
        }

        if (Schema::hasTable('pos_transaction_items') && !Schema::hasColumn('pos_transaction_items', 'is_third_schedule')) {
            Schema::table('pos_transaction_items', function (Blueprint $table) {
                $table->boolean('is_third_schedule')->default(false)->after('is_tax_exempt');
            });
        }

        if (Schema::hasTable('fbr_pos_transaction_items') && !Schema::hasColumn('fbr_pos_transaction_items', 'is_third_schedule')) {
            Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
                $table->boolean('is_third_schedule')->default(false)->after('is_tax_exempt');
            });
        }

        // FBR Product catalog (products table) — shared DI/FBR
        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'is_third_schedule')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('is_third_schedule')->default(false)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        foreach (['pos_products', 'pos_transaction_items', 'fbr_pos_transaction_items', 'products'] as $tbl) {
            if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, 'is_third_schedule')) {
                Schema::table($tbl, fn (Blueprint $t) => $t->dropColumn('is_third_schedule'));
            }
        }
    }
};
