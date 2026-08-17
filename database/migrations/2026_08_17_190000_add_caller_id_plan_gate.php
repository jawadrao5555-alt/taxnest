<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner (17 Aug 2026): Caller ID (Android companion app + sale-screen popup,
 * Task 1039) becomes an Unlimited-only feature.
 *
 * New plan gate column: pricing_plans.caller_id_enabled (PLAN_GATES /
 * planAllows pattern — fails OPEN if column missing on lagging PROD).
 *
 * Matrix (PRA POS product_type='pos'):
 *   Trial     → 0 (active-trial rule in planAllows grants it; expired locks)
 *   Starter   → 0
 *   Business  → 0
 *   Pro       → 0
 *   Pro Max   → 0
 *   Unlimited → 1
 * Non-'pos' product types keep the default TRUE — nothing changes for them.
 *
 * Idempotent: hasColumn guards + deterministic UPDATEs (prod runs
 * migrate --force via deploy script).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pricing_plans', 'caller_id_enabled')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->boolean('caller_id_enabled')->default(true);
            });
        }

        DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->where('name', 'Unlimited')
            ->update(['caller_id_enabled' => 1]);
        DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->where('name', '!=', 'Unlimited')
            ->update(['caller_id_enabled' => 0]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('pricing_plans', 'caller_id_enabled')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->dropColumn('caller_id_enabled');
            });
        }
    }
};
