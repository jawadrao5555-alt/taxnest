<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 781 — Table click = seedha bill kholo (video note, 15 Aug 2026):
 * restaurant shop chahta hai ke occupied table par click karte hi order
 * SEEDHA sale screen ke cart mein edit mode mein khul jaye (beech ka action
 * popup skip), aur popup ke saare actions (Proof Bill, FINAL, KOT dobara,
 * Aakhri Add-on KOT, Table Shift, Order Cancel) sale screen ke payment
 * panel mein milen — receipt print ho ya nahi, iska ikhtiyar bhi wahin.
 *
 * Opt-in per-company flag. Default FALSE — baqi shops ka popup flow
 * bilkul waisa hi rehta hai.
 * Idempotent (hasColumn guard) — prod par `migrate --force` safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }
        if (!Schema::hasColumn('companies', 'table_click_direct_open')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('table_click_direct_open')->default(false)->after('tables_first_flow');
            });
        }

        // ── What's New elaan (POS) — runs at deploy, so the elaan lands exactly
        //    when the feature goes live. Idempotent via title check. ──
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        $title = 'Restaurant shops: table par click = seedha bill khule (naya option)';
        if (\Illuminate\Support\Facades\DB::table('app_updates')->where('title', $title)->exists()) {
            return;
        }
        \Illuminate\Support\Facades\DB::table('app_updates')->insert([
            'title' => $title,
            'points' => json_encode([
                'Naya optional tareeqa: masroof table par click karte hi uska order seedha sale screen ke cart mein edit ke liye khul jata hai — beech mein koi popup nahi.',
                'Table ke saare kaam (Proof Bill, FINAL cash/card, KOT Dobara, Aakhri Add-on KOT, Table Badlein, Order Cancel) ab payment panel mein bhi milte hain jab table ka order cart mein khula ho.',
                'FINAL karte waqt wahin receipt print ka ikhtiyar hai — chaho to bina print ke bill clear karo.',
                'Setting: Tables page → Table Setup → "Table click = seedha bill kholo" toggle. Default OFF hai — jo shops purana popup tareeqa chahti hain un par koi farq nahi.',
            ], JSON_UNESCAPED_UNICODE),
            'audience' => 'pos',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'table_click_direct_open')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('table_click_direct_open');
            });
        }
    }
};
