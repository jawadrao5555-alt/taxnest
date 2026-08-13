<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What's New elaan (POS panel): Business plan now includes the full
 * Kitchen/Restaurant module + Analytics dashboard.
 *
 * Roman Urdu points (owner convention). Audience 'pos' — the pos-app layout
 * already skips confined roles (kitchen/waiter/rider), pending companies and
 * view-only impersonation, so no extra gating needed here.
 * points MUST be a plain PHP array json_encoded ONCE (double-encode killed
 * every pos page on live, 11 Aug 2026).
 */
return new class extends Migration
{
    private string $title = 'Barri Khabar: Business plan mein ab Kitchen Mode + Analytics shamil!';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        $exists = DB::table('app_updates')
            ->where('title', $this->title)
            ->where('audience', 'pos')
            ->exists();
        if ($exists) {
            return;
        }

        $points = [
            'Business plan (24,999/saal) mein ab poora Restaurant / Kitchen module shamil hai — KOT, Kitchen Display Screen (KDS), Table Management, Kitchen Notes aur Recipes.',
            'Advanced Analytics dashboard bhi ab Business plan mein khul gaya hai — date-range sales ka deep dive, PDF download ke saath.',
            'Agar aap Business plan par hain to Settings → Features se kitchen features on kar lein — koi extra charge nahi, aaj hi se available.',
            'Pro plan (34,999/saal) mein pehle ki tarah Delivery Riders, Public QR Menu aur bade limits (10,000 bills/mahina, 10 team accounts, 2 branches) milte hain.',
            'Starter plan par hain? Billing page se Business par upgrade karein aur apne cafe/dhabay ko poora kitchen system dein.',
        ];

        DB::table('app_updates')->insert([
            'title' => $this->title,
            'points' => json_encode($points, JSON_UNESCAPED_UNICODE),
            'image_path' => null,
            'audience' => 'pos',
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
            ->where('audience', 'pos')
            ->delete();
    }
};
