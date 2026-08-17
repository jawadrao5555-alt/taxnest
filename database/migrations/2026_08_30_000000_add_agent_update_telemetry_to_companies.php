<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop Agent self-update telemetry (Task 1062, Aug 2026).
 *
 * Agents v1.8.0+ piggyback the LAST self-update attempt outcome on the
 * heartbeat (target version, failure stage, error, timestamp) so a shop
 * stuck on an old version is visible in saas-admin instead of silent.
 * Heartbeats from OLDER agents omit the fields — the controller only writes
 * these columns when the fields are present, so stored values never get wiped.
 *
 * Per-column hasColumn guards: idempotent on PROD (schema-drift policy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'agent_update_target')) {
                $table->string('agent_update_target', 32)->nullable()->after('agent_snapshot_at');
            }
            if (!Schema::hasColumn('companies', 'agent_update_stage')) {
                $table->string('agent_update_stage', 40)->nullable()->after('agent_update_target');
            }
            if (!Schema::hasColumn('companies', 'agent_update_error')) {
                $table->string('agent_update_error', 800)->nullable()->after('agent_update_stage');
            }
            if (!Schema::hasColumn('companies', 'agent_update_at')) {
                $table->timestamp('agent_update_at')->nullable()->after('agent_update_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['agent_update_target', 'agent_update_stage', 'agent_update_error', 'agent_update_at'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
