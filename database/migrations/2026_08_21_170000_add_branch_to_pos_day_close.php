<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch day close (Task 1360).
 *
 * The day-close PREVIEW has been branch-filtered since multi-branch v1, but the
 * SAVED Z-report was company-wide — Gulberg's cashier saw Rs 770 on screen and
 * froze Rs 1,870 (both branches) into the report, then that close archived the
 * OTHER branch's local bills too. Day close is now scoped to the branch it was
 * run on: figures, wash, report number and opening cash all follow one scope.
 *
 * branch_id is NOT NULL DEFAULT 0 on purpose (the rest of the schema uses
 * nullable branch_id). 0 = "no branch / company-wide" — the state every
 * existing row and every branch-less shop is in. A nullable column would make
 * the composite unique index useless for exactly those rows (MySQL and SQLite
 * both treat NULLs as distinct), so two simultaneous closes of a branch-less
 * shop could each create a Z-report and wash the day twice. With 0 the
 * "one close per day per scope" guarantee survives on both engines.
 *
 * Idempotent (hasColumn / try-catch guards) — prod runs migrate --force and
 * carries known schema drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_day_close_reports') && !Schema::hasColumn('pos_day_close_reports', 'branch_id')) {
            Schema::table('pos_day_close_reports', function (Blueprint $t) {
                $t->unsignedBigInteger('branch_id')->default(0)->after('company_id');
            });
            // Old guarantee: one report per company per date. New: per SCOPE
            // (branch) per date — the old index would refuse the second
            // branch's close of the same day.
            $this->swapUnique(
                'pos_day_close_reports',
                'pos_day_close_reports_company_id_report_date_unique',
                ['company_id', 'branch_id', 'report_date'],
                'pos_dcr_company_branch_date_unique'
            );
        }

        if (Schema::hasTable('pos_day_openings') && !Schema::hasColumn('pos_day_openings', 'branch_id')) {
            Schema::table('pos_day_openings', function (Blueprint $t) {
                $t->unsignedBigInteger('branch_id')->default(0)->after('company_id');
            });
            // Opening cash is the drawer's morning float — each branch counts
            // its own drawer, so it keys on the branch exactly like the close
            // that consumes it.
            $this->swapUnique(
                'pos_day_openings',
                'pos_day_openings_company_date_unique',
                ['company_id', 'branch_id', 'business_date'],
                'pos_day_openings_company_branch_date_unique'
            );
        }
    }

    /**
     * Replace a unique index with a wider one. The new index is a superset of
     * the old, so creating it can never fail on existing data; dropping the old
     * one is wrapped because prod drift may have lost it already.
     */
    private function swapUnique(string $table, string $oldIndex, array $columns, string $newIndex): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($oldIndex) {
                $t->dropUnique($oldIndex);
            });
        } catch (\Throwable $e) {
            // index already gone (or never existed on this box) — the new one
            // below is what matters.
        }
        try {
            Schema::table($table, function (Blueprint $t) use ($columns, $newIndex) {
                $t->unique($columns, $newIndex);
            });
        } catch (\Throwable $e) {
            // already present from a previous partial run
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_day_close_reports') && Schema::hasColumn('pos_day_close_reports', 'branch_id')) {
            $this->swapUnique(
                'pos_day_close_reports',
                'pos_dcr_company_branch_date_unique',
                ['company_id', 'report_date'],
                'pos_day_close_reports_company_id_report_date_unique'
            );
            Schema::table('pos_day_close_reports', function (Blueprint $t) {
                $t->dropColumn('branch_id');
            });
        }

        if (Schema::hasTable('pos_day_openings') && Schema::hasColumn('pos_day_openings', 'branch_id')) {
            $this->swapUnique(
                'pos_day_openings',
                'pos_day_openings_company_branch_date_unique',
                ['company_id', 'business_date'],
                'pos_day_openings_company_date_unique'
            );
            Schema::table('pos_day_openings', function (Blueprint $t) {
                $t->dropColumn('branch_id');
            });
        }
    }
};
