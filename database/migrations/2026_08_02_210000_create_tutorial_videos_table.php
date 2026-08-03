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
            [
                'slug' => 'app-install-pwa',
                'title' => 'NestPOS app install karein (computer/mobile)',
                'description' => 'NestPOS ko computer aur mobile par app ki tarah install karein — ek click, apna icon, apni window.',
                'video_url' => '/videos/tutorials/app-install-pwa.mp4',
                'category' => 'shuruat',
                'sort' => 3,
            ],
            [
                'slug' => 'madadgar-raabta',
                'title' => 'Madad, updates aur suggestions',
                'description' => 'Madadgar bot se sawal poochein, ghanti par nai updates dekhein, aur apna idea Feature Suggestion mein bhejein.',
                'video_url' => '/videos/tutorials/madadgar-raabta.mp4',
                'category' => 'shuruat',
                'sort' => 4,
            ],
            [
                'slug' => 'barcode-scan-search',
                'title' => 'Barcode scan aur tez search',
                'description' => 'Scanner se scan karein ya naam/code likhein — item foran cart mein. Hazaron items, seconds mein.',
                'video_url' => '/videos/tutorials/barcode-scan-search.mp4',
                'category' => 'billing',
                'sort' => 11,
            ],
            [
                'slug' => 'discount-dena',
                'title' => 'Discount dena — % ya fix raqam',
                'description' => 'Bill par percent ya fix raqam ka discount — ek click mein, receipt par bhi saaf chhapta hai.',
                'video_url' => '/videos/tutorials/discount-dena.mp4',
                'category' => 'billing',
                'sort' => 12,
            ],
            [
                'slug' => 'provisional-bills',
                'title' => 'Provisional bill — payment baad mein',
                'description' => 'Bill abhi banayen, final baad mein karein — quota bhi bachta hai, hisaab bhi pakka rehta hai.',
                'video_url' => '/videos/tutorials/provisional-bills.mp4',
                'category' => 'billing',
                'sort' => 13,
            ],
            [
                'slug' => 'bills-history',
                'title' => 'Purane bills — search aur receipt dobara',
                'description' => 'Har bill ka poora record — search karein, kholein, receipt dobara print karein.',
                'video_url' => '/videos/tutorials/bills-history.mp4',
                'category' => 'billing',
                'sort' => 14,
            ],
            [
                'slug' => 'day-opening',
                'title' => 'Din ki shuruat — opening cash',
                'description' => 'Subah galla likhein — raat ko day close par hisaab khud milta hai.',
                'video_url' => '/videos/tutorials/day-opening.mp4',
                'category' => 'reports',
                'sort' => 40,
            ],
            [
                'slug' => 'staff-hazri',
                'title' => 'Staff hazri — kaun kab aaya',
                'description' => 'Team ke login/logout ka record apne aap — hazri report aur day close mein shamil.',
                'video_url' => '/videos/tutorials/staff-hazri.mp4',
                'category' => 'reports',
                'sort' => 41,
            ],
            [
                'slug' => 'desktop-agent-printing',
                'title' => 'Desktop Agent — silent printing',
                'description' => 'Receipt aur KOT seedha printer par, apne aap — bina popup, bina click. Setup ka tareeqa.',
                'video_url' => '/videos/tutorials/desktop-agent-printing.mp4',
                'category' => 'settings',
                'sort' => 31,
            ],
            [
                'slug' => 'package-billing',
                'title' => 'Package, billing aur payment proof',
                'description' => 'Apna package aur muddat dekhein, upgrade karein, payment proof upload karein — service kabhi na ruke.',
                'video_url' => '/videos/tutorials/package-billing.mp4',
                'category' => 'settings',
                'sort' => 32,
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
