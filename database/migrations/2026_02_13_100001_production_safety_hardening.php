<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('status');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('fbr_invoice_number', 'invoices_fbr_invoice_number_unique');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(['company_id', 'internal_invoice_number'], 'invoices_company_internal_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_company_internal_number_unique');
            $table->dropUnique('invoices_fbr_invoice_number_unique');
            $table->dropColumn('submitted_at');
        });
    }
};
