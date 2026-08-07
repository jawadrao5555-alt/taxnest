<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds fbr_auto_retry_count to fbr_pos_transactions.
 *
 * Purpose: persistent server-side cap on automated retry attempts so no bill
 * can loop indefinitely through the scheduler (SyncFbrPosOfflineInvoicesJob
 * runs every 2 min) or the client auto-sync tick.
 *
 * - Incremented by every AUTOMATED retry path on failure.
 * - Reset to 0 on successful submission or explicit manual retry.
 * - When fbr_auto_retry_count >= MAX_AUTO_RETRY (5) the scheduler skips the
 *   bill silently; it remains in the Fail Queue for manual intervention.
 *
 * Idempotent (hasColumn guard): safe to re-run on any environment including
 * PROD via migrate --force. Per prod-schema-drift-selfheal convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fbr_pos_transactions', 'fbr_auto_retry_count')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->unsignedTinyInteger('fbr_auto_retry_count')
                    ->default(0)
                    ->after('fbr_submission_hash')
                    ->comment('Automated retry attempt counter. Resets on manual retry or success. Scheduler skips when >= 5.');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fbr_pos_transactions', 'fbr_auto_retry_count')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->dropColumn('fbr_auto_retry_count');
            });
        }
    }
};
