<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aug 2026 package matrix (owner-approved): premium features become real
 * plan gates instead of "sab ke liye khula". New boolean gate columns on
 * pricing_plans + the per-plan matrix + refreshed display feature lists.
 *
 * Matrix (PRA POS product_type='pos'):
 *   Trial     → everything ON (evaluation)
 *   Starter   → core billing only (deals/riders/hazri/analytics/exports OFF)
 *   Business  → + deals, advanced reports & exports (reports_enabled ON)
 *   Pro       → + restaurant module, riders, hazri, analytics
 *   Unlimited → everything Pro has, no limits
 * Non-'pos' product types (di/fbrpos/standalone): all new gates ON so
 * nothing changes for them (their panels don't have these pages anyway).
 *
 * Idempotent: hasColumn guards + deterministic UPDATEs (prod runs
 * migrate --force via deploy script).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_plans', function (Blueprint $table) {
            foreach (['deals_enabled', 'riders_enabled', 'hazri_enabled', 'analytics_enabled'] as $col) {
                if (!Schema::hasColumn('pricing_plans', $col)) {
                    $table->boolean($col)->default(true);
                }
            }
        });

        // Default true (fail open) — now apply the real matrix to POS plans.
        $matrix = [
            // name => [deals, riders, hazri, analytics, reports]
            // Trial row stays 0 like restaurant_enabled: access comes from the
            // ACTIVE-trial rule in planAllows, so expired trials lock cleanly.
            'Trial' => [0, 0, 0, 0, 1],
            'Starter' => [0, 0, 0, 0, 0],
            'Business' => [1, 0, 0, 0, 1],
            'Pro' => [1, 1, 1, 1, 1],
            'Unlimited' => [1, 1, 1, 1, 1],
        ];
        foreach ($matrix as $name => $m) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', $name)
                ->update([
                    'deals_enabled' => $m[0],
                    'riders_enabled' => $m[1],
                    'hazri_enabled' => $m[2],
                    'analytics_enabled' => $m[3],
                    'reports_enabled' => $m[4],
                ]);
        }

        // Refreshed DISPLAY feature lists (billing page renders these
        // cumulatively: "Everything in <prev>, plus:").
        $features = [
            'Starter' => [
                'PRA fiscal receipts with QR code',
                'Fast barcode & SKU billing screen',
                'Thermal receipt printing (80mm & 58mm)',
                'Customer records, khata & purchase history',
                'Inventory & stock management',
                'Basic sales & tax reports + daily closing (Z-report)',
                'Urdu / English + dashboard themes',
            ],
            'Business' => [
                'Offline billing with auto-sync + Desktop app & silent printing',
                'Multi-terminal support (3 counters)',
                'Deals & combo pricing',
                'Advanced reports with CSV & PDF export',
                'Product Excel import / export',
            ],
            'Pro' => [
                'Full Restaurant module — dine-in tables, KOT, kitchen display, waiter tablets & QR menu',
                'Delivery Riders — rider khata, settlements & rider portal',
                'Staff Hazri (attendance) report',
                'Cancelled-orders & kitchen-waste report',
                'Day-close auto-finalize for pending bills',
                'Advanced analytics dashboard',
                'Priority support',
            ],
            'Unlimited' => [
                'UNLIMITED bills every month — no cap, ever (Pro: 3,000/month)',
                'UNLIMITED team accounts — every cashier, manager, waiter & kitchen login you need (Pro stops at 10)',
                'UNLIMITED branches — run all your locations on one account (Pro: 2)',
                'Every current AND future feature unlocked forever — nothing is ever locked again',
                'Top-priority support with free onboarding & staff training',
                'Built for chains, franchises & multi-branch restaurants',
            ],
        ];
        foreach ($features as $name => $list) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', $name)
                ->update(['features' => json_encode($list)]);
        }
    }

    public function down(): void
    {
        Schema::table('pricing_plans', function (Blueprint $table) {
            foreach (['deals_enabled', 'riders_enabled', 'hazri_enabled', 'analytics_enabled'] as $col) {
                if (Schema::hasColumn('pricing_plans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
