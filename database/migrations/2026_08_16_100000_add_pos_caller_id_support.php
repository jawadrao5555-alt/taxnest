<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1039 — Caller ID (Android companion app + POS sale-screen popup).
 *
 * Companies get:
 *  - caller_id_enabled        master ON/OFF toggle (POS settings, admin-only)
 *  - caller_app_token         SHA-256 of the device bearer token (one active device)
 *  - caller_app_user_id       which admin/manager signed the phone in
 *  - caller_app_device        device model string from the app
 *  - caller_app_last_seen_at  last API contact (settings-page status line)
 *
 * pos_caller_events is a tiny company-scoped ring buffer: one row per incoming
 * call (SIM or WhatsApp), auto-purged after 2 days by the ring endpoint.
 * Idempotent + hasColumn-guarded (prod-schema-drift-selfheal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'caller_id_enabled')) {
                $table->boolean('caller_id_enabled')->default(false);
            }
            if (!Schema::hasColumn('companies', 'caller_app_token')) {
                $table->string('caller_app_token', 64)->nullable();
            }
            if (!Schema::hasColumn('companies', 'caller_app_user_id')) {
                $table->unsignedBigInteger('caller_app_user_id')->nullable();
            }
            if (!Schema::hasColumn('companies', 'caller_app_device')) {
                $table->string('caller_app_device', 120)->nullable();
            }
            if (!Schema::hasColumn('companies', 'caller_app_last_seen_at')) {
                $table->timestamp('caller_app_last_seen_at')->nullable();
            }
        });

        if (!Schema::hasTable('pos_caller_events')) {
            Schema::create('pos_caller_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                // PkPhone-normalized international digits (923001234567), null
                // when the app could only read a contact NAME (WhatsApp saved
                // contact) — those match best-effort by name.
                $table->string('phone', 20)->nullable();
                $table->string('caller_name', 120)->nullable();
                $table->string('source', 12)->default('sim'); // sim | whatsapp
                $table->dateTime('ring_at');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['company_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_caller_events');
        Schema::table('companies', function (Blueprint $table) {
            foreach (['caller_id_enabled', 'caller_app_token', 'caller_app_user_id',
                      'caller_app_device', 'caller_app_last_seen_at'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
