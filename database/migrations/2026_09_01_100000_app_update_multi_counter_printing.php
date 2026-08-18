<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What's New announcement for per-counter printer routing (Task 1166).
 * Data migration (idempotent) because PROD deploys run `migrate --force`,
 * never seeders. Audience 'pos', points array in Roman Urdu per convention.
 * The What's New popup/bell already skips confined roles, pending companies
 * and view-only impersonation — no extra gating needed here.
 */
return new class extends Migration
{
    private const TITLE = 'Naya: Multi-Counter Printing — har cashier ka apna printer';

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
                'Ek se zyada billing counters wali dukanein: ab har counter ke PC par Desktop Agent chalayen (wohi company key), aur har counter Printer Settings page par apne naam ke saath alag nazar aayega.',
                'Har counter ka apna receipt printer set karein, aur har cashier ko us ka counter assign karein — us ke bill sirf usi counter ke printer par nikleinge, manager ke bill manager ke PC par.',
                'Agent ko latest version (v1.9.0+) par update karein — agent khud-ba-khud update ho jata hai, sirf counters ke naam aur printer aik dafa set karne hain.',
                'Jis cashier ka counter set nahi, ya counter band ho, us ke bill pehle ki tarah company ke default printer par nikalte hain — koi bill kabhi gum nahi hota.',
                'Aik hi PC wali dukanon ke liye kuch nahi badla — sab pehle jaisa chalta rahega, kuch set karne ki zaroorat nahi.',
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
