<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What's New" elaan — Rider App v1.2.0 (Aug 2026):
 * Offline route recording — GPS ya network gum ho tab bhi trail save hoti hai.
 * Idempotent data migration — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $title = 'Rider App 1.2.0 — Offline route recording';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        if (!DB::table('app_updates')->where('title', $this->title)->where('audience', 'pos')->exists()) {
            DB::table('app_updates')->insert([
                'title' => $this->title,
                'points' => json_encode([
                    'Rider ke phone ka GPS band ho jaye ya internet gum ho — ab recording band nahi hoti. Trail phone mein save hoti rehti hai aur network wapas aate hi server ko bhej di jati hai.',
                    'Purana app sirf online recording karta tha; naye app mein gap wali jagah clearly mark hoti hai taake admin ko pata chale.',
                    'Naya app update karo (v1.2.0) — Riders page par APK download link available hai.',
                ], JSON_UNESCAPED_UNICODE),
                'image_path' => null,
                'audience' => 'pos',
                'is_published' => true,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        DB::table('app_updates')->where('title', $this->title)->where('audience', 'pos')->delete();
    }
};
