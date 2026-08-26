<?php

namespace App\Services;

use App\Models\Company;

/**
 * Company-level policy for delivery bills that have no rider assigned.
 *
 * "allow" preserves the safe default: the shop is treated as having handled
 * the delivery itself, so the bill does not hold the trading day open.
 * "block" is for companies that require every delivery to be assigned and
 * settled through the rider workflow before closing.
 */
final class PosDayCloseDeliveryPolicy
{
    public const ALLOW = 'allow';
    public const BLOCK = 'block';

    public static function unassignedBlocks(?Company $company): bool
    {
        return ($company?->pos_dayclose_unassigned_delivery_action ?? self::ALLOW) === self::BLOCK;
    }

    public static function action(?Company $company): string
    {
        return self::unassignedBlocks($company) ? self::BLOCK : self::ALLOW;
    }
}