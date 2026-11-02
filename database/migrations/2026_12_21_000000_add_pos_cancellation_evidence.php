<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('restaurant_orders') && !Schema::hasColumn('restaurant_orders', 'cancellation_reason')) {
            Schema::table('restaurant_orders', fn (Blueprint $table) => $table->string('cancellation_reason', 500)->nullable());
        }
        if (Schema::hasTable('restaurant_order_items') && !Schema::hasColumn('restaurant_order_items', 'prepared_quantity')) {
            Schema::table('restaurant_order_items', fn (Blueprint $table) => $table->decimal('prepared_quantity', 12, 3)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurant_order_items', 'prepared_quantity')) {
            Schema::table('restaurant_order_items', fn (Blueprint $table) => $table->dropColumn('prepared_quantity'));
        }
        if (Schema::hasColumn('restaurant_orders', 'cancellation_reason')) {
            Schema::table('restaurant_orders', fn (Blueprint $table) => $table->dropColumn('cancellation_reason'));
        }
    }
};
