<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->string('item_discount_type', 20)->nullable()->after('is_tax_exempt');
            $table->decimal('item_discount_value', 10, 2)->default(0)->after('item_discount_type');
            $table->decimal('item_discount_amount', 10, 2)->default(0)->after('item_discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->dropColumn(['item_discount_type', 'item_discount_value', 'item_discount_amount']);
        });
    }
};
