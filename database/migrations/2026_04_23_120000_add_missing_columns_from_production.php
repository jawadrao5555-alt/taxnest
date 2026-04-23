<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema Drift Catch-Up Migration
 * ================================
 * Detected via scripts/schema_drift_scan.php on 2026-04-23.
 *
 * Purpose: Align Laravel migration files with columns that were added
 * directly to production Postgres outside of the migration system.
 *
 * Rules followed:
 *   - All columns nullable (safety)
 *   - No destructive changes
 *   - No type changes on existing columns
 *   - Idempotent: uses hasColumn() guard so migration is safe on any environment
 *
 * Drift columns recovered:
 *   - invoice_items.further_tax  (numeric, nullable, default 0)
 *     Used by: FBR further tax reporting on per-item basis (Schedule 8)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_items') && !Schema::hasColumn('invoice_items', 'further_tax')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                // numeric with default 0 matches existing Postgres prod definition
                // No ->after() — MySQL appends at end; column order doesn't affect correctness.
                $table->decimal('further_tax', 15, 2)->nullable()->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoice_items') && Schema::hasColumn('invoice_items', 'further_tax')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropColumn('further_tax');
            });
        }
    }
};
