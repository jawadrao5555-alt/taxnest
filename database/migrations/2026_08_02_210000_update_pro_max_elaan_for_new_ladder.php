<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ladder restructure follow-up (Aug 2, 2026): the pro_max_package migration
 * (…_200000_…) seeded a published What's New elaan stating that Riders &
 * Khata + QR Menu start at Pro Max. Owner then approved the new ladder the
 * same day (Pro = everything except Hazri + Rider Live Tracking), making
 * that seeded copy wrong. Rewrite it in place — by title, idempotent.
 *
 * Timestamped _210000_ so it sorts AFTER the _200000_ seed migration on
 * fresh databases (otherwise the stale seed would land after this fix).
 *
 * NOTE: deliberately no mention of Rider Live Tracking — its elaan is
 * deferred until the owner green-lights it.
 */
return new class extends Migration
{
    private const OLD_TITLE = 'Naya package: Pro Max (Rs 34,999/saal) — Riders & Khata, Staff Hazri aur QR Menu';
    private const NEW_TITLE = 'Package update: Pro ab mukammal POS — naya Pro Max (Rs 34,999/saal) = Pro + Staff Hazri';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        DB::table('app_updates')->where('title', self::OLD_TITLE)->update([
            'title' => self::NEW_TITLE,
            'points' => json_encode([
                'Delivery Riders & Khata aur public QR Menu page ab PRO package mein shamil hain — Pro ab mukammal POS hai.',
                'Naya Pro Max package: Pro ke sab features + Staff Hazri (attendance), 5,000 bills/mahina, 15 team accounts aur 3 branches.',
                'Unlimited: har cheez unlimited + Team Custom Access — har member ke liye features chunein.',
                'Trial wali dukanein pehle ki tarah sab features aazma sakti hain — koi tabdeeli nahi.',
            ], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        DB::table('app_updates')->where('title', self::NEW_TITLE)->update([
            'title' => self::OLD_TITLE,
            'points' => json_encode([
                'Pro aur Unlimited ke beech naya Pro Max package: 5,000 bills/mahina, 15 team accounts aur 3 branches.',
                'Delivery Riders & Khata, Staff Hazri report aur public QR Menu page ab Pro Max (ya Unlimited) se milte hain.',
                'Pro package mein Restaurant module aur Advanced Analytics pehle ki tarah shamil hain.',
                'Team Custom Access sirf Unlimited package mein hai — har member ke liye features chunein.',
                'Trial wali dukanein pehle ki tarah sab features aazma sakti hain — koi tabdeeli nahi.',
            ], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
};
