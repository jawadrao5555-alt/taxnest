<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 607 — FBR Z-report return netting: returns detail columns on the
 * stored day-close report (FBR mirror of the PRA Task 570 columns).
 * Idempotent per-column hasColumn guards (prod schema-drift self-heal
 * convention — safe to re-run on boxes where a column already exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fbr_day_close_reports')) {
            return;
        }
        if (!Schema::hasColumn('fbr_day_close_reports', 'returns_count')) {
            Schema::table('fbr_day_close_reports', function (Blueprint $t) {
                $t->integer('returns_count')->default(0);
            });
        }
        if (!Schema::hasColumn('fbr_day_close_reports', 'returns_amount')) {
            Schema::table('fbr_day_close_reports', function (Blueprint $t) {
                $t->decimal('returns_amount', 14, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('fbr_day_close_reports')) {
            return;
        }
        if (Schema::hasColumn('fbr_day_close_reports', 'returns_count')) {
            Schema::table('fbr_day_close_reports', fn (Blueprint $t) => $t->dropColumn('returns_count'));
        }
        if (Schema::hasColumn('fbr_day_close_reports', 'returns_amount')) {
            Schema::table('fbr_day_close_reports', fn (Blueprint $t) => $t->dropColumn('returns_amount'));
        }
    }
};
