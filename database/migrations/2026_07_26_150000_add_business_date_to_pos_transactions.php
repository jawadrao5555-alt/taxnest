<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRA POS "business day" (owner rule 26 Jul 2026): restaurants that stay open
 * past midnight close their trading day at 00:00–02:00 AM. Bills created
 * between 00:00 and 05:59 belong to the PREVIOUS day's business while that
 * day is still un-closed. business_date stores that trading-day bucket —
 * created_at (and everything PRA/tax) keeps the real timestamp.
 *
 * Idempotent + re-runnable (prod schema-drift self-heal pattern): the
 * backfill also repairs any rows created in a deploy window where the column
 * existed but old code didn't stamp it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_transactions', 'business_date')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->date('business_date')->nullable()->after('created_by');
                $table->index(['company_id', 'business_date'], 'pos_tx_company_bizdate_idx');
            });
        }

        // Backfill: historical rows take their calendar date (past days are
        // already closed/settled — only NEW bills get the midnight rule).
        DB::table('pos_transactions')
            ->whereNull('business_date')
            ->update(['business_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_transactions', 'business_date')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->dropIndex('pos_tx_company_bizdate_idx');
                $table->dropColumn('business_date');
            });
        }
    }
};
