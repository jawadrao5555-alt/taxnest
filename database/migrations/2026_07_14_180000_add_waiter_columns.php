<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P7 (F6): Waiter tablets — waiters compose orders on a tablet and SEND them to a
// chosen cashier for payment. Reuses restaurant_orders end-to-end:
//   - assigned_cashier_id: which cashier the waiter sent the order to.
//   - source: 'pos' (cashier-created, default) | 'waiter' (tablet-created).
//   - restaurant_order_items.kot_printed_at: delta-KOT — appended items print
//     alone (NULL rows only), already-printed rows never reprint.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('restaurant_orders', 'assigned_cashier_id')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('assigned_cashier_id')->nullable()->after('created_by')->index();
            });
        }
        if (!Schema::hasColumn('restaurant_orders', 'source')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                $table->string('source', 20)->default('pos')->after('assigned_cashier_id');
            });
        }
        if (!Schema::hasColumn('restaurant_order_items', 'kot_printed_at')) {
            Schema::table('restaurant_order_items', function (Blueprint $table) {
                $table->timestamp('kot_printed_at')->nullable()->after('item_discount_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurant_orders', 'assigned_cashier_id')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                $table->dropColumn('assigned_cashier_id');
            });
        }
        if (Schema::hasColumn('restaurant_orders', 'source')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
        if (Schema::hasColumn('restaurant_order_items', 'kot_printed_at')) {
            Schema::table('restaurant_order_items', function (Blueprint $table) {
                $table->dropColumn('kot_printed_at');
            });
        }
    }
};
