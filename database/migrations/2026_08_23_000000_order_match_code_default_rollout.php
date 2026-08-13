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
 *  2. One-time flip of ALL existing companies to 'code'. Runs once, so a
 *     shop that later picks token/off in Receipt Settings is never
 *     re-overridden (per-company dropdown stays fully functional).
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

        Schema::table('companies', function (Blueprint $table) {
            $table->string('order_match_style', 10)->default('code')->change();
        });

        // Task 662 CONVENTION for every bulk order_match_style rollout: skip
        // companies whose owner manually picked a style in Receipt Settings
        // (order_match_style_locked = true). hasColumn guard: on a fresh
        // environment this migration runs BEFORE the locked column exists —
        // that's fine, nothing can be locked yet at that point.
        $query = DB::table('companies')
            ->where(function ($q) {
                $q->whereNull('order_match_style')
                  ->orWhere('order_match_style', '!=', 'code');
            });
        if (Schema::hasColumn('companies', 'order_match_style_locked')) {
            $query->where('order_match_style_locked', false);
        }
        $query->update(['order_match_style' => 'code']);
    }

    public function down(): void
    {
        // Owner decision — revert via a fresh migration (or per-company Receipt Settings).
    }
};
