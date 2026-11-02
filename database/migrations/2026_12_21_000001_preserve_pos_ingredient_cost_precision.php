<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ingredients', 'cost_per_unit')) {
            Schema::table('ingredients', fn (Blueprint $table) => $table->decimal('cost_per_unit', 18, 6)->default(0)->change());
        }
        if (Schema::hasTable('recipe_consumptions') && !Schema::hasColumn('recipe_consumptions', 'unit_cost')) {
            Schema::table('recipe_consumptions', fn (Blueprint $table) => $table->decimal('unit_cost', 18, 6)->nullable());
        }
        if (Schema::hasTable('pos_stock_in_lines') && !Schema::hasColumn('pos_stock_in_lines', 'unit_snapshot')) {
            Schema::table('pos_stock_in_lines', fn (Blueprint $table) => $table->json('unit_snapshot')->nullable());
        }
    }

    public function down(): void
    {
        // Precision is intentionally retained: rolling back must not destroy saved costs.
        if (Schema::hasColumn('recipe_consumptions', 'unit_cost')) {
            Schema::table('recipe_consumptions', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
        }
        // Retain receipt unit evidence as well; dropping it would lose the input meaning.
    }
};
