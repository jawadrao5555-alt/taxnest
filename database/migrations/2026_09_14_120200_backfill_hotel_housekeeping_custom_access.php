<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom Access backfill for the 'hotel_housekeeping' grant.
 *
 * Front-desk staff who already had 'hotel' keep housekeeping via the
 * PosAccessService implication (hotel ⇒ hotel_housekeeping). This still
 * appends the key so existing saved sets do not silently lose a new tick-box.
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
                    if (!is_array($set) || in_array('hotel_housekeeping', $set, true)) {
                        continue;
                    }
                    $set[] = 'hotel_housekeeping';
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
                    if (!is_array($set) || !in_array('hotel_housekeeping', $set, true)) {
                        continue;
                    }
                    DB::table('users')->where('id', $row->id)->update([
                        'pos_custom_access' => json_encode(array_values(
                            array_filter($set, fn ($f) => $f !== 'hotel_housekeeping')
                        )),
                    ]);
                }
            });
    }
};
