<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decouple PRA submission routing from agent connectivity (owner issue, 23 Jul 2026):
 * switching "Invoice Submission Mode" to Direct Production used to flip agent_enabled
 * OFF, which ALSO killed agent auth + silent printing. New column:
 *
 *   agent_submits_pra (default TRUE) — "Agent Sync" submission mode.
 *   agent_enabled                    — "the agent may connect" (auth/heartbeat/printing).
 *
 * Default TRUE preserves existing behavior exactly (agent_enabled && agent_submits_pra
 * == old agent_enabled). Idempotent — safe to re-run on prod (hasColumn guard).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'agent_submits_pra')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('agent_submits_pra')->default(true)->after('agent_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'agent_submits_pra')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('agent_submits_pra');
            });
        }
    }
};
