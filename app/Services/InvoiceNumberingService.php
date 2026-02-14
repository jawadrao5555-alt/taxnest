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

            $identifier = preg_replace('/[^0-9]/', '', $company->fbr_registration_no ?? '');
            if (empty($identifier)) {
                $identifier = preg_replace('/[^0-9]/', '', $company->ntn ?? '');
            }
            if (empty($identifier)) {
                $identifier = '0000000';
            }

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

        $identifier = preg_replace('/[^0-9]/', '', $company->fbr_registration_no ?? '');
        if (empty($identifier)) {
            $identifier = preg_replace('/[^0-9]/', '', $company->ntn ?? '');
        }
        if (empty($identifier)) {
            $identifier = '0000000';
        }

        $timestampMs = (int)(microtime(true) * 1000);
        return $identifier . 'DI' . $timestampMs;
    }
}
