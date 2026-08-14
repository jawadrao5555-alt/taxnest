<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 682: returns audit detail snapshot on the stored Z-report.
 *
 * The day-close page's returns audit list (Task 678) is built from a LIVE
 * query — after day close, LOCAL return rows get archived (or permanently
 * deleted under the 'delete' wash policy), so past days' audit lists could
 * lose rows. Snapshot the detail (invoice, parent, amount, processed-by)
 * on the report at close time — same pattern as rider_summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_day_close_reports', 'returns_detail')) {
            Schema::table('pos_day_close_reports', function (Blueprint $table) {
                $table->json('returns_detail')->nullable()->after('rider_summary');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_day_close_reports', 'returns_detail')) {
            Schema::table('pos_day_close_reports', function (Blueprint $table) {
                $table->dropColumn('returns_detail');
            });
        }
    }
};
