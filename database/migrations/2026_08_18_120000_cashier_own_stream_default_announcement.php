<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What's New" elaan — Task 1186: har cashier ko BY DEFAULT apni hi stream
 * ki sales (return/credit note samet). Audience 'pos' ONLY: billing scope
 * PRA POS ka concept hai — FBR POS mein streams ka yeh model nahi hai.
 * Idempotent data migration — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $title = 'Nayi Tabdeeli: Har Cashier Ko Apni Stream Ki Sales';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        $points = [
            'Ab har cashier ko by default sirf APNI stream ki sales dikhti hain — PRA reporting ON wale cashier ko sirf PRA sales, reporting OFF (local) wale ko sirf Local sales. Transactions, reports, dashboard, day-close — sab usi stream ke.',
            'Return / credit note bhi apni hi stream par: PRA bill par credit note, local bill par local return. Doosri stream ke bills na list mein dikhte hain na return ho sakte hain.',
            'Cashier ka apna banaya bill (kisi bhi stream ka) usko hamesha dikhta aur print hota hai — sale ke foran baad receipt/reprint kabhi nahi rukta.',
            'Sale banane mein koi nayi rukawat nahi: provisional (F10), held orders, promote — sab pehle jaisa chalta hai. Team page se cashier ki reporting ON/OFF karein to uski dikhne wali stream khud-ba-khud saath badal jati hai.',
            'Band karna ho (pehle jaisa sab kuch dikhe): POS panel → Team → cashier ki edit row (pencil) → Billing Scope → "Dono (PRA + Local)" chunein → Save.',
            'Dobara chalu karna ho: usi dropdown mein "Auto" chunein — cashier wapas apni stream par lock ho jayega (reporting ke mutabiq). Yeh setting sirf owner (ya owner ka ijazat diya admin) dekh/badal sakta hai.',
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
