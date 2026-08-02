<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Task 183: PRA POS hold/recall delivery-address parity with the FBR screen
// (Task 170). Held restaurant orders already snapshot order_type; the typed
// delivery address was lost on hold — store it on the HELD row so recall can
// restore it. The final bill keeps its own frozen copy
// (pos_transactions.delivery_address) at pay time, unchanged.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('restaurant_orders', 'delivery_address')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                $table->string('delivery_address', 500)->nullable()->after('customer_phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurant_orders', 'delivery_address')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                $table->dropColumn('delivery_address');
            });
        }
    }
};
