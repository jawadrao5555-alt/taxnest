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

            $prefix = $company->invoice_number_prefix;
            if (empty($prefix)) {
                $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $company->name), 0, 5));
                if (empty($prefix)) {
                    $prefix = 'INV';
                }
                $company->invoice_number_prefix = $prefix;
            }

            $nextNum = $company->next_invoice_number ?? 1;
            $invoiceNumber = $prefix . '-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

            $company->next_invoice_number = $nextNum + 1;
            $company->save();

            return $invoiceNumber;
        });
    }

    public static function peekNextNumber(int $companyId): string
    {
        $company = Company::find($companyId);
        if (!$company) {
            return 'INV-000001';
        }

        $prefix = $company->invoice_number_prefix;
        if (empty($prefix)) {
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $company->name), 0, 5));
            if (empty($prefix)) {
                $prefix = 'INV';
            }
        }

        $nextNum = $company->next_invoice_number ?? 1;
        return $prefix . '-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
    }
}
