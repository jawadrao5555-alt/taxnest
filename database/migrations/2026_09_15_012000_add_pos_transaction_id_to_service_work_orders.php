<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a completed service work order to at most one NestPOS fiscal sale.
 * Operational job_number (SAL-######) stays separate from invoice_number (P/L).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_service_work_orders')) {
            return;
        }
        if (! Schema::hasColumn('pos_service_work_orders', 'pos_transaction_id')) {
            Schema::table('pos_service_work_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('pos_transaction_id')->nullable()->after('completed_at');
                $table->unique('pos_transaction_id', 'pos_service_job_txn_uq');
                $table->index(['company_id', 'pos_transaction_id'], 'pos_service_job_company_txn_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_service_work_orders')) {
            return;
        }
        if (Schema::hasColumn('pos_service_work_orders', 'pos_transaction_id')) {
            Schema::table('pos_service_work_orders', function (Blueprint $table) {
                $table->dropUnique('pos_service_job_txn_uq');
                $table->dropIndex('pos_service_job_company_txn_idx');
                $table->dropColumn('pos_transaction_id');
            });
        }
    }
};
