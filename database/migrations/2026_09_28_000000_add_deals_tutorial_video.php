<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deals tutorial video (owner request): combo deal banana, uske din/waqt
 * handle karna aur sale screen par bechna.
 *
 * Same convention as the other tutorial-library migrations: PROD runs
 * `migrate --force` (never db:seed), so the row is seeded here idempotently
 * (slug-guarded — never duplicates, never overwrites later admin edits).
 * Video file: public/videos/tutorials/deals.mp4 (committed to the repo).
 */
return new class extends Migration
{
    private array $row = [
        'slug' => 'deals',
        'title' => 'Deals — Combo Banayen, Ek Click Par Bechen',
        'description' => 'Deal banana, din aur waqt set karna, edit/delete se handle karna aur sale screen par ek click mein bechna — pura tareeqa.',
        'category' => 'billing',
        'sort' => 17,
        'min_role' => 'admin',
        'required_feature' => 'deals_enabled',
        'show_public' => true,
        'duration_seconds' => 177,
    ];

    public function up(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            return;
        }
        if (DB::table('tutorial_videos')->where('slug', $this->row['slug'])->exists()) {
            return; // never duplicate, never overwrite later admin edits
        }

        $now = now();
        DB::table('tutorial_videos')->insert([
            'slug' => $this->row['slug'],
            'product' => 'nestpos',
            'title' => $this->row['title'],
            'description' => $this->row['description'],
            'video_url' => '/videos/tutorials/' . $this->row['slug'] . '.mp4',
            'category' => $this->row['category'],
            'required_feature' => $this->row['required_feature'],
            'min_role' => $this->row['min_role'],
            'sort' => $this->row['sort'],
            'is_published' => true,
            'show_public' => $this->row['show_public'],
            'duration_seconds' => $this->row['duration_seconds'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('tutorial_videos')) {
            DB::table('tutorial_videos')->where('slug', $this->row['slug'])->delete();
        }
    }
};
