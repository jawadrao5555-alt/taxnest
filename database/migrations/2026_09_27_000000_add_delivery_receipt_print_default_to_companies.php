<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'delivery_receipt_print_on_assign')) {
                // Default stays OFF: delivery customers are normally away from
                // the counter. The owner can opt their shop in from Receipt
                // Settings, while a cashier can still override one delivery.
                $table->boolean('delivery_receipt_print_on_assign')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'delivery_receipt_print_on_assign')) {
                $table->dropColumn('delivery_receipt_print_on_assign');
            }
        });
    }
};