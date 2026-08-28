<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline queue telemetry.
 *
 * The agent heartbeat already reports whether Offline Mode is ON and how old
 * the sale-screen snapshot is. What nobody could see was the thing that
 * actually costs a shop money: how many bills are sitting in a device's
 * IndexedDB queue, unsent.
 *
 * The genuinely dangerous case is NOT "the shop is offline" — an offline shop
 * cannot report anything anyway, and its silent heartbeat already says so.
 * It is "the device is ONLINE and bills are still stuck": a poisoned bill, a
 * quota block, an expired session. That queue is invisible today, and the shop
 * only finds out at day-close when the totals do not match.
 *
 * So the sale screen reports its own queue depth whenever it is online, and
 * these columns hold the last such report.
 *
 * Idempotent per-column guards: cPanel PROD has a history of migrations marked
 * "Ran" without their columns landing, so every add is re-checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'offline_queue_depth')) {
                $table->unsignedInteger('offline_queue_depth')->nullable()->after('agent_snapshot_at');
            }
            if (!Schema::hasColumn('companies', 'offline_queue_oldest_at')) {
                $table->timestamp('offline_queue_oldest_at')->nullable()->after('offline_queue_depth');
            }
            if (!Schema::hasColumn('companies', 'offline_queue_reported_at')) {
                $table->timestamp('offline_queue_reported_at')->nullable()->after('offline_queue_oldest_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['offline_queue_depth', 'offline_queue_oldest_at', 'offline_queue_reported_at'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
