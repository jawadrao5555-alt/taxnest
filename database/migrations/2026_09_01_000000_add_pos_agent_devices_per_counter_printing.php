<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1166 — Per-counter silent printer routing ("har cashier ka apna printer").
 *
 * Multi-counter shops run the Desktop Agent on SEVERAL PCs with the SAME
 * company agent_api_key (owner-approved Option A — no per-cashier keys).
 * Each agent install now self-identifies with a persistent device UID so:
 *
 * - pos_agent_devices: one row per counter PC (per-company). Stores the
 *   hostname-derived friendly name, last-seen beat, that PC's own reported
 *   printer list, and the admin-chosen per-device receipt printer.
 * - pos_print_jobs.device_uid: bill/proof jobs created by a cashier who is
 *   ASSIGNED to a counter are stamped with that counter's UID — only that
 *   counter's agent claims them. NULL = legacy/company-wide job (today's
 *   behavior, claimable by any agent).
 * - users.pos_device_uid: the per-team-member counter assignment set on the
 *   Printer Settings page. NULL = no assignment → company default routing.
 *
 * Idempotent + per-column guards (prod schema drift convention). No FKs by
 * convention (shared tables / archived rows); company hard-delete purge list
 * gains 'pos_agent_devices'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_agent_devices')) {
            Schema::create('pos_agent_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('device_uid', 64);
                $table->string('hostname', 120)->nullable();
                // Admin-editable friendly name ("Counter 1"); NULL = show hostname.
                $table->string('name', 60)->nullable();
                $table->string('agent_version', 32)->nullable();
                $table->timestamp('last_seen_at')->nullable();
                // This PC's own reported printers (same shape as the company-wide
                // available_printers list) + when they were last reported.
                $table->json('printers')->nullable();
                $table->timestamp('printers_reported_at')->nullable();
                // Per-device receipt printer chosen by the admin. NULL = this
                // counter has no own pick → company default receipt printer.
                $table->string('receipt_printer', 255)->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'device_uid'], 'pos_agent_devices_co_uid_uq');
                $table->index('company_id');
            });
        }

        if (!Schema::hasColumn('pos_print_jobs', 'device_uid')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->string('device_uid', 64)->nullable()->after('claim_token');
            });
        }

        if (!Schema::hasColumn('users', 'pos_device_uid')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('pos_device_uid', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_agent_devices');
        if (Schema::hasColumn('pos_print_jobs', 'device_uid')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->dropColumn('device_uid');
            });
        }
        if (Schema::hasColumn('users', 'pos_device_uid')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pos_device_uid');
            });
        }
    }
};
