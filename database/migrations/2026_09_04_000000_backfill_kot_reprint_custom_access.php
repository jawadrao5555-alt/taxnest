<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1379 — backfill the new 'kot_reprint' Custom Access grant.
 *
 * PosAccessService::customSet() intersects the stored JSON with FEATURES, so a
 * freshly added key is ABSENT from every set saved before this deploy. Without
 * this backfill, kotReprintAllowed() would read "unticked = denied" and every
 * shop already using Custom Access would silently lose its KOT reprint /
 * re-send / Last Add-on buttons — exactly the unannounced behaviour change the
 * task forbids. Members with NO set are unaffected (null = role default).
 *
 * Idempotent (PROD runs `migrate --force`, never seeders) and additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'pos_custom_access')) {
            return;
        }

        DB::table('users')
            ->whereNotNull('pos_custom_access')
            ->select('id', 'pos_custom_access')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $set = json_decode((string) $row->pos_custom_access, true);
                    // Corrupt / non-array values are left exactly as they are —
                    // customSet() already treats them as "no set".
                    if (!is_array($set) || in_array('kot_reprint', $set, true)) {
                        continue;
                    }
                    $set[] = 'kot_reprint';
                    DB::table('users')->where('id', $row->id)->update([
                        'pos_custom_access' => json_encode(array_values($set)),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'pos_custom_access')) {
            return;
        }

        DB::table('users')
            ->whereNotNull('pos_custom_access')
            ->select('id', 'pos_custom_access')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $set = json_decode((string) $row->pos_custom_access, true);
                    if (!is_array($set) || !in_array('kot_reprint', $set, true)) {
                        continue;
                    }
                    DB::table('users')->where('id', $row->id)->update([
                        'pos_custom_access' => json_encode(array_values(
                            array_filter($set, fn ($f) => $f !== 'kot_reprint')
                        )),
                    ]);
                }
            });
    }
};
