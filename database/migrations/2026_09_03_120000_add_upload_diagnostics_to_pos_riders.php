<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task #1357 (Sep 2026) — rider upload diagnostics for the live map.
 *
 * Owner report: "rider ne delivery ki, map par kuch nahi aaya; wapas dukan
 * pahunch kar poora route ek saath upload hua." The map could not explain it,
 * because pos_riders only remembered WHEN a fix was taken (last_located_at),
 * never when the phone actually reached the server, nor why the server said no.
 *
 * - last_upload_at      : server time of the rider app's last accepted upload.
 *                         Stamped OUTSIDE the last_located_at regression guard —
 *                         a drained offline buffer must not move the rider's
 *                         position, but it still proves the phone spoke to us.
 *                         Fix time vs upload time = the "late sync" evidence.
 * - last_reject_reason  : why the last upload was refused — 'duty_off',
 *                         'plan_locked' or 'too_old'. NULL = nothing refused
 *                         (cleared as soon as uploads land again).
 * - last_reject_at      : when that refusal happened.
 *
 * Per-column hasColumn guards + additive only — safe to re-run on a live
 * server whose schema has drifted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_riders', 'last_upload_at')) {
            Schema::table('pos_riders', function (Blueprint $table) {
                $table->timestamp('last_upload_at')->nullable()
                    ->after('last_located_at')
                    ->comment('Server time of the last accepted location upload (may be much newer than last_located_at when the phone synced late)');
            });
        }

        if (!Schema::hasColumn('pos_riders', 'last_reject_reason')) {
            Schema::table('pos_riders', function (Blueprint $table) {
                $table->string('last_reject_reason', 32)->nullable()
                    ->after('last_upload_at')
                    ->comment('Why the last location upload was refused: duty_off | plan_locked | too_old; NULL once uploads land again');
            });
        }

        if (!Schema::hasColumn('pos_riders', 'last_reject_at')) {
            Schema::table('pos_riders', function (Blueprint $table) {
                $table->timestamp('last_reject_at')->nullable()
                    ->after('last_reject_reason')
                    ->comment('When the last refused upload happened');
            });
        }
    }

    public function down(): void
    {
        // Additive only — no destructive down (prod safety).
    }
};
