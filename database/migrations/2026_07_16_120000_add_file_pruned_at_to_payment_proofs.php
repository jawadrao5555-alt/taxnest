<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retention cleanup support: when an old verified/rejected proof's FILE is
 * deleted by the scheduled `payment-proofs:prune` command, the row stays for
 * audit and `file_pruned_at` records when the file was removed.
 * Idempotent (hasTable + hasColumn guards) — safe to re-run on drifted schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_proofs')) {
            return;
        }
        if (!Schema::hasColumn('payment_proofs', 'file_pruned_at')) {
            Schema::table('payment_proofs', function (Blueprint $table) {
                $table->timestamp('file_pruned_at')->nullable()->after('reject_reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_proofs') && Schema::hasColumn('payment_proofs', 'file_pruned_at')) {
            Schema::table('payment_proofs', function (Blueprint $table) {
                $table->dropColumn('file_pruned_at');
            });
        }
    }
};
