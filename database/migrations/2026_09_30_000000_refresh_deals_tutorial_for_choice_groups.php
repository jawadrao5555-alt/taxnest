<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refresh only the stock tutorial record. A shop/admin may have deliberately
     * supplied a custom video URL, which this release must leave untouched.
     */
    public function up(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            return;
        }

        DB::table('tutorial_videos')
            ->where('slug', 'deals')
            ->where('video_url', '/videos/tutorials/deals.mp4')
            ->update([
                'video_url' => '/videos/tutorials/deals.mp4?v=choice-groups-20260930',
                'description' => 'Combo deal, fixed items, cashier choice groups, din/waqt, stock aur sale screen ka pura tareeqa.',
                'duration_seconds' => 225,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            return;
        }

        DB::table('tutorial_videos')
            ->where('slug', 'deals')
            ->where('video_url', '/videos/tutorials/deals.mp4?v=choice-groups-20260930')
            ->update([
                'video_url' => '/videos/tutorials/deals.mp4',
                'duration_seconds' => 177,
                'updated_at' => now(),
            ]);
    }
};