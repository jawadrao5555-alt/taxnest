<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// What's New elaan for the always-visible PRA/LOCAL billing-mode badge on the
// sale screen (Task 1164 — owner video 18 Aug 2026: he had to open Switches
// just to see whether PRA reporting was ON).
//
// Data migration ON PURPOSE: the elaan must appear on live in the SAME deploy
// that ships the feature (prod runs `migrate --force` on deploy — see the
// return-feature elaan / pricing-reprice convention). Idempotent: skips when
// a row with the same title already exists.
return new class extends Migration
{
    private const POS_TITLE = 'Naya: Sale screen par PRA / LOCAL mode ka badge';

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
            'points' => [
                "Sale screen ke top par ab hamesha nazar aane wala badge: green 'PRA ON' matlab is screen ke final bills PRA ko report ho rahe hain, orange 'LOCAL' matlab bills sirf local ban rahe hain.",
                'Ab Switches kholne ki zaroorat nahi — ek nazar mein pata chal jata hai ke khuli screen kis mode mein bill bana rahi hai.',
                'Admin jab Switches se PRA Reporting on/off karta hai to badge usi waqt badal jata hai — page refresh ki zaroorat nahi.',
                'Yeh badge Auto-Sync (Online/Offline) wale nishan se alag hai — Auto-Sync internet ki halat batata hai, yeh badge billing ka mode.',
                'Mobile par bhi yehi badge sale screen ke buttons ki qatar mein nazar aata hai.',
            ],
            'is_published' => true,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('app_updates')) {
            \App\Models\AppUpdate::where('title', self::POS_TITLE)->delete();
        }
    }
};
