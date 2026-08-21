<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task #1405 (Sep 2026) — remember which rider-app build each rider is on.
 *
 * A rider who never taps the update banner silently keeps the old APK: no
 * background delivery sync, no push. Until now the only trace of his build
 * was the raw cPanel access log — the app has always sent
 * `User-Agent: TaxNestRider/<version>` on every call, nobody stored it.
 *
 * - app_version : version parsed from that user agent on every authenticated
 *                 rider-app call. NULL = this rider has never reached us from
 *                 the (new) app at all — exactly the riders the shop must
 *                 chase after a rollout.
 *
 * "Newer than the setting" is deliberately NOT stored: `rider_app_latest_version`
 * moves with each release, so outdated-ness is computed at read time.
 *
 * hasColumn guard + additive only — safe to re-run on a live server whose
 * schema has drifted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_riders')) {
            return;
        }

        if (!Schema::hasColumn('pos_riders', 'app_version')) {
            Schema::table('pos_riders', function (Blueprint $table) {
                $table->string('app_version', 20)->nullable()
                    ->comment('Rider app build last seen on this rider (from the TaxNestRider user agent); NULL = never opened the app');
            });
        }
    }

    public function down(): void
    {
        // Additive only — no destructive down (prod safety).
    }
};
