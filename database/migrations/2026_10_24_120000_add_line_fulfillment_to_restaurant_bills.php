<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['restaurant_order_items', 'pos_transaction_items'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                if (!Schema::hasColumn($name, 'fulfillment')) $table->string('fulfillment', 16)->nullable();
                if (!Schema::hasColumn($name, 'line_uid')) $table->string('line_uid', 100)->nullable();
            });
        }
        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurant_orders', 'delivery_collection_mode')) $table->string('delivery_collection_mode', 16)->nullable();
        });
        Schema::table('pos_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_transactions', 'has_delivery_items')) $table->boolean('has_delivery_items')->default(false);
            // NULL means the unchanged legacy whole-bill rider collection.
            if (!Schema::hasColumn('pos_transactions', 'rider_collection_amount')) $table->decimal('rider_collection_amount', 14, 2)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['restaurant_order_items', 'pos_transaction_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['fulfillment', 'line_uid']));
        }
        Schema::table('restaurant_orders', fn (Blueprint $table) => $table->dropColumn('delivery_collection_mode'));
        Schema::table('pos_transactions', fn (Blueprint $table) => $table->dropColumn(['has_delivery_items', 'rider_collection_amount']));
    }
};
