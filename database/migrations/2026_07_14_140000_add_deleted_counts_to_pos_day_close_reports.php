<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F1 follow-up (architect review): day-close DELETE policy hard-deletes local bills,
 * which would otherwise vanish from the monthly bill-quota count (PlanLimitService)
 * and silently disappear from the historical Z-report PDF. Persist per-report
 * deleted counts so (a) deleted reporting-OFF finals still consume quota and
 * (b) the PDF can state "N bills deleted per policy".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_day_close_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_day_close_reports', 'deleted_final_count')) {
                $table->unsignedInteger('deleted_final_count')->default(0);
            }
            if (!Schema::hasColumn('pos_day_close_reports', 'deleted_provisional_count')) {
                $table->unsignedInteger('deleted_provisional_count')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_day_close_reports', function (Blueprint $table) {
            if (Schema::hasColumn('pos_day_close_reports', 'deleted_final_count')) {
                $table->dropColumn('deleted_final_count');
            }
            if (Schema::hasColumn('pos_day_close_reports', 'deleted_provisional_count')) {
                $table->dropColumn('deleted_provisional_count');
            }
        });
    }
};
