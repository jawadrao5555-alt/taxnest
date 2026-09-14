<?php

namespace App\Services;

use App\Models\User;

/**
 * Hotel / Guest House staff gates on the POS panel.
 *
 * Deliberately independent of Healthcare IPD roles (health_receptionist,
 * health_nurse, …). Hotel uses existing POS roles + Custom Access ticks:
 *
 *   manager / owner  — room master data, out-of-service, front desk, HK
 *   cashier          — receptionist / front desk (stays, folio) when Custom
 *                      Access is unset or 'hotel' is ticked
 *   housekeeping     — rooms board + housekeeping only ('hotel_housekeeping')
 */
class HotelAccessService
{
    public static function isManager(?User $user): bool
    {
        return (bool) $user?->isPosAdmin();
    }

    public static function canFrontDesk(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        if (self::isManager($user)) {
            return true;
        }
        if (($user->pos_role ?? '') !== 'pos_cashier') {
            return false;
        }
        $custom = PosAccessService::customAllows($user, 'hotel');

        return $custom !== false;
    }

    public static function canHousekeeping(?User $user): bool
    {
        if (self::canFrontDesk($user)) {
            return true;
        }

        return PosAccessService::customAllows($user, 'hotel_housekeeping') === true;
    }

    /**
     * Occupancy on retail/restaurant dashboards and day-close is Hotel data.
     * Show it only to Hotel front-desk or Hotel Housekeeping staff.
     */
    public static function canSeeOccupancy(?User $user): bool
    {
        return self::canHousekeeping($user);
    }

    public static function canManageRooms(?User $user): bool
    {
        return self::isManager($user);
    }

    public static function abortUnlessFrontDesk(?User $user): void
    {
        if (!self::canFrontDesk($user)) {
            abort(403, __('pos.custom_access_denied'));
        }
    }

    public static function abortUnlessHousekeeping(?User $user): void
    {
        if (!self::canHousekeeping($user)) {
            abort(403, __('pos.custom_access_denied'));
        }
    }

    public static function abortUnlessManageRooms(?User $user): void
    {
        if (!self::canManageRooms($user)) {
            abort(403, __('pos.custom_access_denied'));
        }
    }
}
