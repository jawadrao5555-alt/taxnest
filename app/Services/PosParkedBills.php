<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parked ("rukay hue") bills — unfinished carts a counter set aside so it could
 * serve the next customer. PRA retail keeps them in pos_held_sales, FBR in
 * fbr_pos_held_sales; both are JSON carts, never transactions.
 *
 * Owner, 23 Aug 2026: neither list was ever cleaned, so months-old parked carts
 * piled up behind the Recall button. Day close is the natural broom — a cart
 * left parked from an earlier day is abandoned by definition, since the day it
 * belonged to is now closed and it was never part of its totals.
 */
class PosParkedBills
{
    public const PRA_TABLE = 'pos_held_sales';
    public const FBR_TABLE = 'fbr_pos_held_sales';

    /**
     * Does this shop park carts here at all?
     *
     * ONLY a plain retail shop — no tables, no KOT, no kitchen, no delivery.
     * A restaurant-shaped shop keeps the held restaurant-order flow: its sale
     * screen shows THOSE, so a cart parked in this lane would be invisible to
     * it (and would skip tables/KOT entirely). The sale screen hides the button,
     * but the button is not an authorization boundary — the endpoints ask this
     * same question, so a crafted request cannot open a second lane.
     */
    public static function retailShop(?Company $company): bool
    {
        if (!$company) {
            return false;
        }

        return !self::restaurantShaped(PosFeatureService::forCompany($company));
    }

    /** Same question when the caller already resolved the feature object. */
    public static function restaurantShaped(?object $features): bool
    {
        return ($features->tables ?? false)
            || ($features->kot ?? false)
            || ($features->kitchen ?? false)
            || ($features->delivery ?? false);
    }

    /** How many carts this shop currently has parked (0 on a pre-migration schema). */
    public static function count(string $table, ?int $companyId): int
    {
        if (!$companyId || !Schema::hasTable($table)) {
            return 0;
        }

        try {
            return (int) DB::table($table)->where('company_id', $companyId)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Sweep carts parked BEFORE the day being closed. Deliberately conservative:
     * anything parked during (or after) that day survives, so a cashier who
     * parks a bill an hour before closing still finds it in the morning.
     */
    public static function purgeBeforeDay(string $table, ?int $companyId, string $businessDate): int
    {
        if (!$companyId || !Schema::hasTable($table)) {
            return 0;
        }

        try {
            $cutoff = Carbon::parse($businessDate)->startOfDay();
        } catch (\Throwable $e) {
            return 0;
        }

        try {
            return (int) DB::table($table)
                ->where('company_id', $companyId)
                ->where('created_at', '<', $cutoff)
                ->delete();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
