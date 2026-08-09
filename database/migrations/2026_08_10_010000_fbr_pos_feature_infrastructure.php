<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS strict feature binding — infrastructure pass (10 Aug 2026).
 *
 * Owner: "sab features lay ao, plans pe baad mein discuss karenge."
 * This migration is deliberately BEHAVIOUR-PRESERVING: every new/updated
 * gate value matches what companies can already do today. The FBR plan
 * ladder flip happens in a later migration once the owner picks values.
 *
 * 1) Three new plan-gate columns (khata / loyalty / kot) — default TRUE for
 *    every plan of every product, because none of these were gated before.
 * 2) FBR POS plan rows: the premium gate columns added for the PRA matrix
 *    (excel/deals/analytics/offline) defaulted to FALSE on fbrpos rows.
 *    FBR entry points get gated in this same deploy, so flip them ON to
 *    keep current behaviour until the owner decides the FBR ladder.
 * 3) fbr_pos_terminals.branch_id — counters can belong to a branch
 *    (multi-branch v1; nullable = main shop).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['khata_enabled', 'loyalty_enabled', 'kot_enabled'] as $col) {
            if (!Schema::hasColumn('pricing_plans', $col)) {
                Schema::table('pricing_plans', function (Blueprint $t) use ($col) {
                    $t->boolean($col)->default(true);
                });
            }
            // Idempotent: ON everywhere = today's ungated behaviour.
            DB::table('pricing_plans')->update([$col => true]);
        }

        DB::table('pricing_plans')->where('product_type', 'fbrpos')->update([
            'excel_enabled'     => true,
            'deals_enabled'     => true,
            'analytics_enabled' => true,
            'offline_enabled'   => true,
            'reports_enabled'   => true,
        ]);

        if (Schema::hasTable('fbr_pos_terminals') && !Schema::hasColumn('fbr_pos_terminals', 'branch_id')) {
            Schema::table('fbr_pos_terminals', function (Blueprint $t) {
                $t->unsignedBigInteger('branch_id')->nullable()->index()->after('company_id');
            });
        }
    }

    /** Data-only + additive; nothing safe to reverse. */
    public function down(): void
    {
    }
};
