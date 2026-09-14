<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom Access backfill for the new 'hotel' grant.
 *
 * PosAccessService::customSet() intersects stored JSON with FEATURES, so a
 * freshly added key is ABSENT from every set saved before this deploy.
 * Append 'hotel' to every non-null set (additive + idempotent). NULL sets stay
 * NULL so they keep following the role default.
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
                    if (!is_array($set) || in_array('hotel', $set, true)) {
                        continue;
                    }
                    $set[] = 'hotel';
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
                    if (!is_array($set) || !in_array('hotel', $set, true)) {
                        continue;
                    }
                    DB::table('users')->where('id', $row->id)->update([
                        'pos_custom_access' => json_encode(array_values(
                            array_filter($set, fn ($f) => $f !== 'hotel')
                        )),
                    ]);
                }
            });
    }
};
