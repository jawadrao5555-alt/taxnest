<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task #1115 (Aug 2026) — per-company rider tracking thresholds.
 *
 * rider_idle_minutes  : minutes stationary-with-open-bills before amber "ruka hua" badge (default 15)
 * rider_silent_minutes: minutes with no GPS upload while on duty before red "GPS/net band" badge (default 10)
 * rider_auto_off_hour : hour (0–8, app TZ) at which duty auto-ends each night; default 3 (3 AM)
 *                       Late-night/sehri restaurants can raise this to 6 or higher.
 *
 * NULL = use the system default constant (backward-compatible).
 * Idempotent (hasColumn guard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'rider_idle_minutes')) {
                $table->unsignedTinyInteger('rider_idle_minutes')->nullable()
                    ->after('shop_lng')
                    ->comment('Idle-warning window in minutes (default 15, range 5–60)');
            }
            if (!Schema::hasColumn('companies', 'rider_silent_minutes')) {
                $table->unsignedTinyInteger('rider_silent_minutes')->nullable()
                    ->after('rider_idle_minutes')
                    ->comment('Silent-warning window in minutes (default 10, range 3–30)');
            }
            if (!Schema::hasColumn('companies', 'rider_auto_off_hour')) {
                $table->unsignedTinyInteger('rider_auto_off_hour')->nullable()
                    ->after('rider_silent_minutes')
                    ->comment('Hour (app TZ) at which duty auto-ends each night (default 3, range 0–8)');
            }
        });
    }

    public function down(): void
    {
        // Additive only — no destructive down (prod safety).
    }
};
