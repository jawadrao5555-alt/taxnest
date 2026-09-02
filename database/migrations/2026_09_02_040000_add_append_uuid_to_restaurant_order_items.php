<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waiter "Add Items" replay guard.
 *
 * WHY: the waiter screen now aborts a request that hangs past its timeout, so a
 * tablet on a bad line can retry an append whose FIRST attempt already committed
 * server-side. New holds were already protected by restaurant_orders.hold_uuid;
 * appends had no equivalent, so the retry inserted the same lines again and the
 * kitchen got a second delta ticket for food it was already cooking.
 *
 * One uuid is generated per append ATTEMPT and rides every retry of it, so the
 * column is deliberately NOT unique — a single attempt writes several item rows
 * that legitimately share it. Dedupe is an existence check taken inside the
 * append transaction, which already holds a row lock on the parent order.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('restaurant_order_items')) {
            return;
        }
        if (Schema::hasColumn('restaurant_order_items', 'append_uuid')) {
            return;
        }

        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->string('append_uuid', 64)->nullable()->after('order_id');
            // Lookup is always "this order, this attempt".
            $table->index(['order_id', 'append_uuid'], 'roi_order_append_uuid_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('restaurant_order_items')) {
            return;
        }
        if (!Schema::hasColumn('restaurant_order_items', 'append_uuid')) {
            return;
        }

        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->dropIndex('roi_order_append_uuid_idx');
            $table->dropColumn('append_uuid');
        });
    }
};
