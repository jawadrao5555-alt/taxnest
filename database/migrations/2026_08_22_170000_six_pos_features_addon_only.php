<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 22 Aug 2026 (owner): the six optional PRA POS features — Delivery Riders,
 * QR Menu, WhatsApp Bill, Staff Attendance (Hazri), Rider Live Tracking and
 * Caller ID — are sold ONLY as paid add-ons (pos_addons lane, Rs 12,000/year
 * or Rs 3,000/quarter each, admin-editable). No POS package includes them any
 * more, so every plan row's gate column goes OFF; the comparison table and
 * package cards drop the rows automatically because they are generated from
 * these same columns.
 *
 * Custom Access is NOT part of this: it stays included from Business upward.
 *
 * Live impact checked before writing this: no real paying shop holds these
 * features through its plan — only the standing QA company (qa.fullaudit),
 * which gets equivalent add-on rows below so the deploy smoke checks keep
 * exercising riders / QR menu / WhatsApp exactly as a paying add-on shop
 * would. Trials are untouched (an active trial unlocks everything by rule,
 * and the Trial plan row already had all six OFF).
 */
return new class extends Migration
{
    private const GATE_COLUMNS = [
        'riders_enabled',
        'qr_menu_enabled',
        'whatsapp_enabled',
        'hazri_enabled',
        'rider_tracking_enabled',
        'caller_id_enabled',
    ];

    private const ADDON_CODES = [
        'delivery_riders',
        'qr_menu',
        'whatsapp_bill',
        'staff_attendance',
        'rider_tracking',
        'caller_id',
    ];

    public function up(): void
    {
        // Gate columns are not $fillable on PricingPlan — write through the
        // query builder, guarded per column (live cPanel schema can drift).
        foreach (self::GATE_COLUMNS as $column) {
            if (Schema::hasColumn('pricing_plans', $column)) {
                DB::table('pricing_plans')
                    ->where('product_type', 'pos')
                    ->update([$column => 0]);
            }
        }

        $this->grantQaCompanyAddons();
    }

    /**
     * The standing QA shop must keep its feature coverage through the SAME
     * lane a real customer now uses. Looked up by login email — company ids
     * differ between dev and live, so a hardcoded id would hit the wrong row.
     */
    private function grantQaCompanyAddons(): void
    {
        if (!Schema::hasTable('pos_addons') || !Schema::hasTable('users') || !Schema::hasTable('subscriptions')) {
            return;
        }

        $qaUser = DB::table('users')->where('email', 'qa.fullaudit@taxnest.com.pk')->first();
        if (!$qaUser || empty($qaUser->company_id)) {
            return; // dev / fresh installs have no QA company — nothing to grant
        }

        $sub = DB::table('subscriptions')
            ->where('company_id', $qaUser->company_id)
            ->where('active', 1)
            ->orderByDesc('end_date')
            ->first();
        if (!$sub || empty($sub->end_date)) {
            return; // add-ons always end with the subscription; no sub, no grant
        }

        $endsAt = substr((string) $sub->end_date, 0, 10);
        $today = now()->toDateString();

        foreach (self::ADDON_CODES as $code) {
            $existing = DB::table('pos_addons')
                ->where('company_id', $qaUser->company_id)
                ->where('addon_code', $code)
                ->first();

            if ($existing) {
                DB::table('pos_addons')->where('id', $existing->id)->update([
                    'active' => 1,
                    'ends_at' => $endsAt,
                    'updated_at' => now(),
                ]);
                continue;
            }

            DB::table('pos_addons')->insert([
                'company_id' => $qaUser->company_id,
                'addon_code' => $code,
                'active' => 1,
                'billing_cycle' => 'annual',
                'amount' => 0, // QA grant — not a sale
                'starts_at' => $today,
                'ends_at' => $endsAt,
                'payment_proof_id' => null,
                'subscription_id' => $sub->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // One-way pricing decision — the old "which plan included what" mix
        // is not restorable from here (and must not silently come back).
    }
};
