<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('internal_invoice_number')->nullable()->after('invoice_number');
            $table->string('fbr_invoice_number')->nullable()->after('internal_invoice_number');
            $table->timestamp('fbr_submission_date')->nullable()->after('fbr_invoice_number');
        });

        DB::statement("UPDATE invoices SET internal_invoice_number = invoice_number WHERE internal_invoice_number IS NULL");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['internal_invoice_number', 'fbr_invoice_number', 'fbr_submission_date']);
        });
    }
};
