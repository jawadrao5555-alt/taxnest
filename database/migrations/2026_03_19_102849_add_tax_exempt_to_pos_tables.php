<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            $table->boolean('is_tax_exempt')->default(false)->after('is_active');
        });

        Schema::table('pos_services', function (Blueprint $table) {
            $table->boolean('is_tax_exempt')->default(false)->after('is_active');
        });

        Schema::table('pos_transaction_items', function (Blueprint $table) {
            $table->boolean('is_tax_exempt')->default(false)->after('subtotal');
            $table->decimal('tax_rate', 8, 2)->default(0)->after('is_tax_exempt');
            $table->decimal('tax_amount', 12, 2)->default(0)->after('tax_rate');
        });

        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->decimal('exempt_amount', 12, 2)->default(0)->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            $table->dropColumn('is_tax_exempt');
        });
        Schema::table('pos_services', function (Blueprint $table) {
            $table->dropColumn('is_tax_exempt');
        });
        Schema::table('pos_transaction_items', function (Blueprint $table) {
            $table->dropColumn(['is_tax_exempt', 'tax_rate', 'tax_amount']);
        });
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropColumn('exempt_amount');
        });
    }
};
