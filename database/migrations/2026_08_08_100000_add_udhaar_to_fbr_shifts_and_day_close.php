<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds udhaar (credit/khata) as a first-class payment bucket:
 *  - fbr_day_close_reports.udhaar_amount — frozen in the snapshot at close time
 *  - fbr_pos_shifts.total_udhaar        — running counter incremented in store()
 *
 * Idempotent: every column addition is hasColumn-guarded so the migration is
 * safe to re-run on prod (migrate --force convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── fbr_day_close_reports ────────────────────────────────────────────
        if (Schema::hasTable('fbr_day_close_reports') && !Schema::hasColumn('fbr_day_close_reports', 'udhaar_amount')) {
            Schema::table('fbr_day_close_reports', function (Blueprint $table) {
                // Insert after other_amount for logical grouping.
                $table->decimal('udhaar_amount', 15, 2)->default(0)->after('other_amount');
            });
        }

        // ── fbr_pos_shifts ───────────────────────────────────────────────────
        if (Schema::hasTable('fbr_pos_shifts') && !Schema::hasColumn('fbr_pos_shifts', 'total_udhaar')) {
            Schema::table('fbr_pos_shifts', function (Blueprint $table) {
                $table->decimal('total_udhaar', 15, 2)->default(0)->after('total_other');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fbr_day_close_reports') && Schema::hasColumn('fbr_day_close_reports', 'udhaar_amount')) {
            Schema::table('fbr_day_close_reports', function (Blueprint $table) {
                $table->dropColumn('udhaar_amount');
            });
        }
        if (Schema::hasTable('fbr_pos_shifts') && Schema::hasColumn('fbr_pos_shifts', 'total_udhaar')) {
            Schema::table('fbr_pos_shifts', function (Blueprint $table) {
                $table->dropColumn('total_udhaar');
            });
        }
    }
};
