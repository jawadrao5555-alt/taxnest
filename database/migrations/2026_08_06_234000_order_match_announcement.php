<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What's New" elaan — Order Matching Number feature (owner request 06 Aug 2026,
 * detail-mein usage instructions). Audience 'pos' ONLY: feature restaurant-order
 * flows par chalta hai (RestaurantOrder rows + kitchen-ticket + PRA receipts);
 * FBR POS ka held-sale system alag hai (JSON carts, no RestaurantOrder) — us
 * side order matching abhi ship nahi hua, is liye wahan elaan nahi.
 * Idempotent data migration — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $title = 'Naya Feature: Order Matching Number (Receipt + KOT)';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        $points = [
            'Masla jo hal hua: kitchen KOT par aur customer receipt par alag alag number the — ready order ko bill se milana mushkil tha. Ab dono parchiyon par AIK jaisa number chap sakta hai.',
            'On kaise karein: POS panel → Receipt Settings → "Order Matching Number" section → apna style chunein → Save. Sirf admin/manager kar sakta hai.',
            'Style 1 — Daily Token Number: har naye order ko roz ka chhota number milta hai (1, 2, 3…) jo receipt aur KOT dono par bade font mein chapta hai. Har business day subah 6 baje dobara 1 se shuru hota hai. Counter par awaz dena aasan: "Token 45 tayyar!"',
            'Style 2 — Unique Order Code: order number ka 5-harfi code (misal 91C4F) dono parchiyon par bade font mein chapta hai. Yeh random hota hai — bahar wala banda is se aap ke rozana orders ki ginti trace nahi kar sakta.',
            'Add-on ka usool: order mein baad mein items add karein to number WOHI rehta hai — nayi KOT (KOT #2, #3…) par bhi wohi token/code chapta hai, aur aakhri bill par bhi.',
            'Do counter wali dukanein: token number system ke markaz se milta hai, computer se nahi — dono counter ek hi series mein chalte hain, number kabhi nahi takrata.',
            'Jo dukan yeh feature na chahe: setting "Band" (Off) par rehne dein — receipts bilkul pehle jaisi chapengi.',
        ];

        foreach (['pos'] as $audience) {
            $exists = DB::table('app_updates')
                ->where('title', $this->title)
                ->where('audience', $audience)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('app_updates')->insert([
                'title' => $this->title,
                'points' => json_encode($points, JSON_UNESCAPED_UNICODE),
                'image_path' => null,
                'audience' => $audience,
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
            ->whereIn('audience', ['pos'])
            ->delete();
    }
};
