<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What's New announcement for per-cashier sales isolation (Task 1197).
 * Default-ON behavior change on live shops — cashiers suddenly see only their
 * own bills, so the popup must explain WHY. Data migration (idempotent)
 * because PROD deploys run `migrate --force`, never seeders. Audience 'pos',
 * points array in Roman Urdu per convention.
 */
return new class extends Migration
{
    private const TITLE = 'Naya: Har cashier sirf apni sale dekhega';

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
                'Ab har cashier ko har jagah sirf APNI sales nazar aati hain — Transactions list, Reprint list, dashboard ke figures, aur pending/provisional bills sab apne hi.',
                'Doosre cashier ka bill kholna, print karna ya WhatsApp karna cashier ke liye band hai — kisi aur ke bill par return ab manager ya owner karega.',
                'Manager aur owner ko pehle ki tarah POORI dukaan ki sale dikhti hai — aur ab Transactions page aur dashboard par kisi bhi aik cashier ki sale alag se filter kar ke bhi dekh sakte hain.',
                'Agar aap chahte hain ke cashiers aik doosre ki sales dekh saken (pehle wala tareeqa), to owner Team page par "Cashier sirf apni sale dekhe" ka switch OFF kar sakta hai.',
                'Restaurant ka mushtaraka kaam waisa hi hai — tables board, waiter orders, held orders aur kitchen display sab pehle ki tarah sab ko nazar aate hain.',
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
