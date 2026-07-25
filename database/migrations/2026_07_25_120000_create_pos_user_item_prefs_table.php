<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-USER sale-screen grid visibility (owner request, 25 Jul 2026):
 * every POS user (cashier / waiter / manager) decides which products, services
 * and deals appear on THEIR OWN grid. Full user authority — a user pref
 * overrides the admin's show_on_sale default in BOTH directions, for that
 * user's screen only. Rows exist only for EXPLICIT user overrides; no row =
 * fall back to the admin default (show_on_sale for products, visible for
 * services/deals). Search is NEVER filtered by these prefs (standing rule:
 * typed search spans the whole catalog).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_user_item_prefs')) {
            return; // idempotent — prod drift safe
        }
        Schema::create('pos_user_item_prefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('item_type', 10); // product | service | deal
            $table->unsignedBigInteger('item_id');
            $table->boolean('visible')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'item_type', 'item_id'], 'pos_user_item_prefs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_user_item_prefs');
    }
};
