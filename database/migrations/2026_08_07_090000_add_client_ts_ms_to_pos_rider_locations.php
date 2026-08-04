<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rider offline route buffering (Aug 2026) — adds client_ts_ms for dedupe.
 *
 * client_ts_ms = epoch milliseconds from the phone clock, stored verbatim.
 * The server already converts `at` to recorded_at (PKT); this column is the
 * raw client value used solely as a per-rider replay-dedupe key.
 *
 * Unique index (rider_id, client_ts_ms) — MySQL/SQLite both allow multiple
 * NULLs in a unique index, so old APKs that never send `at` are unaffected.
 *
 * Idempotent (hasColumn + try-create-swallow-duplicate) — prod schema drift
 * safe.  We intentionally avoid getDoctrineSchemaManager / Schema::getIndexes
 * because both call the Doctrine layer which is not available on SQLite in
 * this Laravel version.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_rider_locations', 'client_ts_ms')) {
            Schema::table('pos_rider_locations', function (Blueprint $table) {
                $table->bigInteger('client_ts_ms')->nullable()->unsigned()
                    ->after('recorded_at')
                    ->comment('Client epoch-ms; NULL for old APKs without at');
            });
        }

        // Try to create the unique index; swallow "already exists" quietly so
        // the migration is safe to re-run on prod where the column was added
        // manually.  Any other exception is re-thrown.
        try {
            Schema::table('pos_rider_locations', function (Blueprint $table) {
                $table->unique(['rider_id', 'client_ts_ms'], 'prl_rider_client_ts_dedup');
            });
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            // MySQL: "Duplicate key name", SQLite: "index ... already exists"
            if (!str_contains($msg, 'duplicate key') &&
                !str_contains($msg, 'already exists') &&
                !str_contains($msg, 'prl_rider_client_ts_dedup')) {
                throw $e;
            }
            // Index already exists — idempotent, continue.
        }
    }

    public function down(): void
    {
        // Additive only — no destructive down (prod safety).
    }
};
