<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task #1142 — POS shell-app FCM device tokens.
 *
 * One row per phone with the TaxNest POS app installed and logged in.
 * Multiple devices per user allowed; token_hash (sha256 of the FCM token)
 * is the unique dedupe key (the token itself is TEXT — FCM documents no
 * length cap, same reasoning as pos_riders.fcm_token).
 *
 * No FK constraints (matches newer pos_* tables) — cleanup on company hard
 * delete is handled by the AdminCompanyController::forceDelete purge list.
 *
 * Idempotent (hasTable guard) — prod schema drift safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_app_devices')) {
            Schema::create('pos_app_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('company_id')->index();
                $table->text('fcm_token');
                $table->char('token_hash', 64)->unique();
                $table->string('app_version', 30)->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_app_devices');
    }
};
