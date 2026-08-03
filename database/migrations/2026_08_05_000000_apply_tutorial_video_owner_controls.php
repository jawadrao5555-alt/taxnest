<?php

use App\Models\TutorialVideo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner controls for task-merged tutorial videos (owner's order, 3 Aug 2026):
 * offline billing video must NOT be published anywhere until the owner turns
 * it on himself, and every new video must be feature-gated to subscriptions
 * that actually include it (restaurant → 'restaurant', riders → 'riders_enabled',
 * deals → 'deals_enabled', rider tracking / custom access / QR menu by slug).
 *
 * Video rows arrive from task-merge migrations authored in isolated
 * environments — and can merge AFTER this migration has already run. So the
 * real enforcement is TutorialVideo::applyOwnerControls(), a once-per-row
 * self-heal (controls_applied flag) that also runs on every tutorials page
 * load. This migration adds the flag column and runs one pass for rows
 * already present. Idempotent + hasColumn guards (prod self-heal convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            return;
        }

        if (!Schema::hasColumn('tutorial_videos', 'controls_applied')) {
            Schema::table('tutorial_videos', function (Blueprint $table) {
                $table->boolean('controls_applied')->default(false)->after('show_public');
            });
        }

        // Guarded pass for rows already merged (e.g. restaurant/riders/deals
        // from task 233). Later-merging rows are caught at page-load time.
        if (Schema::hasColumn('tutorial_videos', 'show_public')) {
            TutorialVideo::applyOwnerControls();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tutorial_videos') && Schema::hasColumn('tutorial_videos', 'controls_applied')) {
            Schema::table('tutorial_videos', function (Blueprint $table) {
                $table->dropColumn('controls_applied');
            });
        }
    }
};
