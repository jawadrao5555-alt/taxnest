<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 822 — owner decision (16 Aug 2026): Tables-first flow ab OPT-IN nahi,
 * sab companies ke liye ON. "sab companies kay liye kr do tmam companies kay
 * liye aur uska notification bhi daal do."
 *
 * - Existing companies: tables_first_flow = 1 (jo shops OFF chahen woh Table
 *   Setup ke toggle se per-company OFF kar sakti hain — toggle zinda rehta hai).
 * - New companies: column DEFAULT 1 (MySQL-only ALTER; sqlite test DB skip —
 *   behaviour is still gated by the tables feature flag in universal.blade.php,
 *   so non-restaurant shops see zero change).
 * - What's New elaan (audience pos) — idempotent via title check.
 *
 * Idempotent throughout — prod `migrate --force` safe, and safe to pre-apply
 * the same SQL directly on live before this file lands there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'tables_first_flow')) {
            DB::table('companies')->where('tables_first_flow', 0)->update([
                'tables_first_flow' => 1,
                'updated_at' => now(),
            ]);

            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE companies ALTER COLUMN tables_first_flow SET DEFAULT 1');
            }
        }

        if (!Schema::hasTable('app_updates')) {
            return;
        }
        $title = 'Bari Tables screen ab sab restaurant shops ke liye ON';
        if (DB::table('app_updates')->where('title', $title)->exists()) {
            return;
        }
        DB::table('app_updates')->insert([
            'title' => $title,
            'points' => json_encode([
                'Dine-in KOT bhejne ke baad aur bill mukammal (receipt popup band) hone ke baad cashier ab seedha full-screen Tables page par wapas jata hai — chhoti table-picker window baar baar nahi khulti.',
                'Yeh tabdeeli ab tamam restaurant shops ke liye ON kar di gayi hai (pehle sirf optional thi).',
                'Receipt aur KOT printing bilkul pehle jaisi chalti hai — screen tabhi badalti hai jab print poori ho jaye.',
                'Agar aap ki shop ko purana tareeqa (chhoti table window) behtar lagta hai to Tables page → Table Setup → "Tables-first flow" toggle OFF kar dein.',
            ], JSON_UNESCAPED_UNICODE),
            'audience' => 'pos',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Data/default rollback intentionally conservative: restore the old
        // default only; per-company values stay as the shops chose them.
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'tables_first_flow') && DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE companies ALTER COLUMN tables_first_flow SET DEFAULT 0');
        }
    }
};
