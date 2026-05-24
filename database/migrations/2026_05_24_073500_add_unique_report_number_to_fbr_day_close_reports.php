<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Adds unique(company_id, report_number) to fbr_day_close_reports.
 *
 * Purpose: makes the retry-on-23000 loop in FbrPosController::performDayClose()
 * actually trigger when two concurrent closes for DIFFERENT past dates compute
 * the same MAX(report_number)+1. Without this constraint, duplicates can silently
 * land in the table — a fiscal-audit integrity risk.
 *
 * Defensive: scans for pre-existing duplicates first and re-numbers them with
 * a "-DUP{id}" suffix before adding the constraint. This way the migration
 * never fails on legacy data, and the operator can clean up afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Defensive cleanup: rename any pre-existing duplicate (company_id, report_number)
        //    so the unique index can be added without failure.
        $duplicates = DB::table('fbr_day_close_reports')
            ->select('company_id', 'report_number', DB::raw('COUNT(*) as c'))
            ->groupBy('company_id', 'report_number')
            ->having('c', '>', 1)
            ->get();

        foreach ($duplicates as $dup) {
            // Keep the oldest row untouched; rename all newer ones.
            $rows = DB::table('fbr_day_close_reports')
                ->where('company_id', $dup->company_id)
                ->where('report_number', $dup->report_number)
                ->orderBy('id')
                ->get();
            foreach ($rows->slice(1) as $row) {
                DB::table('fbr_day_close_reports')
                    ->where('id', $row->id)
                    ->update(['report_number' => $row->report_number . '-DUP' . $row->id]);
            }
        }

        Schema::table('fbr_day_close_reports', function (Blueprint $table) {
            $table->unique(['company_id', 'report_number'], 'fbr_day_close_company_report_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fbr_day_close_reports', function (Blueprint $table) {
            $table->dropUnique('fbr_day_close_company_report_unique');
        });
    }
};
