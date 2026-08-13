<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 662 — Preserve manual Token/Code choices from future bulk rollouts.
 *
 * Why: the Aug-23 rollout migration flipped EVERY company to 'code' without
 * knowing which shops had deliberately picked 'token' in Receipt Settings
 * (Frost and Brew etc.). Any future bulk rollout could repeat that override.
 *
 * Fix: `order_match_style_locked` boolean (default false).
 *  - Set to TRUE whenever a shop manually saves the Order Matching style in
 *    Receipt Settings (both PRA + FBR POS save paths).
 *  - CONVENTION for every future rollout migration touching
 *    order_match_style: the bulk UPDATE must include
 *    `->where('order_match_style_locked', false)` (with a hasColumn guard)
 *    so it only flips companies that never made their own choice.
 *
 * Idempotent + hasColumn guards (PROD drift self-heal convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies') || Schema::hasColumn('companies', 'order_match_style_locked')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('order_match_style_locked')->default(false)->after('order_match_style');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'order_match_style_locked')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('order_match_style_locked');
            });
        }
    }
};
