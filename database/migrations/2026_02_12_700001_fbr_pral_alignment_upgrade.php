<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('document_type', 50)->default('Sale Invoice')->after('branch_id');
            $table->string('reference_invoice_number')->nullable()->after('document_type');
            $table->string('buyer_registration_type', 50)->default('Registered')->after('buyer_ntn');
            $table->string('supplier_province', 100)->nullable()->after('buyer_registration_type');
            $table->string('destination_province', 100)->nullable()->after('supplier_province');
            $table->decimal('total_value_excluding_st', 18, 2)->default(0)->after('destination_province');
            $table->decimal('total_sales_tax', 18, 2)->default(0)->after('total_value_excluding_st');
            $table->decimal('wht_rate', 8, 4)->default(0)->after('total_amount');
            $table->decimal('wht_amount', 18, 2)->default(0)->after('wht_rate');
            $table->decimal('net_receivable', 18, 2)->default(0)->after('wht_amount');
            $table->string('fbr_status', 50)->nullable()->after('status');
            $table->string('invoice_date')->nullable()->after('fbr_status');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->boolean('st_withheld_at_source')->default(false)->after('sale_type');
            $table->decimal('petroleum_levy', 18, 2)->nullable()->after('st_withheld_at_source');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('province', 100)->nullable()->after('address');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('invoice_number_prefix', 20)->nullable()->after('province');
            $table->integer('next_invoice_number')->default(1)->after('invoice_number_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'document_type', 'reference_invoice_number', 'buyer_registration_type',
                'supplier_province', 'destination_province', 'total_value_excluding_st',
                'total_sales_tax', 'wht_rate', 'wht_amount', 'net_receivable',
                'fbr_status', 'invoice_date',
            ]);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['st_withheld_at_source', 'petroleum_levy']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('province');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['invoice_number_prefix', 'next_invoice_number']);
        });
    }
};
