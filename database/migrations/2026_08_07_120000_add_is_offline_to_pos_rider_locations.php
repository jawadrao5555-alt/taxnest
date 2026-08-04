<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rider offline-point stamp (Aug 2026) — adds is_offline boolean.
 *
 * is_offline is set at INSERT time by the server:
 *   true  → recorded_at is 5+ minutes older than server now()
 *             OR the point arrived in an offline batch replay
 *   false → point was recorded and uploaded in near-real-time
 *   NULL  → old row inserted before this migration (pre-stamp era)
 *
 * trail() uses the column when it is non-NULL; for NULL rows (pre-migration)
 * it falls back to the existing created_at − recorded_at heuristic so that
 * historical trails still classify gaps correctly.
 *
 * Idempotent (hasColumn guard) — prod schema drift safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_rider_locations', 'is_offline')) {
            Schema::table('pos_rider_locations', function (Blueprint $table) {
                $table->boolean('is_offline')->nullable()->default(null)
                    ->after('client_ts_ms')
                    ->comment('Stamped at insert: true=offline-buffered, false=live, NULL=pre-migration row (heuristic fallback)');
            });
        }
    }

    public function down(): void
    {
        // Additive only — no destructive down (prod safety).
    }
};
