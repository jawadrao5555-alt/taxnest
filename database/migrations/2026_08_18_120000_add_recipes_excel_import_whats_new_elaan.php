<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// What's New elaan for the Recipes Excel bulk upload (Task 1162).
//
// Data migration ON PURPOSE: the elaan must appear on live in the SAME deploy
// that ships the feature (prod runs `migrate --force` on deploy — same
// convention as the Return/Credit Note elaan). Idempotent: skips when a row
// with the same title already exists.
return new class extends Migration
{
    private const POS_TITLE = 'Naya Feature: Recipes ka Excel bulk upload';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return; // base table migration not run yet (fresh installs run in order anyway)
        }

        if (\App\Models\AppUpdate::where('title', self::POS_TITLE)->exists()) {
            return; // already announced (re-run / partial deploy)
        }

        // points passed as PHP ARRAY — never pre-encoded JSON (double-encode
        // incident 11 Aug 2026 500'd every pos-app page).
        \App\Models\AppUpdate::create([
            'title' => self::POS_TITLE,
            'audience' => 'pos',
            'is_published' => true,
            'points' => [
                "Recipes page par ab 'Excel Upload' ka button hai — poore menu ki recipes ek hi Excel file se upload ho jati hain, ek ek kar ke add karne ki zaroorat nahi.",
                "Pehle 'Download Excel Template' se file lein, har ingredient apni alag row mein likhein (product ka naam/code, ingredient, unit, miqdaar), phir wohi file wapas upload kar dein.",
                'Jo ingredient pehle se nahi bana woh khud ban jata hai — alag se Ingredients page par jane ki zaroorat nahi.',
                'Agar kisi product + ingredient ki recipe pehle se maujood hai to us ki miqdaar update ho jati hai — koi error nahi aata.',
                'Kisi row mein masla ho to sirf woh row skip hoti hai, baqi file import ho jati hai — end par poora khulasa milta hai ke kitni rows add, update ya skip hui.',
            ],
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        \App\Models\AppUpdate::where('title', self::POS_TITLE)->delete();
    }
};
