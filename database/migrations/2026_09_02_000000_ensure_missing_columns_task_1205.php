<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1205 — idempotent ensure-columns self-heal migration.
 *
 * Three column families confirmed missing on cPanel PROD via laravel.log
 * (production.ERROR / Column not found):
 *
 *  1. pos_day_close_reports.business_date
 *     "select `business_date` … from `pos_day_close_reports` where `company_id` = 28"
 *     → 500 on day-close history for that company.
 *
 *  2. audit_logs.model_type / model_id
 *     "insert into `audit_logs` … `model_type`, `model_id` …"
 *     → audit INSERT silently fails (pos_billing_scope_set, platform-support).
 *
 *  3. companies.agent_update_status
 *     "select … `agent_update_status`, `agent_update_error` from `companies`"
 *     → admin agent-overview SELECT 500s; agent_update_error already on prod.
 *
 * Per prod-schema-drift-selfheal.md: EACH column has its OWN hasColumn guard
 * so a partially-applied version never silently skips siblings.
 * down() is also guarded — safe to re-run in both directions.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. pos_day_close_reports.business_date
        if (!Schema::hasColumn('pos_day_close_reports', 'business_date')) {
            Schema::table('pos_day_close_reports', function (Blueprint $table) {
                // Nullable date; mirrors pos_transactions.business_date convention.
                $table->date('business_date')->nullable()->after('report_date');
            });
        }

        // 2. audit_logs.model_type / model_id
        //    (older code used these names; ensure they exist so any surviving
        //    INSERT path doesn't 500)
        if (!Schema::hasColumn('audit_logs', 'model_type')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->string('model_type')->nullable()->after('action');
            });
        }
        if (!Schema::hasColumn('audit_logs', 'model_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('model_id')->nullable()->after('model_type');
            });
        }

        // 3. companies.agent_update_status
        if (!Schema::hasColumn('companies', 'agent_update_status')) {
            Schema::table('companies', function (Blueprint $table) {
                // Short status string (e.g. 'pending', 'ok', 'failed').
                // Positioned after agent_update_at which the Aug-30 migration added.
                $table->string('agent_update_status', 40)->nullable()->after('agent_update_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_day_close_reports', 'business_date')) {
            Schema::table('pos_day_close_reports', function (Blueprint $table) {
                $table->dropColumn('business_date');
            });
        }

        if (Schema::hasColumn('audit_logs', 'model_type')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropColumn('model_type');
            });
        }
        if (Schema::hasColumn('audit_logs', 'model_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropColumn('model_id');
            });
        }

        if (Schema::hasColumn('companies', 'agent_update_status')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('agent_update_status');
            });
        }
    }
};
