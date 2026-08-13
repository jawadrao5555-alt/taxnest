<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business plan gains Kitchen mode + Analytics (owner decision, Aug 2026).
 *
 * Strategy is market capture, not revenue: small cafes/dhabas that won't pay
 * 34,999 for Pro can get the FULL kitchen experience (KOT, KDS, tables,
 * kitchen notes, recipes) plus the Analytics dashboard on Business (24,999).
 * Pro keeps its own differentiators: Delivery Riders, Public QR Menu and
 * bigger limits (10,000 bills / 10 users / 2 branches).
 *
 * Gates are DATA (pricing_plans columns):
 *   - restaurant_enabled  → PosFeatureService::restaurantAllowed()
 *   - analytics_enabled   → PosFeatureService::planAllows()
 * These columns are deliberately NOT $fillable on PricingPlan — migrations
 * are their only write path, so DB::table() updates are used here.
 *
 * features JSON is DISPLAY-ONLY marketing copy (plan cards on pos/landing +
 * pos/billing). Cards render cumulatively ("Everything in <prev>, plus:"),
 * so Business gains the kitchen/analytics lines and Pro's list shrinks to
 * its true Pro-only extras.
 *
 * Idempotent: plain UPDATEs keyed by product_type + name, schema-tolerant
 * hasColumn guards (prod runs `migrate --force`, never seeds). PRA POS only —
 * FBR POS / DI ladders untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        // ── Business: unlock Restaurant module + Analytics dashboard ──────
        $bizUpdate = [];
        if (Schema::hasColumn('pricing_plans', 'restaurant_enabled')) {
            $bizUpdate['restaurant_enabled'] = 1;
        }
        if (Schema::hasColumn('pricing_plans', 'analytics_enabled')) {
            $bizUpdate['analytics_enabled'] = 1;
        }
        if (Schema::hasColumn('pricing_plans', 'features')) {
            $bizUpdate['features'] = json_encode([
                'Full Restaurant module — dine-in tables, KOT, kitchen display & waiter tablets',
                'Advanced analytics dashboard',
                'Cancelled-orders & kitchen-waste report',
                'Offline billing with auto-sync + Desktop app & silent printing',
                'Multi-terminal support (3 counters)',
                'Deals & combo pricing',
                'Advanced reports with CSV & PDF export',
                'Product Excel import / export',
            ]);
        }
        if ($bizUpdate) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', 'Business')
                ->update($bizUpdate);
        }

        // ── Pro: card copy shrinks to true Pro-only extras (gates untouched —
        //    riders/QR/limits stay exactly where they were) ─────────────────
        if (Schema::hasColumn('pricing_plans', 'features')) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', 'Pro')
                ->update(['features' => json_encode([
                    'Delivery Riders — rider khata, settlements & rider portal',
                    'Public QR Menu page — customers scan & browse your menu online',
                    '2 branches, unlimited counters & products',
                ])]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        $bizRevert = [];
        if (Schema::hasColumn('pricing_plans', 'restaurant_enabled')) {
            $bizRevert['restaurant_enabled'] = 0;
        }
        if (Schema::hasColumn('pricing_plans', 'analytics_enabled')) {
            $bizRevert['analytics_enabled'] = 0;
        }
        if (Schema::hasColumn('pricing_plans', 'features')) {
            $bizRevert['features'] = json_encode([
                'Offline billing with auto-sync + Desktop app & silent printing',
                'Multi-terminal support (3 counters)',
                'Deals & combo pricing',
                'Advanced reports with CSV & PDF export',
                'Product Excel import / export',
            ]);
        }
        if ($bizRevert) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', 'Business')
                ->update($bizRevert);
        }

        if (Schema::hasColumn('pricing_plans', 'features')) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', 'Pro')
                ->update(['features' => json_encode([
                    'Full Restaurant module — dine-in tables, KOT, kitchen display & waiter tablets',
                    'Delivery Riders — rider khata, settlements & rider portal',
                    'Public QR Menu page — customers scan & browse your menu online',
                    'Advanced analytics dashboard',
                    'Cancelled-orders & kitchen-waste report',
                    '2 branches, unlimited counters & products',
                ])]);
        }
    }
};
