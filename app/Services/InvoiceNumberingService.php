<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

class InvoiceNumberingService
{
    public static function generateNextNumber(int $companyId): string
    {
        return DB::transaction(function () use ($companyId) {
            $company = Company::where('id', $companyId)->lockForUpdate()->first();

            if (!$company) {
                throw new \RuntimeException("Company not found: {$companyId}");
            }

            $identifier = self::resolveRegistrationIdentifier($company);

            $timestampMs = (int)(microtime(true) * 1000);

            $invoiceNumber = $identifier . 'DI' . $timestampMs;

            $company->next_invoice_number = ($company->next_invoice_number ?? 1) + 1;
            $company->save();

            return $invoiceNumber;
        });
    }

    public static function peekNextNumber(int $companyId): string
    {
        $company = Company::find($companyId);
        if (!$company) {
            return '0000000DI' . (int)(microtime(true) * 1000);
        }

        $identifier = self::resolveRegistrationIdentifier($company);

        $timestampMs = (int)(microtime(true) * 1000);
        return $identifier . 'DI' . $timestampMs;
    }

    private static function resolveRegistrationIdentifier(Company $company): string
    {
        $regNo = $company->fbr_registration_no ?? '';
        $cleanRegNo = preg_replace('/[^0-9]/', '', $regNo);

        if (strlen($cleanRegNo) === 13) {
            return $cleanRegNo;
        }

        if (strlen($cleanRegNo) >= 7) {
            return substr($cleanRegNo, 0, 7);
        }

        $ntn = $company->ntn ?? '';
        $cleanNtn = preg_replace('/[^0-9]/', '', $ntn);

        if (strlen($cleanNtn) >= 7) {
            return substr($cleanNtn, 0, 7);
        }

        if (!empty($cleanRegNo)) {
            return str_pad($cleanRegNo, 7, '0', STR_PAD_LEFT);
        }
        if (!empty($cleanNtn)) {
            return str_pad($cleanNtn, 7, '0', STR_PAD_LEFT);
        }

        return '0000000';
    }
}
