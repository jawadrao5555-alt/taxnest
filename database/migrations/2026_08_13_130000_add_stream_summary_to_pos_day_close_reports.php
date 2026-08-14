<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 660 (ZFC Z-Report restructure): per-stream figures stored ON the
 * Z-report row — PRA vs Local vs Exempt sale/tax/payment-bucket totals plus
 * exempt item detail. Must be STORED (not recomputed) because the day-close
 * wash can permanently DELETE reporting-OFF finals right after the close —
 * a later recompute from surviving rows would undercount the Local stream
 * (this is also why the old Invoice Summary "Amount" column printed "-").
 *
 * Idempotent + per-column hasColumn guards (prod schema-drift self-heal).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_day_close_reports')) {
            return;
        }
        if (!Schema::hasColumn('pos_day_close_reports', 'stream_summary')) {
            Schema::table('pos_day_close_reports', function (Blueprint $table) {
                $table->json('stream_summary')->nullable()->after('local_summary');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_day_close_reports')
            && Schema::hasColumn('pos_day_close_reports', 'stream_summary')) {
            Schema::table('pos_day_close_reports', function (Blueprint $table) {
                $table->dropColumn('stream_summary');
            });
        }
    }
};
