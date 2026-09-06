<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1582 — module tutorials that have no gate today (barcode scanning,
 * stock/inventory) now ride the module flag, so a salon or consultant no
 * longer sees a barcode video. Idempotent: only rows still on NULL move;
 * anything an admin set by hand is left alone.
 */
return new class extends Migration
{
    private const SLUG_GATES = [
        'barcode-scan-search' => 'barcode',
        'inventory-stock' => 'inventory',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('tutorial_videos') || ! Schema::hasColumn('tutorial_videos', 'required_feature')) {
            return;
        }
        foreach (self::SLUG_GATES as $slug => $gate) {
            DB::table('tutorial_videos')->where('slug', $slug)->whereNull('required_feature')->update(['required_feature' => $gate]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tutorial_videos') || ! Schema::hasColumn('tutorial_videos', 'required_feature')) {
            return;
        }
        foreach (self::SLUG_GATES as $slug => $gate) {
            DB::table('tutorial_videos')->where('slug', $slug)->where('required_feature', $gate)->update(['required_feature' => null]);
        }
    }
};
