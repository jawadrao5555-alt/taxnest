<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task #1102 (Aug 2026) — server-side auto duty-off stamp.
 *
 * duty_auto_off_at is set when the lazy sweep (trackingData poll / location
 * upload path) flips a forgotten on_duty rider off after the late-night
 * cutoff. Cleared the next time the rider toggles duty from the app.
 * NULL = duty was never auto-ended (or has been toggled since).
 *
 * Idempotent (hasColumn guard) — prod schema drift safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_riders', 'duty_auto_off_at')) {
            Schema::table('pos_riders', function (Blueprint $table) {
                $table->timestamp('duty_auto_off_at')->nullable()
                    ->after('duty_started_at')
                    ->comment('Set when the night sweep auto-ended duty; cleared on next app duty toggle');
            });
        }
    }

    public function down(): void
    {
        // Additive only — no destructive down (prod safety).
    }
};
