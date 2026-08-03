<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner controls for tutorial videos (3 Aug 2026):
 *  - product:          folder grouping on the public page ("nestpos" for now,
 *                      ready for fbrpos / di video sets later).
 *  - required_feature: plan-gate key (PosFeatureService::PLAN_GATES column) —
 *                      inside /pos/tutorials a company only sees videos its
 *                      subscription actually includes. NULL = everyone.
 *  - show_public:      landing-page visibility, super-admin controlled.
 *                      Default FALSE so any video row added later (e.g. by a
 *                      task merge) stays OFF the public site until the super
 *                      admin explicitly allows it. Existing rows are turned on
 *                      below because the owner has already approved them.
 *
 * Idempotent + per-column hasColumn guards (prod self-heal convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            return; // created by the earlier migration; nothing to alter yet
        }

        if (!Schema::hasColumn('tutorial_videos', 'product')) {
            Schema::table('tutorial_videos', function (Blueprint $table) {
                $table->string('product', 30)->default('nestpos')->after('slug');
            });
        }
        if (!Schema::hasColumn('tutorial_videos', 'required_feature')) {
            Schema::table('tutorial_videos', function (Blueprint $table) {
                $table->string('required_feature', 50)->nullable()->after('category');
            });
        }
        if (!Schema::hasColumn('tutorial_videos', 'show_public')) {
            Schema::table('tutorial_videos', function (Blueprint $table) {
                $table->boolean('show_public')->default(false)->after('is_published');
            });

            // Only on first add: flip ONLY the owner-approved 2 Aug 2026 launch
            // set to public. Explicit allowlist (not a blanket update) so that
            // on a fresh machine any video row inserted by an earlier-timestamp
            // migration (e.g. task-merged restaurant/riders/offline videos)
            // stays show_public=0 until the super admin allows it — the owner
            // ordered that the offline video must never auto-publish.
            DB::table('tutorial_videos')->whereIn('slug', [
                'nestpos-taaruf', 'account-banana', 'sale-screen-tutorial',
                'customers-add-import-export', 'pos-customize', 'app-install-pwa',
                'madadgar-raabta', 'barcode-scan-search', 'discount-dena',
                'provisional-bills', 'bills-history', 'day-opening',
                'staff-hazri', 'desktop-agent-printing', 'package-billing',
            ])->update(['show_public' => 1]);
        }

        // Feature gates for the launch set (idempotent, re-runnable).
        DB::table('tutorial_videos')->where('slug', 'staff-hazri')
            ->update(['required_feature' => 'hazri_enabled']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            return;
        }
        Schema::table('tutorial_videos', function (Blueprint $table) {
            foreach (['product', 'required_feature', 'show_public'] as $col) {
                if (Schema::hasColumn('tutorial_videos', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
