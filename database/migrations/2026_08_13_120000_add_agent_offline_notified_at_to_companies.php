<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 630: owner-facing "agent offline" alert dedup flag.
 * Set when the offline email is sent; cleared when the agent heartbeats again,
 * so each OUTAGE notifies exactly once. Idempotent per-column guard (PROD
 * schema-drift self-heal convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'agent_offline_notified_at')) {
                $table->timestamp('agent_offline_notified_at')->nullable()->after('agent_snapshot_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'agent_offline_notified_at')) {
                $table->dropColumn('agent_offline_notified_at');
            }
        });
    }
};
