<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 541: FBR day-close rider cash summary (mirror of the PRA
 * pos_day_close_reports.rider_summary column, Jul 2026). Idempotent —
 * hasColumn-guarded so prod drift / re-runs are safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fbr_day_close_reports')) {
            return;
        }
        Schema::table('fbr_day_close_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('fbr_day_close_reports', 'rider_summary')) {
                $table->text('rider_summary')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('fbr_day_close_reports') && Schema::hasColumn('fbr_day_close_reports', 'rider_summary')) {
            Schema::table('fbr_day_close_reports', function (Blueprint $table) {
                $table->dropColumn('rider_summary');
            });
        }
    }
};
