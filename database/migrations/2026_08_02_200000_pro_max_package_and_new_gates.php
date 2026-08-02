<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pro Max package + Custom Access / QR Menu plan gates (owner-approved 2 Aug 2026).
 *
 * New POS package matrix:
 *   Trial     → gate columns 0 (access comes from the ACTIVE-trial rule)
 *   Starter   → core billing only
 *   Business  → + deals, advanced reports & exports
 *   Pro       → + restaurant module & analytics (riders/hazri/QR menu REMOVED)
 *   Pro Max   → NEW Rs 34,999/yr: Pro + riders & khata + hazri + public QR menu
 *   Unlimited → everything, incl. Team Custom Access (ONLY here) + no limits
 *
 * New boolean gate columns default TRUE (fail open) so non-'pos' product
 * types (di/fbrpos/standalone) and a lagging PROD migrate never lock anyone.
 * Idempotent: hasColumn/exists guards + deterministic UPDATEs (prod runs
 * migrate --force via deploy script, never seeds).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_plans', function (Blueprint $table) {
            foreach (['custom_access_enabled', 'qr_menu_enabled'] as $col) {
                if (!Schema::hasColumn('pricing_plans', $col)) {
                    $table->boolean($col)->default(true);
                }
            }
        });

        // ── Pro Max row (insert once) ──────────────────────────────────
        $exists = DB::table('pricing_plans')
            ->where('product_type', 'pos')->where('name', 'Pro Max')->exists();
        if (!$exists) {
            DB::table('pricing_plans')->insert([
                'name' => 'Pro Max',
                'product_type' => 'pos',
                'price' => 34999, // POS plans store ANNUAL price
                'price_monthly' => null,
                'is_trial' => false,
                'invoice_limit' => 5000,
                'user_limit' => 15,
                'branch_limit' => 3,
                'max_terminals' => -1,
                'max_users' => -1,
                'max_products' => -1,
                'inventory_enabled' => 1,
                'reports_enabled' => 1,
                'restaurant_enabled' => 1,
                'deals_enabled' => 1,
                'riders_enabled' => 1,
                'hazri_enabled' => 1,
                'analytics_enabled' => 1,
                'rider_tracking_enabled' => 0, // Unlimited-only
                'custom_access_enabled' => 0,  // Unlimited-only
                'qr_menu_enabled' => 1,
                'features' => json_encode([
                    'Delivery Riders — rider khata, settlements & rider portal',
                    'Staff Hazri (attendance) report',
                    'Public QR Menu page — customers scan & browse your menu online',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ── Gate matrix (ONLY product_type='pos'; others stay fail-open TRUE) ──
        $matrix = [
            // name => [riders, hazri, qr_menu, custom_access]
            // Trial row stays 0: access comes from the ACTIVE-trial rule in
            // planAllows, so expired trials lock cleanly.
            'Trial' => [0, 0, 0, 0],
            'Starter' => [0, 0, 0, 0],
            'Business' => [0, 0, 0, 0],
            'Pro' => [0, 0, 0, 0],
            'Pro Max' => [1, 1, 1, 0],
            'Unlimited' => [1, 1, 1, 1],
        ];
        foreach ($matrix as $name => $m) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', $name)
                ->update([
                    'riders_enabled' => $m[0],
                    'hazri_enabled' => $m[1],
                    'qr_menu_enabled' => $m[2],
                    'custom_access_enabled' => $m[3],
                ]);
        }

        // ── Refreshed DISPLAY feature lists (billing renders cumulatively:
        //    "Everything in <prev>, plus:") ───────────────────────────────
        $features = [
            'Pro' => [
                'Full Restaurant module — dine-in tables, KOT, kitchen display & waiter tablets',
                'Cancelled-orders & kitchen-waste report',
                'Day-close auto-finalize for pending bills',
                'Advanced analytics dashboard',
            ],
            'Pro Max' => [
                'Delivery Riders — rider khata, settlements & rider portal',
                'Staff Hazri (attendance) report',
                'Public QR Menu page — customers scan & browse your menu online',
            ],
            'Unlimited' => [
                'UNLIMITED bills every month — no cap, ever (Pro Max: 5,000/month)',
                'UNLIMITED team accounts — every cashier, manager, waiter & kitchen login you need (Pro Max stops at 15)',
                'UNLIMITED branches — run all your locations on one account (Pro Max: 3)',
                'Team Custom Access — choose exactly which features each member sees',
                'Every current AND future feature unlocked forever — nothing is ever locked again',
                'Priority Support with free onboarding & staff training',
                'Built for chains, franchises & multi-branch restaurants',
            ],
        ];
        foreach ($features as $name => $list) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', $name)
                ->update(['features' => json_encode($list)]);
        }

        // ── What's New announcement (audience 'pos') ───────────────────
        if (Schema::hasTable('app_updates')) {
            $title = 'Naya package: Pro Max (Rs 34,999/saal) — Riders & Khata, Staff Hazri aur QR Menu';
            if (!DB::table('app_updates')->where('title', $title)->exists()) {
                DB::table('app_updates')->insert([
                    'title' => $title,
                    'points' => json_encode([
                        'Pro aur Unlimited ke beech naya Pro Max package: 5,000 bills/mahina, 15 team accounts aur 3 branches.',
                        'Delivery Riders & Khata, Staff Hazri report aur public QR Menu page ab Pro Max (ya Unlimited) se milte hain.',
                        'Pro package mein Restaurant module aur Advanced Analytics pehle ki tarah shamil hain.',
                        'Team Custom Access sirf Unlimited package mein hai — har member ke liye features chunein.',
                        'Trial wali dukanein pehle ki tarah sab features aazma sakti hain — koi tabdeeli nahi.',
                    ], JSON_UNESCAPED_UNICODE),
                    'audience' => 'pos',
                    'is_published' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('pricing_plans')
            ->where('product_type', 'pos')->where('name', 'Pro Max')->delete();
        Schema::table('pricing_plans', function (Blueprint $table) {
            foreach (['custom_access_enabled', 'qr_menu_enabled'] as $col) {
                if (Schema::hasColumn('pricing_plans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        if (Schema::hasTable('app_updates')) {
            DB::table('app_updates')
                ->where('title', 'Naya package: Pro Max (Rs 34,999/saal) — Riders & Khata, Staff Hazri aur QR Menu')
                ->delete();
        }
    }
};
