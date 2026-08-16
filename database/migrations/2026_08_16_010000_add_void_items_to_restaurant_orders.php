<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 841 (16 Aug 2026): KDS void-items column.
 *
 * When a cashier recalls + re-holds an order and removes/decreases a dish
 * that already reached the kitchen (printed KOT), the void slip goes to the
 * kitchen printer. Shops that run a Kitchen Display Screen instead of — or
 * alongside — a printer never saw the cancellation on the board.
 *
 * void_items (TEXT/JSON, nullable): the same array the void slip prints
 * [{ item_type, item_id, item_name, notes, qty }, …]. Persisted so the KDS
 * live-orders poll can surface a "CANCELLED" badge on the card until the cook
 * acknowledges it. Cleared to NULL when a fresh re-hold produces no new voids
 * (idempotent — a second recall with no removals replaces with null, not
 * with the stale previous void list).
 *
 * Idempotent + hasColumn guard (prod schema-drift memory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurant_orders', 'void_items')) {
                $table->text('void_items')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_orders', 'void_items')) {
                $table->dropColumn('void_items');
            }
        });
    }
};
