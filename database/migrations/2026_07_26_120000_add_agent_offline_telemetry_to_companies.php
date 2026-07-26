<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NestPOS Desktop Offline Mode telemetry (Jul 2026).
 *
 * The Desktop Agent's heartbeat now reports whether Offline Mode is ON and
 * when the sale-screen snapshot was last captured, so admins can spot shops
 * running with stale offline snapshots without visiting the PC.
 *
 * Per-column hasColumn guards: idempotent on PROD (schema-drift policy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'agent_offline_mode')) {
                $table->boolean('agent_offline_mode')->nullable()->after('agent_version');
            }
            if (!Schema::hasColumn('companies', 'agent_snapshot_at')) {
                $table->timestamp('agent_snapshot_at')->nullable()->after('agent_offline_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'agent_offline_mode')) {
                $table->dropColumn('agent_offline_mode');
            }
            if (Schema::hasColumn('companies', 'agent_snapshot_at')) {
                $table->dropColumn('agent_snapshot_at');
            }
        });
    }
};
