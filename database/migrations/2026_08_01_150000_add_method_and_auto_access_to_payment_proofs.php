<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment-proof instant access (owner approved, Aug 2026):
 *  - payment_method: how the customer paid (bank / jazzcash / easypaisa / other).
 *  - auto_access_until: when a proof upload auto-granted a 10-day temporary
 *    override, this records the grant's expiry (NULL = no auto grant, e.g.
 *    company had a previously rejected proof).
 * Idempotent (hasTable + hasColumn guards) — safe to re-run on drifted schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_proofs')) {
            return;
        }
        if (!Schema::hasColumn('payment_proofs', 'payment_method')) {
            Schema::table('payment_proofs', function (Blueprint $table) {
                $table->string('payment_method', 30)->nullable()->after('amount');
            });
        }
        if (!Schema::hasColumn('payment_proofs', 'auto_access_until')) {
            Schema::table('payment_proofs', function (Blueprint $table) {
                $table->timestamp('auto_access_until')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('payment_proofs')) {
            return;
        }
        foreach (['payment_method', 'auto_access_until'] as $col) {
            if (Schema::hasColumn('payment_proofs', $col)) {
                Schema::table('payment_proofs', function (Blueprint $table) use ($col) {
                    $table->dropColumn($col);
                });
            }
        }
    }
};
