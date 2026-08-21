<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What's New announcement for the final-bill KOT safety net (Task 1356).
 * Behaviour change on live restaurant shops — a bill settled without
 * "Send to Kitchen" now prints a kitchen slip by itself, so the popup must
 * explain what changed and where the off-switch lives. Data migration
 * (idempotent) because PROD deploys run `migrate --force`, never seeders.
 * Audience 'pos', points array in Roman Urdu per convention.
 */
return new class extends Migration
{
    private const TITLE = 'Naya: Bill final ho to kitchen parchi khud chali jayegi';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        if (DB::table('app_updates')->where('title', self::TITLE)->exists()) {
            return;
        }
        DB::table('app_updates')->insert([
            'title' => self::TITLE,
            'points' => json_encode([
                'Agar cashier "Send to Kitchen" dabaye baghair hi bill CASH/CARD se final kar de, to ab un items ki kitchen parchi khud-ba-khud nikal jayegi — dine-in par bhi. Pehle aise order ki khabar kitchen tak pohanchti hi nahi thi.',
                'Parchi sirf UN items ki nikalti hai jo kitchen ne kabhi dekhe hi nahi. Jo order pehle hold/KOT ho chuka ho, ya waiter ne bheja ho, uski doosri parchi kabhi nahi nikalti.',
                'Parchi jate hi cashier ko chhota sa paighaam nazar aata hai, taake usay yaqeen ho jaye ke order kitchen tak pohanch gaya.',
                'Jin shops par Kitchen Display (KDS) khud parchi chhapta hai, wahan cashier kuch nahi chhapta — aisa paid order jo kitchen ne na dekha ho, wo KDS board par usi din nazar aata rahega jab tak kitchen usay clear na kare.',
                'Ye safety-net har restaurant shop par by default ON hai. Band karna ho to: Kitchen Settings mein "Bina KOT ke bill final ho to kitchen ticket khud bhejein" ka switch OFF kar dein.',
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
