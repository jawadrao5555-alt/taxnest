<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS package reprice + strict feature binding (owner-approved 9 Aug 2026).
 *
 * New monthly rates: Starter 999, Business 1,999, Pro 2,999 (were 499/999/1749).
 * fbrpos billing is MONTHLY — set price AND price_monthly together.
 *
 * Gate ladder (fbrpos rows were seeded permissive until this flip):
 *   Trial    — everything open (limits unchanged).
 *   Starter  — inventory only; offline/excel/khata/reports/deals/loyalty/kot/analytics OFF.
 *   Business — + offline/excel/khata/reports; deals/loyalty/kot/analytics stay OFF.
 *   Pro      — everything open, limits already -1 (unlimited).
 * Limits are NOT touched. PRA-only columns (riders/hazri/rider_tracking/
 * custom_access/qr_menu) are NOT touched — fbrpos code never reads them.
 *
 * Idempotent, matched by product_type+name (prod runs migrate --force, never
 * trusts live IDs). scripts/plan-gate-check.php matrix updated in the same
 * commit (deploy preflight).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        $gateCols = ['offline_enabled', 'excel_enabled', 'khata_enabled', 'reports_enabled',
                     'deals_enabled', 'loyalty_enabled', 'kot_enabled', 'analytics_enabled'];
        foreach ($gateCols as $col) {
            if (!Schema::hasColumn('pricing_plans', $col)) {
                return; // infrastructure migration not yet run — nothing to flip safely
            }
        }

        $set = function (string $name, array $values): void {
            DB::table('pricing_plans')
                ->where('product_type', 'fbrpos')
                ->where('name', $name)
                ->update($values + ['updated_at' => now()]);
        };

        $set('Starter', [
            'price' => 999, 'price_monthly' => 999,
            'inventory_enabled' => 1,
            'offline_enabled' => 0, 'excel_enabled' => 0, 'khata_enabled' => 0,
            'reports_enabled' => 0, 'deals_enabled' => 0, 'loyalty_enabled' => 0,
            'kot_enabled' => 0, 'analytics_enabled' => 0,
        ]);

        $set('Business', [
            'price' => 1999, 'price_monthly' => 1999,
            'inventory_enabled' => 1,
            'offline_enabled' => 1, 'excel_enabled' => 1, 'khata_enabled' => 1,
            'reports_enabled' => 1, 'deals_enabled' => 0, 'loyalty_enabled' => 0,
            'kot_enabled' => 0, 'analytics_enabled' => 0,
        ]);

        $set('Pro', [
            'price' => 2999, 'price_monthly' => 2999,
            'inventory_enabled' => 1,
            'offline_enabled' => 1, 'excel_enabled' => 1, 'khata_enabled' => 1,
            'reports_enabled' => 1, 'deals_enabled' => 1, 'loyalty_enabled' => 1,
            'kot_enabled' => 1, 'analytics_enabled' => 1,
        ]);

        // Trial: gate COLUMNS stay 0 (PRA convention) — an ACTIVE trial
        // unlocks everything via PosFeatureService::planAllows() isTrialActive
        // branch; columns=1 would leak every premium feature to EXPIRED trials.
        $set('Trial', [
            'inventory_enabled' => 1,
            'offline_enabled' => 0, 'excel_enabled' => 0, 'khata_enabled' => 0,
            'reports_enabled' => 0, 'deals_enabled' => 0, 'loyalty_enabled' => 0,
            'kot_enabled' => 0, 'analytics_enabled' => 0,
        ]);
    }

    public function down(): void
    {
        // Pricing/gate ladder change — restore via a fresh migration if ever needed.
    }
};
