<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE-FIRST POS (Jul 2026) — idempotency key for bills created while the
 * cashier's device had NO internet. The universal sale screen queues the bill
 * payload in IndexedDB with a client-generated UUID and replays it when the
 * network returns. storeInvoice() uses this column to detect "already synced"
 * replays (response lost mid-flight) and return the existing bill instead of
 * creating a duplicate.
 *
 * Idempotent + guarded per PROD schema-drift runbook (cPanel MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_transactions', 'offline_uuid')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->string('offline_uuid', 64)->nullable()->after('submission_hash');
            });
        }

        // Unique per company — MySQL allows unlimited NULLs in a unique index,
        // so normal (online) bills are unaffected.
        try {
            $existing = DB::select("SHOW INDEX FROM pos_transactions WHERE Key_name = 'pos_txn_offline_uuid_unique'");
            if (empty($existing)) {
                Schema::table('pos_transactions', function (Blueprint $table) {
                    $table->unique(['company_id', 'offline_uuid'], 'pos_txn_offline_uuid_unique');
                });
            }
        } catch (\Throwable $e) {
            // Index creation is best-effort — the application-level lookup in
            // storeInvoice() is the primary dedupe; the index is a safety net.
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_transactions', 'offline_uuid')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                try { $table->dropUnique('pos_txn_offline_uuid_unique'); } catch (\Throwable $e) {}
                $table->dropColumn('offline_uuid');
            });
        }
    }
};
