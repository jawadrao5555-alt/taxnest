<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Schema;

/**
 * Outstanding-balance check-out for Hotel / Guest House.
 *
 * V1 allowed check-out with a remaining folio due (confirm in UI, folio stays
 * open for collection). That remains the default when the setting is missing
 * so existing companies are not blocked by this delivery pass.
 *
 *   allow — V1 behaviour (server permits check-out with due)
 *   block — refuse check-out until fiscal outstanding is 0
 */
class HotelCheckoutPolicy
{
    public const ALLOW = 'allow';
    public const BLOCK = 'block';

    public static function forCompany(?Company $company): string
    {
        if (!$company || !Schema::hasColumn('companies', 'hotel_checkout_outstanding')) {
            return self::ALLOW;
        }
        $stored = strtolower(trim((string) ($company->hotel_checkout_outstanding ?? '')));

        return $stored === self::BLOCK ? self::BLOCK : self::ALLOW;
    }

    public static function allowsOutstandingCheckout(?Company $company): bool
    {
        return self::forCompany($company) === self::ALLOW;
    }
}
