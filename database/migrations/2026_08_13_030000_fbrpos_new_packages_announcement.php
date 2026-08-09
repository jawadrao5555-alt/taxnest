<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What's New" elaan — FBR POS ke naye packages (Starter 999 / Business 1,999 /
 * Pro 2,999) + strict feature binding (owner-approved 9 Aug 2026, dekho
 * 2026_08_13_020000_fbrpos_plan_reprice_and_strict_gating).
 *
 * Audience 'fbr_pos' ONLY — PRA POS ki apni pricing ladder alag hai.
 * Idempotent data migration — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $title = 'Naye FBR POS Packages: Har Dukan ke Liye Sahi Intikhab';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        $points = [
            'FBR POS ke packages naye siray se tarteeb diye gaye hain — ab TEEN saaf packages hain: Starter Rs. 999/mahina, Business Rs. 1,999/mahina, aur Pro Rs. 2,999/mahina.',
            'Starter (Rs. 999): rozana ki FBR billing ke liye — sale screen, FBR real-time submission, QR code receipts aur inventory (stock) shamil hai.',
            'Business (Rs. 1,999): Starter ki har cheez + Offline Billing (internet ke baghair bhi bill), Excel import/export, Khata (udhaar) aur mukammal Reports.',
            'Pro (Rs. 2,999): Business ki har cheez + Deals, Loyalty, Kitchen/KOT aur Advanced Analytics — sab kuch unlimited.',
            'Zaroori ittila: ab har feature apne package ke sath bandha hai. Agar koi feature (maslan Offline Billing ya Khata) aap ke menu se ghayab hua hai to iska matlab hai woh aap ke mojooda package mein shamil nahi — koi kharabi nahi hai.',
            'Aap ke pehle ke banaye hue bills, khata records aur data bilkul mehfooz hain — package tabdeeli se koi data delete NAHI hota. Feature dobara on karne ke liye sirf package upgrade karein.',
            'Upgrade kaise karein: FBR POS panel → Billing page kholen, apni pasand ka package chunein aur payment proof upload karein — manzoori ke baad features foran khul jate hain.',
            'Kisi bhi sawal ke liye support se rabta karein — hum aap ki dukan ke liye behtareen package chunne mein madad karenge.',
        ];

        $exists = DB::table('app_updates')
            ->where('title', $this->title)
            ->where('audience', 'fbr_pos')
            ->exists();
        if ($exists) {
            return;
        }

        DB::table('app_updates')->insert([
            'title' => $this->title,
            'points' => json_encode($points, JSON_UNESCAPED_UNICODE),
            'image_path' => null,
            'audience' => 'fbr_pos',
            'is_published' => true,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        DB::table('app_updates')
            ->where('title', $this->title)
            ->where('audience', 'fbr_pos')
            ->delete();
    }
};
