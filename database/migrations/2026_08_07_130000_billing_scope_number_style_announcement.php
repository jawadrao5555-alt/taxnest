<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What's New" elaan — Billing Scope (staff stream lock) + Bill Number Style
 * (2-3 customer companies ki tajweez, owner-approved 07 Aug 2026).
 * Audience 'pos' ONLY: dono features PRA POS ke local/PRA stream system par
 * chalte hain — FBR POS mein streams ka yeh model nahi hai.
 * Idempotent data migration — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $title = 'Naye Features: Staff Billing Scope + Bill Number Style';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        $points = [
            'Feature 1 — Staff Billing Scope: ab aap kisi cashier ya manager ko sirf EK billing stream tak mehdood kar sakte hain — sirf Local billing, ya sirf PRA billing. Default "Dono" hai (pehle jaisa).',
            'Set kaise karein: POS panel → Team → naya account banate waqt "Billing Scope" chunein, ya kisi mojooda cashier/manager ki edit row (pencil) se tabdeel karein.',
            'Scope lagne ka asar: Local-only staff sirf local bill bana/dekh sakta hai (dashboard, transactions, reports, day-close sab usi stream ke). PRA-only staff sirf PRA bill — provisional aur local unse chhupe rehte hain. PRA switch bhi lock ho jata hai.',
            'Feature 2 — Bill Number Style: har stream (PRA bills / Local bills) ke liye chunein ke receipt par NUMAYA number kya chape — chalti serial (default, pehle jaisa) ya roz ka token (1, 2, 3…) jo har business day subah 6 baje dobara shuru hota hai.',
            'On kaise karein: POS panel → Receipt Settings → "Bill Number Style" section → PRA aur Local ke liye alag alag style chunein → Save.',
            'Zaroori baat: serial number kabhi khatam nahi hota — token style mein bhi receipt par neeche "Ref" ke sath serial chapta hai. Khata, talash, wapsi aur PRA reporting sab serial par hi chalte hain.',
            'Reprint ka usool: bill ka token us par hamesha ke liye jam jata hai — baad mein reprint karein to wohi number chapta hai, kabhi tabdeel nahi hota.',
            'Jo dukan yeh features na chahe: kuch na karein — dono settings default par purane tareeqe se hi chalti hain.',
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
