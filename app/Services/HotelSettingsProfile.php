<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;

/** Presentation and write gates only; never rewrite stored company flags. */
class HotelSettingsProfile
{
    public static function isAccommodation(?Company $company): bool
    {
        return $company && PosFeatureService::profileCategory($company) === 'hotel';
    }

    public static function for(Company $company, User $user): array
    {
        abort_unless($user->isPosAdmin(), 403, __('pos.only_admin_change_setting'));
        return [
            'outlet' => self::outletAvailable($company),
            'kitchen' => PosFeatureService::moduleAvailable($company, 'kitchen'),
            'kdsPrint' => PosFeatureService::moduleAvailable($company, 'kitchen')
                && PosFeatureService::moduleAvailable($company, 'kot'),
            'whatsapp' => PosFeatureService::moduleAvailable($company, 'whatsapp_enabled'),
            'caller' => PosFeatureService::moduleRelevant($company, 'caller_id_enabled')
                && PosFeatureService::planAllows($company, 'caller_id_enabled'),
            'accountAdmin' => $user->role === 'company_admin' || $user->pos_role === 'pos_admin',
        ];
    }

    public static function outletAvailable(?Company $company): bool
    {
        return HotelShell::restaurantOutletOn($company)
            && PosFeatureService::restaurantAllowed($company);
    }

    public static function assertAccountWrite(?Company $company, ?User $user): void
    {
        self::assertWrite($company, $user);
        if (self::isAccommodation($company)) {
            abort_unless($user && ($user->role === 'company_admin' || $user->pos_role === 'pos_admin'), 403, __('pos.access_denied'));
        }
    }

    public static function assertWrite(?Company $company, ?User $user, bool $outletOnly = false): void
    {
        if (!self::isAccommodation($company)) {
            return; // Other categories retain their existing authorization.
        }
        abort_unless($user?->isPosAdmin(), 403, __('pos.only_admin_change_setting'));
        if ($outletOnly) {
            abort_unless(self::outletAvailable($company), 403, __('pos.access_denied'));
        }
    }
}
