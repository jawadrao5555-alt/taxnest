<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 596: Z-report stored wastage figures. Task 593 added the wastage line to
 * the day-close SCREEN preview only; the stored pos_day_close_reports rows (and
 * therefore the PDF/thermal prints) had no wastage columns, so print and screen
 * could disagree. Schema-drift-guarded per PROD self-heal convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_day_close_reports', function (Blueprint $table) {
            // No ->after() placement: returns_amount itself is drift-guarded
            // (2026_08_14_030000) and may be absent on a drifted PROD table —
            // a placement reference to a missing column would fail the ALTER.
            if (!Schema::hasColumn('pos_day_close_reports', 'wastage_count')) {
                $table->unsignedInteger('wastage_count')->nullable();
            }
            if (!Schema::hasColumn('pos_day_close_reports', 'wastage_amount')) {
                $table->decimal('wastage_amount', 12, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_day_close_reports', function (Blueprint $table) {
            if (Schema::hasColumn('pos_day_close_reports', 'wastage_count')) {
                $table->dropColumn('wastage_count');
            }
            if (Schema::hasColumn('pos_day_close_reports', 'wastage_amount')) {
                $table->dropColumn('wastage_amount');
            }
        });
    }
};
