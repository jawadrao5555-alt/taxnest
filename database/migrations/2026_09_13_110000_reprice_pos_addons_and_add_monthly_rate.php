<?php

use App\Services\PosAddonPricingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRA POS add-on reprice + monthly rate (owner-approved, Aug 2026).
 *
 * Two problems this closes:
 *  1. Every add-on sat at a flat Rs 12,000/year — between a third and two
 *     thirds of the package it rides on, and all three together cost MORE than
 *     the whole Unlimited package. The new rates are a small fraction of the
 *     package, priced per feature.
 *  2. Add-ons could only be bought yearly or quarterly while a package can now
 *     be monthly. Since an add-on always expires WITH its package, a monthly
 *     shop was being asked to pay a year for thirty days of use.
 *
 * Rates stay admin-editable: this writes the new defaults into the settings
 * rows so existing installs move off the old flat price, and clears the rate
 * rows of add-ons that became package-included features.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('system_settings')) {
            return;
        }

        $prices = [];
        $liveKeys = [];
        foreach (PosAddonPricingService::ADDONS as $code => $addon) {
            foreach (PosAddonPricingService::CYCLES as $cycle) {
                $prices[$code][$cycle] = $addon[$cycle];
                $liveKeys[] = PosAddonPricingService::settingKey($code, $cycle);
            }
        }

        PosAddonPricingService::save($prices);

        // Retired add-ons (QR Menu, Staff Attendance, Delivery Riders, Custom
        // Access) are package-included features now. Their leftover rate rows
        // can never be quoted again — drop them so no admin edits a price
        // nothing reads.
        DB::table('system_settings')
            ->where('key', 'like', 'pos\_addon\_%\_price')
            ->whereNotIn('key', $liveKeys)
            ->delete();
    }

    public function down(): void
    {
        // Rates are owner-managed from the admin panel; a rollback must never
        // resurrect the old flat price over a newer decision.
    }
};
