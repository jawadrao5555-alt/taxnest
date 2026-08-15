<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 767 — Notify shops with centering STILL on that they should verify
 * their KOT printout.
 *
 * Task 761 (2026_08_28_000000_reset_accidental_kot_center) reset accidental
 * kot_align_center=true rows to NULL. Companies still at explicit TRUE after
 * that pass had also configured compact mode or a left margin — likely a
 * deliberate choice, but Center prints BLANK slips on A4-default Windows
 * print queues, so they must verify their printout. The kitchen-settings
 * page already warns them (Task 761 blade change) — but only if they visit.
 *
 * This migration stamps kot_center_notice_at for that residual set. The POS
 * layout (pos-app.blade.php) shows admins/managers of stamped companies a
 * one-time banner linking straight to Kitchen Settings. The stamp clears
 * when they open/save Kitchen Settings or dismiss the banner.
 *
 * POS product only: for fbrpos companies kot_align_center is the RECEIPT
 * position by design (Task 718) and there is no kitchen-settings page.
 *
 * Idempotent: column-add is hasColumn-guarded; the stamp only touches rows
 * still NULL, so a re-run never re-flags a dismissed company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'kot_center_notice_at')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->timestamp('kot_center_notice_at')->nullable()->default(null);
            });
        }

        if (!Schema::hasColumn('companies', 'kot_align_center')) {
            return; // schema drift — nothing to flag.
        }

        DB::table('companies')
            ->where('product_type', 'pos')
            ->where('kot_align_center', true)
            ->whereNull('kot_center_notice_at')
            ->update(['kot_center_notice_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'kot_center_notice_at')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('kot_center_notice_at');
            });
        }
    }
};
