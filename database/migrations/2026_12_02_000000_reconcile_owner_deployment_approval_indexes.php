<?php

use App\Support\OwnerDeploymentApprovalSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * For environments that already recorded 2026_12_01_* (so that file will
 * never run again), including non-empty tables that #48's create migration
 * refuses to rewrite: add the provenance unique / repo lookup indexes only
 * when an equivalent index is missing. Never drop the table, rows, or a
 * working index that already covers the same columns (even under a legacy name).
 * Short names match 2026_12_01 (odpr_prov_receipt_hash_uidx / odpr_repo_pr_sha_idx).
 */
return new class extends Migration {
    public function up(): void
    {
        OwnerDeploymentApprovalSchema::ensureIndexes();
    }

    public function down(): void
    {
        // Intentionally empty: rolling this back must not drop approval rows
        // or remove a unique/lookup index that production relies on.
    }
};
