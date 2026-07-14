<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant module plan gating (Jul 2026):
 * Restaurant & Kitchen features (KOT, KDS, Tables, Kitchen Notes, Recipes)
 * are available only on Pro and Unlimited POS plans.
 *
 * Idempotent: hasColumn guard + name-based UPDATE (prod plan ids differ from
 * staging — always match by product_type + name, never by id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pricing_plans', 'restaurant_enabled')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->boolean('restaurant_enabled')->default(false)->after('reports_enabled');
            });
        }

        DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->whereIn('name', ['Pro', 'Unlimited'])
            ->update(['restaurant_enabled' => 1]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('pricing_plans', 'restaurant_enabled')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->dropColumn('restaurant_enabled');
            });
        }
    }
};
