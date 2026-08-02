<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Package ladder restructure (owner decision, Aug 2, 2026):
 *   Pro       = the FULL POS — everything except Staff Hazri + Rider Live Tracking
 *               (gains: Delivery Riders & Khata + Public QR Menu, previously Pro Max+)
 *   Pro Max   = Pro + Staff Hazri (attendance)
 *   Unlimited = everything incl. Rider Live Tracking + no limits (unchanged)
 *
 * One-line sales pitch per tier:
 *   "Pro = pura POS. Pro Max = + Staff Hazri. Unlimited = + Rider Live Tracking, koi limit nahi."
 *
 * Gates are DATA (pricing_plans columns read by PosFeatureService::planAllows) —
 * this migration IS the matrix change. Idempotent: plain UPDATEs by name.
 * Prod applies via `migrate --force` (never seeds).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        // ── Pro: + Riders & Khata, + QR Menu ────────────────────────────────
        $proUpdate = [];
        if (Schema::hasColumn('pricing_plans', 'riders_enabled')) {
            $proUpdate['riders_enabled'] = 1;
        }
        if (Schema::hasColumn('pricing_plans', 'qr_menu_enabled')) {
            $proUpdate['qr_menu_enabled'] = 1;
        }
        if (Schema::hasColumn('pricing_plans', 'features')) {
            $proUpdate['features'] = json_encode([
                'Full Restaurant module — dine-in tables, KOT, kitchen display & waiter tablets',
                'Delivery Riders — rider khata, settlements & rider portal',
                'Public QR Menu page — customers scan & browse your menu online',
                'Advanced analytics dashboard',
                'Cancelled-orders & kitchen-waste report',
                'Day-close auto-finalize for pending bills',
            ]);
        }
        if ($proUpdate) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', 'Pro')
                ->update($proUpdate);
        }

        // ── Pro Max: differentiator is now Staff Hazri (riders/QR moved to Pro) ──
        if (Schema::hasColumn('pricing_plans', 'features')) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', 'Pro Max')
                ->update(['features' => json_encode([
                    'Everything in Pro — riders & khata, QR menu, full restaurant module',
                    'Staff Hazri (attendance) — daily in/out record for every staff member',
                    '5,000 bills per month (Pro: 3,000)',
                    '15 team accounts (Pro: 10) & 3 branches (Pro: 2)',
                ])]);

            // Unlimited: advertise its real killer feature — Rider LIVE Tracking.
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', 'Unlimited')
                ->update(['features' => json_encode([
                    'Rider LIVE Tracking — see every rider\'s live location on the map',
                    'UNLIMITED bills every month — no cap, ever (Pro Max: 5,000/month)',
                    'UNLIMITED team accounts — every cashier, manager, waiter & kitchen login you need (Pro Max stops at 15)',
                    'UNLIMITED branches — run all your locations on one account (Pro Max: 3)',
                    'Team Custom Access — choose exactly which features each member sees',
                    'Every current AND future feature unlocked forever — nothing is ever locked again',
                    'Priority Support with free onboarding & staff training',
                ])]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        $proRevert = [];
        if (Schema::hasColumn('pricing_plans', 'riders_enabled')) {
            $proRevert['riders_enabled'] = 0;
        }
        if (Schema::hasColumn('pricing_plans', 'qr_menu_enabled')) {
            $proRevert['qr_menu_enabled'] = 0;
        }
        if (Schema::hasColumn('pricing_plans', 'features')) {
            $proRevert['features'] = json_encode([
                'Full Restaurant module — dine-in tables, KOT, kitchen display & waiter tablets',
                'Cancelled-orders & kitchen-waste report',
                'Day-close auto-finalize for pending bills',
                'Advanced analytics dashboard',
            ]);
        }
        if ($proRevert) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', 'Pro')
                ->update($proRevert);
        }

        if (Schema::hasColumn('pricing_plans', 'features')) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', 'Pro Max')
                ->update(['features' => json_encode([
                    'Delivery Riders — rider khata, settlements & rider portal',
                    'Staff Hazri (attendance) report',
                    'Public QR Menu page — customers scan & browse your menu online',
                ])]);

            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', 'Unlimited')
                ->update(['features' => json_encode([
                    'UNLIMITED bills every month — no cap, ever (Pro Max: 5,000/month)',
                    'UNLIMITED team accounts — every cashier, manager, waiter & kitchen login you need (Pro Max stops at 15)',
                    'UNLIMITED branches — run all your locations on one account (Pro Max: 3)',
                    'Team Custom Access — choose exactly which features each member sees',
                    'Every current AND future feature unlocked forever — nothing is ever locked again',
                    'Priority Support with free onboarding & staff training',
                    'Built for chains, franchises & multi-branch restaurants',
                ])]);
        }
    }
};
