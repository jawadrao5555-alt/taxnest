<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite index for the Munafa report's range scan
 * (company_id + status + created_at on fbr_pos_transactions).
 * Idempotent — safe to re-run on cPanel PROD (migrate --force).
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->index(['company_id', 'status', 'created_at'], 'fbr_pos_txn_company_status_created_idx');
            });
        } catch (\Throwable $e) {
            // Index already exists — nothing to do.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->dropIndex('fbr_pos_txn_company_status_created_idx');
            });
        } catch (\Throwable $e) {
            // Index absent — nothing to do.
        }
    }
};
