<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Policy correction for service_jobs Custom Access (Sep 2026 hotfix).
 *
 * 2026_09_15_011000 previously appended `service_jobs` to every non-null
 * users.pos_custom_access set. That silently expanded saved permissions and
 * failed the production settings-regression guard for shops that never opted
 * into work-order boards.
 *
 * Going forward:
 *  - Existing saved Custom Access sets must remain byte-stable across category
 *    / work-order migrations.
 *  - `service_jobs` is granted only via new-company category defaults (NULL
 *    Custom Access → role defaults + feature relevance) or an explicit Team
 *    Custom Access edit by owner/admin.
 *
 * This migration is intentionally a no-op for data. It exists so environments
 * that already ran 011000 keep a clear audit trail of the corrected policy,
 * and so fresh installs never re-introduce a global rewrite here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No data rewrite. See PosCustomAccessInvariantsTest::NO_BACKFILL_REQUIRED.
    }

    public function down(): void
    {
        // No data rewrite.
    }
};
