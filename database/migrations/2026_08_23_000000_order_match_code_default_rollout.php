<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 652 — Order Matching: Code style becomes the DEFAULT for everyone
 * (owner decision, Aug 2026, after Pizza Master's live experience).
 *
 * Why 'code' over 'token'/'off': fewer lines on the slip, better format,
 * paper saving, and the random ORD suffix means an outsider can't count a
 * shop's daily order volume. Rendering is already built + tested in BOTH
 * panels (PRA + FBR POS): full bold ORD- number on the KOT, short suffix
 * on 80mm + 58mm receipts. Retail (non-restaurant) bills print nothing
 * extra — the renders are gated on order_type / restaurant order rows.
 *
 * What this does (idempotent, PROD drift self-heal convention):
 *  1. Column default 'off' → 'code' so every NEW company starts on code.
 *  2. Flip of genuinely UNSET (NULL) rows only.
 *
 * Task 644 review / Task 662 (Aug 2026): the original version flipped ALL
 * non-'code' rows. Live already ran that version (all 24 companies on code,
 * then Frost & Brew reverted to 'token' by the 13 Aug migration), so live
 * state is correct — but in a FRESH migration sequence this file runs AFTER
 * the (earlier-dated) Frost & Brew token migration and would clobber it,
 * and any env replaying it would also erase deliberate 'token'/'off'
 * choices. Rollout migrations must never rewrite an explicit per-company
 * choice — only unset/NULL rows may be flipped.
 *
 * Prod applies this via `php artisan migrate --force` (never seeds).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies') || !Schema::hasColumn('companies', 'order_match_style')) {
            return;
        }

        // Flip genuinely UNSET rows FIRST — the column change() below rebuilds
        // the table as NOT NULL on some drivers, which would fail while NULL
        // rows still exist.
        DB::table('companies')
            ->whereNull('order_match_style')
            ->update(['order_match_style' => 'code']);

        Schema::table('companies', function (Blueprint $table) {
            $table->string('order_match_style', 10)->default('code')->change();
        });
    }

    public function down(): void
    {
        // Owner decision — revert via a fresh migration (or per-company Receipt Settings).
    }
};
