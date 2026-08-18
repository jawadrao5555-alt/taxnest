<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task #1106 (Aug 2026) — rider-app instant push (FCM) + battery reporting.
 *
 * - fcm_token: the rider phone's Firebase Cloud Messaging registration token.
 *   Rotates with app_token on every login (one active device); cleared on
 *   logout and when FCM reports the token UNREGISTERED. NULL = old APK or
 *   push unavailable — the 15-min poll fallback still covers those phones.
 * - last_battery_pct: newest battery % piggybacked on location uploads,
 *   denormalized here so the admin live map never scans the points table.
 *
 * Idempotent (hasColumn guards) — cPanel PROD schema-drift safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_riders', 'fcm_token')) {
            Schema::table('pos_riders', function (Blueprint $table) {
                // TEXT, not varchar: FCM registration tokens have no documented
                // length cap (currently ~160–300 chars, may grow).
                $table->text('fcm_token')->nullable()
                    ->comment('FCM registration token; rotates with app_token, NULL = push unavailable');
            });
        }
        if (!Schema::hasColumn('pos_riders', 'last_battery_pct')) {
            Schema::table('pos_riders', function (Blueprint $table) {
                $table->unsignedTinyInteger('last_battery_pct')->nullable()
                    ->after('last_located_at')
                    ->comment('Newest battery % from location uploads (old APKs send none)');
            });
        }
    }

    public function down(): void
    {
        // Additive only — no destructive down (prod safety).
    }
};
