<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T006 — vendor-requested manual cart lines (Restaurant POS Enter-fallback when no product matches).
 * Manual lines carry item_type='manual' and item_id=NULL, so the foreign-ish item_id column must allow
 * NULL on both restaurant_order_items and pos_transaction_items. No FK constraint exists on either
 * column (they're polymorphic — product OR service OR now manual), so dropping NOT NULL is safe.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable()->change();
        });
        Schema::table('pos_transaction_items', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Backfill stray NULL manual rows to 0 sentinel before re-tightening, otherwise the
        // NOT NULL constraint re-add will fail on existing data.
        \DB::table('restaurant_order_items')->whereNull('item_id')->update(['item_id' => 0]);
        \DB::table('pos_transaction_items')->whereNull('item_id')->update(['item_id' => 0]);
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable(false)->change();
        });
        Schema::table('pos_transaction_items', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable(false)->change();
        });
    }
};
