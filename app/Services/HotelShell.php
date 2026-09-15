<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;

/**
 * Hotel / Guest House category-native chrome.
 *
 * Does not change stay, folio, tax or fiscal engines. Existing companies keep
 * restaurant_mode, rooms flag, checkout policy and custom access as saved.
 */
class HotelShell
{
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
     * Hide generic New Sale when Hotel is the operating surface.
     * Hotels that already saved kitchen/restaurant_mode keep the sale screen.
     */
    public static function hideGenericSale(?Company $company): bool
    {
        return self::isNativeCategory($company) && !((bool) ($company->restaurant_mode ?? false));
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

        return '/pos/invoice/create';
    }

    public static function panelHomeUrl(?User $user, ?Company $company, bool $isRestaurantLayout): string
    {
        if (self::isNativeCategory($company) && HotelAccessService::canFrontDesk($user)) {
            return route('pos.hotel.dashboard');
        }
        if (self::roomsOn($company) && HotelAccessService::canHousekeeping($user) && !HotelAccessService::canFrontDesk($user)) {
            return route('pos.hotel.housekeeping');
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
