<?php

namespace App\Services;

use App\Models\Company;

class HotelCreditNoteActivation
{
    public static function issuance(int $companyId): bool
    {
        return (bool) config('hotel_credit_notes.enabled', false)
            && (Company::findOrFail($companyId)->feature_flags['hotel_credit_issuance'] ?? false) === true;
    }

    public static function refunds(int $companyId): bool
    {
        return (bool) config('hotel_credit_notes.enabled', false)
            && (Company::findOrFail($companyId)->feature_flags['hotel_credit_refunds'] ?? false) === true;
    }
}
