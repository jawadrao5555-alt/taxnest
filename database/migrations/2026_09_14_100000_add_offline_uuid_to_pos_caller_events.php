<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAN Mode — offline caller rings replayed by the Desktop Agent.
 *
 * When the shop's internet is down the Caller ID phone posts its rings to the
 * agent's LAN server instead of the cloud, so the cashier still gets the popup
 * on the counter PC. Once the line is back the agent forwards those rings here
 * for HISTORY (bell list, customer matching, call-back marks).
 *
 * offline_uuid is the ring's identity, minted on the phone. The agent retries
 * until the server acknowledges, so the same ring can arrive twice — this
 * column (plus its unique index) is what makes the second copy a no-op, the
 * same contract offline_uuid carries on POS bills.
 *
 * Idempotent + column-guarded (prod carries known schema drift and runs
 * migrate --force).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_caller_events')) {
            return;
        }
        if (!Schema::hasColumn('pos_caller_events', 'offline_uuid')) {
            Schema::table('pos_caller_events', function (Blueprint $table) {
                // NULL for every ring that reached the cloud directly — NULLs
                // stay distinct under a unique index in both MySQL and SQLite,
                // so live rings never collide with each other.
                $table->string('offline_uuid', 64)->nullable()->after('company_id');
            });
        }

        // The index is added on its own pass, NOT inside the column guard: a
        // drifted database can already carry the column without it, and then
        // the whole uuid contract is only as strong as a read-then-write race.
        // Adding it twice throws — which is the "already correct" case.
        try {
            Schema::table('pos_caller_events', function (Blueprint $table) {
                $table->unique(['company_id', 'offline_uuid'], 'pos_caller_events_offline_uuid_unique');
            });
        } catch (\Throwable $e) {
            // Index already present.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('pos_caller_events') || !Schema::hasColumn('pos_caller_events', 'offline_uuid')) {
            return;
        }
        Schema::table('pos_caller_events', function (Blueprint $table) {
            try { $table->dropUnique('pos_caller_events_offline_uuid_unique'); } catch (\Throwable $e) {}
            $table->dropColumn('offline_uuid');
        });
    }
};
