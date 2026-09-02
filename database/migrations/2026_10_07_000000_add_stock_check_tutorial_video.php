<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Physical Stock Check tutorial.
 *
 * Tutorial rows ship through migrations because production does not run
 * seeders. The slug guard intentionally preserves any super-admin edits.
 */
return new class extends Migration
{
    private array $row = [
        'slug' => 'stock-check',
        'title' => 'Physical Stock Check — Shelf ki Ginti, Har Farq ka Hisaab',
        'description' => 'System ke stock ko shelf ki asal ginti se milayen, kami ya ziyadti ki wajah likhein, Excel sheet se count import karein aur tasdeeq ke baad stock correct karein.',
        'category' => 'products',
        'sort' => 24,
        'min_role' => 'admin',
        'duration_seconds' => 270,
    ];

    public function up(): void
    {
        if (!Schema::hasTable('tutorial_videos')
            || DB::table('tutorial_videos')->where('slug', $this->row['slug'])->exists()) {
            return;
        }

        $now = now();
        DB::table('tutorial_videos')->insert([
            'slug' => $this->row['slug'],
            'product' => 'nestpos',
            'title' => $this->row['title'],
            'description' => $this->row['description'],
            'video_url' => '/videos/tutorials/stock-check.mp4',
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

        DB::table('tutorial_videos')
            ->where('slug', $this->row['slug'])
            ->where('title', $this->row['title'])
            ->where('description', $this->row['description'])
            ->where('video_url', '/videos/tutorials/stock-check.mp4')
            ->delete();
    }
};