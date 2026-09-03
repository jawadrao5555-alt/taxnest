<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What's New announcement for the realtime Agent print-wake rollout.
 * Data migration is idempotent because production deploys run migrate --force.
 */
return new class extends Migration
{
    private const TITLE = 'Silent printing ab kam server load ke sath foran chalegi';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates') ||
            DB::table('app_updates')->where('title', self::TITLE)->exists()) {
            return;
        }

        DB::table('app_updates')->insert([
            'title' => self::TITLE,
            'points' => json_encode([
                'NestPOS Desktop ab nayi receipt ya kitchen parchi ka secure signal foran leta hai, is liye baar baar server se poochne ki zaroorat bohat kam ho gayi hai.',
                'Asal print job pehle ki tarah mehfooz API se sirf usi company aur usi counter ko milti hai. Signal mein API key ya bill ki detail nahi hoti.',
                'Internet ya realtime service mein masla ho to Agent khud-ba-khud purani polling par aa jata hai — silent printing rukti nahi aur koi setting dobara karni nahi parti.',
                'Naya Agent version 1.13.0 khud update hoga. Purane Agents bhi pehle ki tarah kaam karte rahenge.',
            ], JSON_UNESCAPED_UNICODE),
            'audience' => 'pos',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('app_updates')) {
            DB::table('app_updates')->where('title', self::TITLE)->delete();
        }
    }
};