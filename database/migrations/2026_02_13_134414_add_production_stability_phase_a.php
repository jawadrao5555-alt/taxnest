<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->indexExists('invoices', 'invoices_company_status_date_index')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index(['company_id', 'status', 'invoice_date'], 'invoices_company_status_date_index');
            });
        }

        if (!$this->indexExists('invoice_items', 'invoice_items_hs_code_index')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->index('hs_code', 'invoice_items_hs_code_index');
            });
        }

        if (!$this->indexExists('fbr_logs', 'fbr_logs_invoice_id_status_index')) {
            Schema::table('fbr_logs', function (Blueprint $table) {
                $table->index(['invoice_id', 'status'], 'fbr_logs_invoice_id_status_index');
            });
        }

        if (!$this->indexExists('invoice_items', 'invoice_items_invoice_id_index')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->index('invoice_id', 'invoice_items_invoice_id_index');
            });
        }

        if (!Schema::hasColumn('fbr_logs', 'environment_used')) {
            Schema::table('fbr_logs', function (Blueprint $table) {
                $table->string('environment_used', 20)->nullable();
                $table->string('failure_category', 50)->nullable();
                $table->integer('submission_latency_ms')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_company_status_date_index');
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropIndex('invoice_items_hs_code_index');
            $table->dropIndex('invoice_items_invoice_id_index');
        });
        Schema::table('fbr_logs', function (Blueprint $table) {
            $table->dropIndex('fbr_logs_invoice_id_status_index');
            $table->dropColumn(['environment_used', 'failure_category', 'submission_latency_ms']);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::select("SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $indexName]) ? true : false;
    }
};
