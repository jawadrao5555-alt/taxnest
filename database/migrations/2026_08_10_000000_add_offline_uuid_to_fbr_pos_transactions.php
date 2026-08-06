<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS idempotency key (Aug 2026) — mirrors the PRA POS offline_uuid
 * pattern. A client-generated UUID rides on EVERY submit attempt (online
 * and offline retries) so the server can detect a lost-response replay and
 * return the existing bill instead of creating a duplicate transaction,
 * ledger entry, or stock deduction.
 *
 * Idempotent (hasColumn + SHOW INDEX guards) so it is safe to re-run on
 * any environment including PROD. MySQL allows unlimited NULLs in a unique
 * index, so normal bills that pre-date this migration are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fbr_pos_transactions', 'offline_uuid')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->string('offline_uuid', 64)->nullable()->after('delivery_address');
            });
        }

        // Unique per company — closed race window: two concurrent identical
        // submits can't both insert (DB throws; one wins, other short-circuits
        // the app-level lookup on retry).
        try {
            $existing = DB::select("SHOW INDEX FROM fbr_pos_transactions WHERE Key_name = 'fbr_txn_offline_uuid_unique'");
            if (empty($existing)) {
                Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                    $table->unique(['company_id', 'offline_uuid'], 'fbr_txn_offline_uuid_unique');
                });
            }
        } catch (\Throwable $e) {
            // Index creation is best-effort — the app-level lookup is the
            // primary dedupe; the index is the concurrent-submit safety net.
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fbr_pos_transactions', 'offline_uuid')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                try { $table->dropUnique('fbr_txn_offline_uuid_unique'); } catch (\Throwable $e) {}
                $table->dropColumn('offline_uuid');
            });
        }
    }
};
