<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 779 — Tables-first flow (video note, 15 Aug 2026): restaurant shop
 * jo bara Tables board chalata hai — "chhoti window baar baar show na ho,
 * bari screen hi rahe". Opt-in per-company flag: ON ho to dine-in KOT ke
 * baad aur receipt popup band hone ke baad cashier full-screen Tables page
 * par wapas jata hai (chhota table-picker auto-open nahi hota).
 *
 * Default FALSE — baqi shops ka flow bilkul waisa hi rehta hai.
 * Idempotent (hasColumn guard) — prod par `migrate --force` safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }
        if (!Schema::hasColumn('companies', 'tables_first_flow')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('tables_first_flow')->default(false)->after('dine_in_auto_kot');
            });
        }

        // ── What's New elaan (POS) — runs at deploy, so the elaan lands exactly
        //    when the feature goes live. Idempotent via title check. ──
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        $title = 'Restaurant shops: bari Tables screen wapas (Tables-first flow)';
        if (\Illuminate\Support\Facades\DB::table('app_updates')->where('title', $title)->exists()) {
            return;
        }
        \Illuminate\Support\Facades\DB::table('app_updates')->insert([
            'title' => $title,
            'points' => json_encode([
                'Naya optional tareeqa un restaurant shops ke liye jo full-screen Tables page se kaam chalate hain.',
                'ON karne par: dine-in KOT bhejne ke baad aur bill mukammal (receipt popup band) hone ke baad cashier seedha bari Tables screen par wapas jata hai — chhoti table window baar baar nahi khulti.',
                'Receipt aur KOT printing bilkul pehle jaisi chalti hai — screen tabhi badalti hai jab print poori ho jaye.',
                'Setting: Tables page → Table Setup → "Tables-first flow" toggle. Default OFF hai — jo shops yeh nahi chahtin un par koi farq nahi.',
            ], JSON_UNESCAPED_UNICODE),
            'audience' => 'pos',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'tables_first_flow')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('tables_first_flow');
            });
        }
    }
};
