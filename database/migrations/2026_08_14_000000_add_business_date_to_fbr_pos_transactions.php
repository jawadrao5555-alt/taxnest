<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 492 — FBR POS business day (mirror of PRA Task 26 Jul 2026 rule).
 *
 * A bill created between 00:00 and the company's day-close cutoff (default
 * 06:00) belongs to the PREVIOUS day's trading day while that day is still
 * un-closed. business_date stores that trading-day bucket for
 * fbr_pos_transactions — day-close, Z-report, sales reports and dashboard
 * "today" group on it. FBR / tax reporting ALWAYS keeps real created_at.
 *
 * Backfill: existing rows get DATE(created_at) — history stays exactly as
 * the shop has already seen and closed it; the cutoff rule only shapes rows
 * created from now on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fbr_pos_transactions', 'business_date')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->date('business_date')->nullable()->after('created_by');
                $table->index(['company_id', 'business_date'], 'fbr_pos_tx_company_bizdate_idx');
            });
        }

        // Idempotent backfill — safe to re-run (only touches NULL rows).
        DB::table('fbr_pos_transactions')
            ->whereNull('business_date')
            ->update(['business_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('fbr_pos_transactions', 'business_date')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->dropIndex('fbr_pos_tx_company_bizdate_idx');
                $table->dropColumn('business_date');
            });
        }
    }
};
