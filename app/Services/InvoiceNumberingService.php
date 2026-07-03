<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceNumberingService
{
    /**
     * Per-company sequential invoice numbering.
     *
     * Format: {identifier}DI{NNNNN} where identifier is the company's FBR
     * registration number (13 digits) or NTN prefix — so every company runs
     * its OWN 1,2,3… sequence and numbers can never collide across companies.
     *
     * Historical invoices (timestamp-suffix format {identifier}DI{13-digit-ms})
     * are never renumbered; old and new formats cannot collide because the
     * suffix lengths differ.
     */
    public static function generateNextNumber(int $companyId): string
    {
        return DB::transaction(function () use ($companyId) {
            $company = Company::where('id', $companyId)->lockForUpdate()->first();

            if (!$company) {
                throw new \RuntimeException("Company not found: {$companyId}");
            }

            $identifier = self::resolveRegistrationIdentifier($company);

            $seq = max(1, (int) ($company->next_invoice_number ?? 1));

            // Cross-company safety: two companies could share the same NTN
            // identifier (branch registrations). Bump until globally free.
            do {
                $candidate = $identifier . 'DI' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
                $taken = Invoice::withoutGlobalScopes()
                    ->where(function ($q) use ($candidate) {
                        $q->where('invoice_number', $candidate)
                          ->orWhere('internal_invoice_number', $candidate);
                    })
                    ->exists();
                if ($taken) {
                    $seq++;
                }
            } while ($taken);

            $company->next_invoice_number = $seq + 1;
            $company->save();

            return $candidate;
        });
    }

    public static function peekNextNumber(int $companyId): string
    {
        $company = Company::find($companyId);
        if (!$company) {
            return '0000000DI00001';
        }

        $identifier = self::resolveRegistrationIdentifier($company);
        $seq = max(1, (int) ($company->next_invoice_number ?? 1));

        return $identifier . 'DI' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
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
