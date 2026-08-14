<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Task 722: the Return / Credit Note elaan rows (created by migration
// 2026_08_14_130000) become FEATURED "bara elaan" popups, and their seen
// rows are cleared ONCE so users who dismissed the plain version also see
// the celebratory one (a second dismiss sticks — seen rows are recreated).
//
// Query Builder ON PURPOSE: only the flag is touched — points are never
// read or re-written (double-encode incident 11 Aug 2026).
//
// Idempotent: only rows still is_featured=false are flipped, so a re-run
// never wipes seen rows again.
return new class extends Migration
{
    private const TITLES = [
        'Naya Feature: Bills list se seedha Return / Credit Note',          // PRA POS
        'Naya Feature: Receipts list se seedha Return (15 din ke andar)',   // FBR POS
    ];

    public function up(): void
    {
        if (!Schema::hasTable('app_updates') || !Schema::hasColumn('app_updates', 'is_featured')) {
            return;
        }

        $ids = DB::table('app_updates')
            ->whereIn('title', self::TITLES)
            ->where('is_featured', false)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return; // already featured (re-run) or elaan rows not created yet
        }

        DB::table('app_updates')->whereIn('id', $ids)->update(['is_featured' => true]);

        if (Schema::hasTable('app_update_seens')) {
            DB::table('app_update_seens')->whereIn('app_update_id', $ids)->delete();
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_updates') || !Schema::hasColumn('app_updates', 'is_featured')) {
            return;
        }
        // Seen rows are NOT restored — un-featuring must not re-show anything.
        DB::table('app_updates')->whereIn('title', self::TITLES)->update(['is_featured' => false]);
    }
};
