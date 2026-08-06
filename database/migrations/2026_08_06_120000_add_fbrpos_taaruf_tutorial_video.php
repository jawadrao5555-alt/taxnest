<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS taaruf/promo video (owner request, 6 Aug 2026): tutorial library
 * mein FBR POS ka apna folder shuru hota hai — public /tutorials page par
 * ab NestPOS aur FBR POS ke alag alag sections nazar aate hain (controller
 * already product-folders mein group karta hai).
 *
 * Same convention as the other tutorial migrations: PROD runs
 * `migrate --force` (never db:seed), so the row is seeded here idempotently
 * (slug-guarded — never duplicates, never overwrites later admin edits).
 * Video file: public/videos/fbrpos-promo.mp4 (committed to the repo).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            return;
        }

        $exists = DB::table('tutorial_videos')->where('slug', 'fbrpos-taaruf')->exists();
        if ($exists) {
            return;
        }

        $now = now();
        DB::table('tutorial_videos')->insert([
            'slug' => 'fbrpos-taaruf',
            'product' => 'fbrpos',
            'title' => 'FBR POS ka taaruf — 1 minute mein',
            'description' => 'FBR POS kya hai aur kaise kaam karta hai — register se pehla bill, FBR reporting, udhaar khata, stock aur munafa report tak, sab ek minute mein.',
            'video_url' => '/videos/fbrpos-promo.mp4',
            'category' => 'shuruat',
            'sort' => 1,
            'is_published' => true,
            'show_public' => true,
            'duration_seconds' => 70,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('tutorial_videos')) {
            DB::table('tutorial_videos')->where('slug', 'fbrpos-taaruf')->delete();
        }
    }
};
