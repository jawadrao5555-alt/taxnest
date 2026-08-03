<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module tutorial videos (Task 233, 3 Aug 2026): restaurant mode, delivery
 * riders aur deals ki Urdu tutorial videos ko tutorial library mein add
 * karta hai. Same convention as the library migration itself: PROD runs
 * `migrate --force` (never db:seed), so rows are seeded here idempotently
 * (slug-guarded — never duplicates, never overwrites later edits).
 *
 * Video files live in public/videos/tutorials/ (committed to the repo) so
 * dev preview aur live dono serve karte hain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            return; // library migration hasn't run yet; it runs first by date
        }

        $now = now();
        $rows = [
            [
                'slug' => 'restaurant-mode-tutorial',
                'title' => 'Restaurant Mode — tables, KOT, kitchen aur settle',
                'description' => 'Table par order lena, kitchen ko KOT bhejna, Kitchen Display par tayyari, waiter se order aur Table Board se bill final karna — poora restaurant flow.',
                'video_url' => '/videos/tutorials/restaurant-mode-tutorial.mp4',
                'category' => 'restaurant',
                'sort' => 40,
                'duration_seconds' => 207,
            ],
            [
                'slug' => 'delivery-riders-tutorial',
                'title' => 'Delivery Riders — order assign, rider portal aur khata',
                'description' => 'Delivery bill banana, rider ko assign karna, rider ke mobile portal se Delivered karna aur din ke aakhir mein rider ka cash settle karna.',
                'video_url' => '/videos/tutorials/delivery-riders-tutorial.mp4',
                'category' => 'riders',
                'sort' => 50,
                'duration_seconds' => 160,
            ],
            [
                'slug' => 'deals-tutorial',
                'title' => 'Deals — combo deal banana aur bechna',
                'description' => 'Nayi deal set karna (items, fixed qeemat, active din) aur sale screen par ek click mein deal bechna — receipt par saare items alag alag.',
                'video_url' => '/videos/tutorials/deals-tutorial.mp4',
                'category' => 'deals',
                'sort' => 60,
                'duration_seconds' => 144,
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('tutorial_videos')->where('slug', $row['slug'])->exists();
            if (!$exists) {
                DB::table('tutorial_videos')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('tutorial_videos')->whereIn('slug', [
            'restaurant-mode-tutorial',
            'delivery-riders-tutorial',
            'deals-tutorial',
        ])->delete();
    }
};
