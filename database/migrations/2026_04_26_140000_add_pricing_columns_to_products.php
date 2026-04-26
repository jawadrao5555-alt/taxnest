<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 PHASE 3 — Smart Pricing
 *
 * Adds optional cost_price, suggested_price, pricing_strategy to products.
 * Idempotent (uses Schema::hasColumn guards).
 * Reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'cost_price')) {
                $table->decimal('cost_price', 10, 2)->nullable()->after('default_price')
                    ->comment('Phase 3: optional cost-of-goods for margin calc');
            }
            if (! Schema::hasColumn('products', 'suggested_price')) {
                $table->decimal('suggested_price', 10, 2)->nullable()->after('cost_price')
                    ->comment('Phase 3: PricingService output (cached)');
            }
            if (! Schema::hasColumn('products', 'pricing_strategy')) {
                $table->string('pricing_strategy', 20)->default('manual')->after('suggested_price')
                    ->comment('Phase 3: manual | margin | dynamic');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['pricing_strategy', 'suggested_price', 'cost_price'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
