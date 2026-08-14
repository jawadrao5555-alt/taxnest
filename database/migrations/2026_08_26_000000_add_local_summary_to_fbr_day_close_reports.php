<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 691 — FBR mirror of PRA's pos_day_close_reports.local_summary:
 * durable per-close audit of what happened to the day's pending (local)
 * bills — finalize-sweep outcome, per-bill picks, deletes, rider-guarded
 * skips. Idempotent + hasColumn-guarded per the prod schema-drift
 * self-heal convention (safe to re-run on any box).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fbr_day_close_reports')) {
            return;
        }
        if (!Schema::hasColumn('fbr_day_close_reports', 'local_summary')) {
            Schema::table('fbr_day_close_reports', function (Blueprint $table) {
                $table->json('local_summary')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fbr_day_close_reports')
            && Schema::hasColumn('fbr_day_close_reports', 'local_summary')) {
            Schema::table('fbr_day_close_reports', function (Blueprint $table) {
                $table->dropColumn('local_summary');
            });
        }
    }
};
