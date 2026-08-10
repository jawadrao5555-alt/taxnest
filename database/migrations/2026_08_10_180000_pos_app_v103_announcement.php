<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What's New" elaan — TaxNest POS App v1.0.3 (Android).
 * Tutorial videos ab fullscreen chalti hain (phone par fullscreen button kaam karta hai).
 * Audience 'pos' only: PRA POS users ke liye (FBR POS ka alag app hai).
 * Idempotent — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $title = 'Naya Update: TaxNest POS App v1.0.3';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        $points = [
            'TaxNest POS App ka naya version (v1.0.3) ab download page par available hai — downloads/taxnest-pos.apk se hasil karein.',
            'Tutorial videos ab phone par FULLSCREEN mein chalti hain — screen ko badhane ka button ab kaam karta hai, pehle silently kuch nahi hota tha.',
            'Koi bhi team member (owner, admin, cashier, waiter, rider) apne normal login se directly app mein sign in kar sakta hai.',
            'App install karne ke baad apne aap update hoti rehti hai — baar baar download karne ki zaroorat nahi.',
        ];

        $exists = DB::table('app_updates')
            ->where('title', $this->title)
            ->where('audience', 'pos')
            ->exists();

        if (!$exists) {
            DB::table('app_updates')->insert([
                'title'      => $this->title,
                'points'     => json_encode($points, JSON_UNESCAPED_UNICODE),
                'image_path' => null,
                'audience'   => 'pos',
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
        DB::table('app_updates')
            ->where('title', $this->title)
            ->where('audience', 'pos')
            ->delete();
    }
};
