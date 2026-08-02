<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 117 (Aug 2026): "Offline billing + Desktop app" is listed as a
 * Business+ feature on the billing matrix but was never enforced.
 * New plan gate column: pricing_plans.offline_enabled (PLAN_GATES /
 * planAllows pattern — fails OPEN if column missing on lagging PROD).
 *
 * Matrix (PRA POS product_type='pos'):
 *   Trial     → 0 (active-trial rule in planAllows grants it; expired locks)
 *   Starter   → 0 (offline billing + Desktop app OFF)
 *   Business+ → 1
 * Non-'pos' product types keep the default TRUE — nothing changes for them.
 *
 * Idempotent: hasColumn guards + deterministic UPDATEs (prod runs
 * migrate --force via deploy script).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pricing_plans', 'offline_enabled')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->boolean('offline_enabled')->default(true)->after('analytics_enabled');
            });
        }

        DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->whereIn('name', ['Trial', 'Starter'])
            ->update(['offline_enabled' => 0]);
        DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->whereNotIn('name', ['Trial', 'Starter'])
            ->update(['offline_enabled' => 1]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('pricing_plans', 'offline_enabled')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->dropColumn('offline_enabled');
            });
        }
    }
};
