<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tutorial videos library (owner request, 2 Aug 2026 night):
 * Urdu how-to videos shown on the public /tutorials page AND inside every
 * company's POS login (/pos/tutorials). Rows seeded here idempotently —
 * PROD runs `migrate --force` (never db:seed), so seeding lives in the
 * migration itself (same convention as pricing reprices).
 *
 * video_url is RELATIVE (/videos/...) — files live in public/videos/ and are
 * committed to the repo, so dev preview and live both serve them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            Schema::create('tutorial_videos', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('video_url');
                $table->string('category')->default('shuruat');
                $table->integer('sort')->default(0);
                $table->boolean('is_published')->default(true);
                $table->integer('duration_seconds')->nullable();
                $table->timestamps();
            });
        }

        $now = now();
        $rows = [
            [
                'slug' => 'nestpos-taaruf',
                'title' => 'NestPOS ka taaruf — 1 minute mein',
                'description' => 'Account banane se le kar pehla bill banane tak — NestPOS kya hai aur aap ke liye kya kar sakta hai.',
                'video_url' => '/videos/nestpos-promo.mp4',
                'category' => 'shuruat',
                'sort' => 1,
            ],
            [
                'slug' => 'account-banana',
                'title' => 'Account kaise banayen (Sign Up)',
                'description' => 'taxnest.com.pk par apni dukaan ka account banane ka poora tareeqa — business type, dukaan ki maloomat aur apna login.',
                'video_url' => '/videos/tutorials/account-banana.mp4',
                'category' => 'shuruat',
                'sort' => 2,
            ],
            [
                'slug' => 'sale-screen-tutorial',
                'title' => 'Sale screen — bill banana, payment aur raseed',
                'description' => 'Naya bill banana, items add karna, cash ya card payment lena aur raseed print karna — poora tareeqa tasalli se.',
                'video_url' => '/videos/tutorials/sale-screen-tutorial.mp4',
                'category' => 'billing',
                'sort' => 10,
            ],
            [
                'slug' => 'customers-add-import-export',
                'title' => 'Customer add, import aur export karna',
                'description' => 'Naya customer add karna, Excel/CSV se saare customers ek saath import karna aur list export karna.',
                'video_url' => '/videos/tutorials/customers-add-import-export.mp4',
                'category' => 'customers',
                'sort' => 20,
            ],
            [
                'slug' => 'pos-customize',
                'title' => 'POS apni pasand ka banayen (Customize)',
                'description' => 'POS ka style, rang (theme), zuban aur guided billing — sab kuch apni dukaan ke hisaab se set karein.',
                'video_url' => '/videos/tutorials/pos-customize.mp4',
                'category' => 'settings',
                'sort' => 30,
            ],
        ];

        foreach ($rows as $row) {
            // Idempotent: never duplicate, never overwrite later edits.
            $exists = DB::table('tutorial_videos')->where('slug', $row['slug'])->exists();
            if (!$exists) {
                DB::table('tutorial_videos')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorial_videos');
    }
};
