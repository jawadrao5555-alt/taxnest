<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;

/**
 * Hotel / Guest House category-native chrome.
 *
 * Does not change stay, folio, tax or fiscal engines. Existing companies keep
 * restaurant_mode, rooms flag, checkout policy and custom access as saved.
 *
 * Restaurant_mode ON never turns the whole Hotel shell back into generic POS:
 * kitchen/sale lives under a separated Restaurant Outlet module.
 */
class HotelShell
{
    public const OUTLET_SESSION_KEY = 'hotel_restaurant_outlet';

    public static function roomsOn(?Company $company): bool
    {
        if (!$company) {
            return false;
        }
        $flags = PosFeatureService::forCompany($company);

        return !empty($flags->rooms);
    }

    public static function isNativeCategory(?Company $company): bool
    {
        return ($company->business_category ?? '') === 'hotel' && self::roomsOn($company);
    }

    /**
     * Hide generic New Sale from the primary Hotel shell always.
     * Hotels that already saved kitchen/restaurant_mode reach sale via
     * Restaurant Outlet — not as the dominant Hotel action.
     */
    public static function hideGenericSale(?Company $company): bool
    {
        return self::isNativeCategory($company);
    }

    /**
     * Saved restaurant_mode (and kitchen/KOT/tables master) stays as-is.
     * When ON on a Hotel company, expose a separated outlet entry.
     */
    public static function restaurantOutletOn(?Company $company): bool
    {
        return self::isNativeCategory($company) && (bool) ($company->restaurant_mode ?? false);
    }

    public static function enterRestaurantOutlet(): void
    {
        session([self::OUTLET_SESSION_KEY => true]);
    }

    public static function leaveRestaurantOutlet(): void
    {
        session()->forget(self::OUTLET_SESSION_KEY);
    }

    public static function inRestaurantOutlet(): bool
    {
        return (bool) session(self::OUTLET_SESSION_KEY);
    }

    /**
     * Who may open the Restaurant Outlet (or direct restaurant/sale URLs)
     * on a Hotel company. Housekeeping-only staff stay on the HK board.
     * Cashiers with a custom-access set need hotel or orders; an unset set
     * keeps the historical default (cashier may sell).
     */
    public static function canOpenRestaurantOutlet(?User $user, ?Company $company): bool
    {
        if (!self::restaurantOutletOn($company) || !$user) {
            return false;
        }
        if (HotelAccessService::canFrontDesk($user)) {
            return true;
        }
        // Housekeeping-only: never the kitchen/sale door.
        if (HotelAccessService::canHousekeeping($user) && !HotelAccessService::canFrontDesk($user)) {
            return false;
        }
        $custom = PosAccessService::customSet($user);
        if ($custom !== null) {
            return in_array('orders', $custom, true) || in_array('hotel', $custom, true);
        }

        return ($user->pos_role ?? '') === 'pos_cashier'
            || ($user->pos_role ?? '') === 'pos_manager'
            || $user->isPosAdmin();
    }

    public static function postLoginPath(?User $user): string
    {
        if (!$user) {
            return '/pos/invoice/create';
        }
        $company = $user->relationLoaded('company')
            ? $user->company
            : Company::find($user->company_id);
        if (($company->business_category ?? '') !== 'hotel' || !self::roomsOn($company)) {
            return '/pos/invoice/create';
        }
        if (HotelAccessService::canFrontDesk($user)) {
            return '/pos/hotel';
        }
        if (HotelAccessService::canHousekeeping($user)) {
            return '/pos/hotel/housekeeping';
        }
        if (self::restaurantOutletOn($company) && self::canOpenRestaurantOutlet($user, $company)) {
            return '/pos/hotel/restaurant';
        }

        // Hotel company but no Hotel / Outlet grant — generic home (sale stays gated).
        return '/pos/dashboard';
    }

    public static function panelHomeUrl(?User $user, ?Company $company, bool $isRestaurantLayout): string
    {
        if (self::isNativeCategory($company) && HotelAccessService::canFrontDesk($user)) {
            return route('pos.hotel.dashboard');
        }
        if (self::roomsOn($company) && HotelAccessService::canHousekeeping($user) && !HotelAccessService::canFrontDesk($user)) {
            return route('pos.hotel.housekeeping');
        }
        if (self::restaurantOutletOn($company) && self::canOpenRestaurantOutlet($user, $company)) {
            return route('pos.hotel.restaurant-outlet');
        }

        return $isRestaurantLayout ? route('pos.restaurant.dashboard') : route('pos.dashboard');
    }

    public static function statusLabel(string $status): string
    {
        $key = 'pos.hotel_status_'.$status;
        $translated = __($key);

        return $translated === $key ? $status : $translated;
    }
}
