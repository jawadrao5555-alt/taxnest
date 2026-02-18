<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!$this->hasIndex('invoices', 'invoices_status_index')) {
                $table->index('status');
            }
            if (!$this->hasIndex('invoices', 'invoices_company_id_status_index')) {
                $table->index(['company_id', 'status']);
            }
            if (!$this->hasIndex('invoices', 'invoices_company_id_created_at_index')) {
                $table->index(['company_id', 'created_at']);
            }
            if (!$this->hasIndex('invoices', 'invoices_created_at_index')) {
                $table->index('created_at');
            }
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            if (!$this->hasIndex('audit_logs', 'audit_logs_created_at_index')) {
                $table->index('created_at');
            }
            if (!$this->hasIndex('audit_logs', 'audit_logs_company_id_index')) {
                $table->index('company_id');
            }
        });

        Schema::table('fbr_logs', function (Blueprint $table) {
            if (!$this->hasIndex('fbr_logs', 'fbr_logs_status_index')) {
                $table->index('status');
            }
        });

        Schema::table('anomaly_logs', function (Blueprint $table) {
            if (!$this->hasIndex('anomaly_logs', 'anomaly_logs_resolved_index')) {
                $table->index('resolved');
            }
        });

        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table) {
                if (!$this->hasIndex('companies', 'companies_company_status_index')) {
                    $table->index('company_status');
                }
                if (!$this->hasIndex('companies', 'companies_compliance_score_index')) {
                    $table->index('compliance_score');
                }
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return collect(\Illuminate\Support\Facades\DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $indexName]
        ))->isNotEmpty();
    }

    public function down(): void
    {
        //
    }
};
