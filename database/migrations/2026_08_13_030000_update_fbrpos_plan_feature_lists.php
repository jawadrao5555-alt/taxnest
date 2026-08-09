<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS plan cards: rewrite the marketing feature lists (pricing_plans.features
 * JSON) to match the strict gate ladder flipped in
 * 2026_08_13_020000_fbrpos_plan_reprice_and_strict_gating.
 *
 * Old copy promised features on cards that no longer include them (Starter
 * "Basic Reports") and omitted the real differentiators (Business offline/
 * Excel/khata/reports; Pro deals/loyalty/KOT/analytics).
 *
 * Ladder (see scripts/plan-gate-check.php + PosFeatureService::PLAN_GATES):
 *   Starter  — inventory only; NO offline/excel/khata/reports/deals/loyalty/kot/analytics.
 *   Business — + offline, Excel import/export, khata, sales reports.
 *   Pro      — everything, unlimited limits.
 *
 * Idempotent: matched by product_type+name, plain UPDATE (prod runs
 * migrate --force). Trial row has no card — features left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        $set = function (string $name, array $features): void {
            DB::table('pricing_plans')
                ->where('product_type', 'fbrpos')
                ->where('name', $name)
                ->update([
                    'features' => json_encode($features),
                    'updated_at' => now(),
                ]);
        };

        // Starter: inventory only — must NOT promise reports, offline, Excel,
        // khata, deals, loyalty, KOT, or analytics.
        $set('Starter', [
            '500 FBR bills/month',
            'FBR Real-time Submission',
            'QR Code on Receipts',
            'Inventory & Stock Management',
            'Cash & Card Payments',
            '2 Team Accounts, 100 Products',
        ]);

        // Business: + offline billing, Excel import/export, khata, sales reports.
        $set('Business', [
            '2,000 FBR bills/month',
            'Everything in Starter',
            'Offline Billing with Auto-Sync',
            'Excel Product Import/Export',
            'Khata (Customer Credit Ledger)',
            'Sales Reports',
            '5 Team Accounts, 500 Products',
        ]);

        // Pro: full feature set, unlimited limits.
        $set('Pro', [
            'Unlimited FBR bills',
            'Everything in Business',
            'Deals & Bundles',
            'Customer Loyalty Points',
            'KOT / Kitchen Printing',
            'Advanced Analytics',
            'Unlimited Team Accounts & Products',
            'Priority Support',
        ]);
    }

    public function down(): void
    {
        // Marketing copy change — restore via a fresh migration if ever needed.
    }
};
