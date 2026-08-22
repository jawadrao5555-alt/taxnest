<?php

namespace App\Services;

use App\Models\SystemSetting;
use InvalidArgumentException;

/**
 * PRA POS paid add-on catalogue and pricing.
 *
 * Rates are stored as SystemSetting values so an admin can change them without
 * a code deploy. The defaults are deliberately kept here as the safe fallback
 * for a fresh database or a lagging environment.
 */
class PosAddonPricingService
{
    public const DEFAULT_ANNUAL_PRICE = 12000;
    public const DEFAULT_QUARTERLY_PRICE = 3000;

    /**
     * Package-included features are intentionally absent from this catalogue:
     * Custom Access, Delivery Riders and QR Menu are included from Business
     * upward, while Staff Attendance is included from Pro upward.
     */
    public const ADDONS = [
        'whatsapp_bill' => [
            'label' => 'WhatsApp Bill',
            'description' => 'Send bills to customers on WhatsApp',
            'gate' => 'whatsapp_enabled',
        ],
        'rider_tracking' => [
            'label' => 'Rider Live Tracking',
            'description' => 'Show rider location live on a map',
            'gate' => 'rider_tracking_enabled',
        ],
        'caller_id' => [
            'label' => 'Caller ID',
            'description' => 'Customer popup on incoming calls',
            'gate' => 'caller_id_enabled',
        ],
    ];

    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::ADDONS as $code => $addon) {
            $catalog[$code] = array_merge($addon, [
                'annual_price' => self::price($code, 'annual'),
                'quarterly_price' => self::price($code, 'quarterly'),
            ]);
        }

        return $catalog;
    }

    public static function price(string $code, string $cycle = 'annual'): int
    {
        if (!isset(self::ADDONS[$code])) {
            throw new InvalidArgumentException("Unknown PRA POS add-on: {$code}");
        }

        $cycle = match ($cycle) {
            'annual', 'yearly' => 'annual',
            'quarterly' => 'quarterly',
            default => throw new InvalidArgumentException("Unsupported add-on billing cycle: {$cycle}"),
        };

        $default = $cycle === 'annual'
            ? self::DEFAULT_ANNUAL_PRICE
            : self::DEFAULT_QUARTERLY_PRICE;

        $raw = SystemSetting::get(self::settingKey($code, $cycle), (string) $default);
        return max(0, (int) round((float) $raw));
    }

    /**
     * Save only known add-ons and cycles. The controller validates the request;
     * this second allow-list keeps the service safe for future callers too.
     *
     * @param array<string, array{annual:mixed, quarterly:mixed}> $prices
     */
    public static function save(array $prices): void
    {
        foreach (self::ADDONS as $code => $addon) {
            foreach (['annual', 'quarterly'] as $cycle) {
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