<?php

namespace App\Services;

use App\Models\SystemSetting;
use InvalidArgumentException;

/**
 * PRA POS paid add-on catalogue and pricing.
 *
 * Rates are stored as SystemSetting values so an admin can change them without
 * a code deploy. The defaults below are the safe fallback for a fresh database
 * or a lagging environment.
 *
 * Add-ons follow the SAME three billing cycles as the package (owner, Aug
 * 2026): annual is the cheapest per month, quarterly costs ~5% more and
 * monthly ~10% more. That matters because an add-on always expires WITH the
 * package it was bought against — a monthly shop must be able to buy a
 * monthly add-on instead of paying a year for thirty days of use.
 */
class PosAddonPricingService
{
    /** The cycles an add-on can be sold on — same ladder as the packages. */
    public const CYCLES = ['annual', 'quarterly', 'monthly'];

    /** Fallback for an add-on with no per-feature price of its own. */
    public const DEFAULT_ANNUAL_PRICE = 4999;
    public const DEFAULT_QUARTERLY_PRICE = 1299;
    public const DEFAULT_MONTHLY_PRICE = 449;

    /**
     * Package-included features are intentionally absent from this catalogue:
     * Custom Access, Delivery Riders and QR Menu are included from Business
     * upward, while Staff Attendance is included from Pro upward.
     *
     * Prices are deliberately a small fraction of the package they ride on —
     * an add-on that costs half the package pushes shops to skip it entirely.
     */
    public const ADDONS = [
        'whatsapp_bill' => [
            'label' => 'WhatsApp Bill',
            'description' => 'Send bills to customers on WhatsApp',
            'gate' => 'whatsapp_enabled',
            'annual' => 4999,
            'quarterly' => 1299,
            'monthly' => 449,
        ],
        'rider_tracking' => [
            'label' => 'Rider Live Tracking',
            'description' => 'Show rider location live on a map',
            'gate' => 'rider_tracking_enabled',
            // Dearest of the three: live maps, GPS and the rider mobile app.
            'annual' => 7999,
            'quarterly' => 2099,
            'monthly' => 749,
        ],
        'caller_id' => [
            'label' => 'Caller ID',
            'description' => 'Customer popup on incoming calls',
            'gate' => 'caller_id_enabled',
            'annual' => 4999,
            'quarterly' => 1299,
            'monthly' => 449,
        ],
    ];

    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::ADDONS as $code => $addon) {
            $catalog[$code] = array_merge($addon, [
                'annual_price' => self::price($code, 'annual'),
                'quarterly_price' => self::price($code, 'quarterly'),
                'monthly_price' => self::price($code, 'monthly'),
            ]);
        }

        return $catalog;
    }

    /**
     * Normalise a requested cycle to one this catalogue actually sells.
     * Unknown / DI-only values fall back to ANNUAL, never to the dearest rate.
     */
    public static function normalizeCycle(?string $cycle): string
    {
        $cycle = mb_strtolower(trim((string) $cycle));
        $cycle = $cycle === 'yearly' ? 'annual' : $cycle;

        return in_array($cycle, self::CYCLES, true) ? $cycle : 'annual';
    }

    /** Number of months represented by one advertised cycle rate. */
    public static function monthsForCycle(?string $cycle): int
    {
        return match (self::normalizeCycle($cycle)) {
            'monthly' => 1,
            'quarterly' => 3,
            default => 12,
        };
    }

    public static function price(string $code, string $cycle = 'annual'): int
    {
        if (!isset(self::ADDONS[$code])) {
            throw new InvalidArgumentException("Unknown PRA POS add-on: {$code}");
        }

        $cycle = match ($cycle) {
            'annual', 'yearly' => 'annual',
            'quarterly' => 'quarterly',
            'monthly' => 'monthly',
            default => throw new InvalidArgumentException("Unsupported add-on billing cycle: {$cycle}"),
        };

        $default = self::ADDONS[$code][$cycle] ?? match ($cycle) {
            'quarterly' => self::DEFAULT_QUARTERLY_PRICE,
            'monthly' => self::DEFAULT_MONTHLY_PRICE,
            default => self::DEFAULT_ANNUAL_PRICE,
        };

        $raw = SystemSetting::get(self::settingKey($code, $cycle), (string) $default);
        return max(0, (int) round((float) $raw));
    }

    /**
     * Save only known add-ons and cycles. The controller validates the request;
     * this second allow-list keeps the service safe for future callers too.
     *
     * @param array<string, array{annual?:mixed, quarterly?:mixed, monthly?:mixed}> $prices
     */
    public static function save(array $prices): void
    {
        foreach (self::ADDONS as $code => $addon) {
            foreach (self::CYCLES as $cycle) {
                if (!array_key_exists($code, $prices) || !array_key_exists($cycle, $prices[$code])) {
                    continue;
                }

                $value = max(0, (int) round((float) $prices[$code][$cycle]));
                SystemSetting::set(
                    self::settingKey($code, $cycle),
                    (string) $value,
                    'PRA POS paid add-on pricing'
                );
            }
        }
    }

    public static function settingKey(string $code, string $cycle): string
    {
        return "pos_addon_{$code}_{$cycle}_price";
    }
}
