<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1101 — Caller ID v2: multi-device pairing.
 *
 * pos_caller_devices: one row per paired phone (SIM phone + WhatsApp phone can
 * both stay signed in). Token is stored as SHA-256, same as the legacy
 * companies.caller_app_token — which stays in place for backward compat: an
 * already-paired beta phone keeps working without re-login.
 * Idempotent + guarded (prod-schema-drift-selfheal).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_caller_devices')) {
            Schema::create('pos_caller_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('device', 120)->nullable();
                $table->string('token_hash', 64);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'token_hash']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_caller_devices');
    }
};
