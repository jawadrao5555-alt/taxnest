<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// What's New elaan for Task 1163: Today's Ledger now shows the sale split
// by cash / card next to the existing tax split, on both stream cards.
//
// Data migration ON PURPOSE: the elaan must appear on live in the SAME deploy
// that ships the feature (prod runs `migrate --force` on deploy — same
// convention as the return-feature elaan). Idempotent: skips if the title
// already exists.
return new class extends Migration
{
    private const POS_TITLE = "Aaj ke Khaate mein ab Cash aur Card ki sale alag alag";

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
            'points' => [
                "Dashboard ke 'Aaj ka Khaata' card par ab do nayi lines hain — 'Cash par sale' aur 'Card par sale'.",
                'Yeh dono lines PRA aur Local dono cards par tax wali lines ke saath nazar aati hain.',
                'Returns pehle hi minha ho kar dikhte hain — bilkul baaqi lines ki tarah.',
            ],
            'audience' => 'pos',
            'is_published' => true,
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        \App\Models\AppUpdate::where('title', self::POS_TITLE)->delete();
    }
};
