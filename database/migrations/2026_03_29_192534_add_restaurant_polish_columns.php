<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'manager_override_pin')) {
                $table->string('manager_override_pin', 255)->nullable();
            }
            if (!Schema::hasColumn('companies', 'cashier_discount_limit')) {
                $table->decimal('cashier_discount_limit', 5, 2)->default(10);
            }
            if (!Schema::hasColumn('companies', 'manager_discount_limit')) {
                $table->decimal('manager_discount_limit', 5, 2)->default(50);
            }
        });

        Schema::table('pos_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_transactions', 'receipt_printed_at')) {
                $table->timestamp('receipt_printed_at')->nullable();
            }
            if (!Schema::hasColumn('pos_transactions', 'reprint_count')) {
                $table->unsignedSmallInteger('reprint_count')->default(0);
            }
        });

        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurant_orders', 'estimated_cost')) {
                $table->decimal('estimated_cost', 10, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['manager_override_pin', 'cashier_discount_limit', 'manager_discount_limit']);
        });
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropColumn(['receipt_printed_at', 'reprint_count']);
        });
        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->dropColumn(['estimated_cost']);
        });
    }
};
