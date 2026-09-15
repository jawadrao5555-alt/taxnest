<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Historical filename kept for migration history continuity.
 *
 * Original behaviour appended `service_jobs` to every non-null
 * users.pos_custom_access JSON set. That violated the saved-settings rule and
 * failed Deploy Production for PR #72. The rewrite body is permanently retired:
 * service_jobs must not be silently granted to existing Custom Access sets.
 *
 * See 2026_09_15_013000_service_jobs_custom_access_no_silent_rewrite.php and
 * PosCustomAccessInvariantsTest::NO_BACKFILL_REQUIRED.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intentionally empty — do not rewrite saved Custom Access.
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
