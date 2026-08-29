<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAN Mode + Offline billing tutorial (Desktop Agent v1.11.0 release).
 *
 * Same convention as the other tutorial-library migrations: PROD runs
 * `migrate --force` (never db:seed), so the row is seeded here idempotently
 * (slug-guarded — never duplicates, never overwrites later admin edits).
 * Video file: public/videos/tutorials/lan-mode.mp4 (committed to the repo).
 *
 * min_role = admin: switching LAN Mode on lives in the agent window on the
 * shop's own PC, so this is owner/admin work, not a cashier's.
 */
return new class extends Migration
{
    private array $row = [
        'slug' => 'lan-mode',
        'title' => 'LAN Mode — Net Band Ho To Bhi Sab Aapas Mein Juda',
        'description' => 'Internet band ho jaye to counter par billing kaise chalti rahti hai, aur naya LAN Mode: shop ka PC khud chhota server ban kar phone/tablet ko seedha jorta hai — switch on karne se le kar device pair karne tak pura tareeqa.',
        'category' => 'settings',
        'sort' => 1100,
        'min_role' => 'admin',
        'duration_seconds' => 342,
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
            'required_feature' => null,
            'min_role' => $this->row['min_role'],
            'sort' => $this->row['sort'],
            'is_published' => true,
            'show_public' => true,
            'duration_seconds' => $this->row['duration_seconds'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            return;
        }

        // Only remove the row if it is still exactly what this migration
        // inserted. Once an admin has edited the title/description or another
        // migration owns the slug, a rollback must not throw that work away.
        DB::table('tutorial_videos')
            ->where('slug', $this->row['slug'])
            ->where('title', $this->row['title'])
            ->where('video_url', '/videos/tutorials/' . $this->row['slug'] . '.mp4')
            ->where('description', $this->row['description'])
            ->delete();
    }
};
