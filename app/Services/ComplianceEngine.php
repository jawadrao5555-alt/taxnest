<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Company;

class ComplianceEngine
{
    private const RATE_TOLERANCE = 2.0;

    private const DEDUCTION_RATE_MISMATCH = 15;
    private const DEDUCTION_BUYER_RISK = 20;
    private const DEDUCTION_BANKING_RISK = 25;
    private const DEDUCTION_STRUCTURE_ERROR = 10;

    public static function validate(Invoice $invoice): array
    {
        $invoice->load('items', 'company');
        $standardTaxRate = $invoice->company ? ($invoice->company->standard_tax_rate ?? 18.0) : 18.0;

        $flags = [
            'RATE_MISMATCH' => self::checkRateMismatch($invoice),
            'BUYER_RISK' => self::checkBuyerRisk($invoice),
            'BANKING_RISK' => self::checkBankingRisk($invoice),
            'STRUCTURE_ERROR' => self::checkStructureError($invoice),
        ];

        $deductions = self::calculateDeductions($flags);

        return [
            'flags' => $flags,
            'deductions' => $deductions,
            'total_deduction' => array_sum($deductions),
            'details' => self::getDetails($invoice, $flags),
        ];
    }

    private static function checkRateMismatch(Invoice $invoice): bool
    {
        $standardTaxRate = $invoice->company ? ($invoice->company->standard_tax_rate ?? 18.0) : 18.0;
        foreach ($invoice->items as $item) {
            $subtotal = $item->price * $item->quantity;
            if ($subtotal <= 0) continue;

            $effectiveRate = ($item->tax / $subtotal) * 100;
            $expectedRate = self::getExpectedRateForHsCode($item->hs_code, $standardTaxRate);

            if (abs($effectiveRate - $expectedRate) > self::RATE_TOLERANCE) {
                return true;
            }
        }
        return false;
    }

    private static function checkBuyerRisk(Invoice $invoice): bool
    {
        if (empty($invoice->buyer_ntn) || trim($invoice->buyer_ntn) === '') {
            return true;
        }

        $ntn = trim($invoice->buyer_ntn);

        if (!preg_match('/^\d{7}-?\d{1}$/', $ntn) && !preg_match('/^\d{13}$/', $ntn)) {
            return true;
        }

        if (empty($invoice->buyer_name) || strlen(trim($invoice->buyer_name)) < 3) {
            return true;
        }

        return false;
    }

    private static function checkBankingRisk(Invoice $invoice): bool
    {
        $threshold = 50000;

        if ($invoice->total_amount > $threshold) {
            if (empty($invoice->buyer_ntn) || trim($invoice->buyer_ntn) === '') {
                return true;
            }

            $ntn = trim($invoice->buyer_ntn);
            if (!preg_match('/^\d{7}-?\d{1}$/', $ntn)) {
                return true;
            }
        }

        return false;
    }

    private static function checkStructureError(Invoice $invoice): bool
    {
        if (empty($invoice->invoice_number)) return true;
        if (empty($invoice->buyer_name)) return true;
        if (empty($invoice->buyer_ntn)) return true;
        if ($invoice->total_amount <= 0) return true;

        if ($invoice->items->isEmpty()) return true;

        foreach ($invoice->items as $item) {
            if (empty($item->hs_code)) return true;
            if (empty($item->description)) return true;
            if ($item->quantity <= 0) return true;
            if ($item->price < 0) return true;
        }

        if ($invoice->company) {
            if (empty($invoice->company->ntn)) return true;
            if (empty($invoice->company->name)) return true;
        }

        return false;
    }

    private static function getExpectedRateForHsCode(string $hsCode, float $standardTaxRate = 18.0): float
    {
        $prefix = substr($hsCode, 0, 2);

        $zeroRatedPrefixes = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'];
        if (in_array($prefix, $zeroRatedPrefixes)) {
            return 0.0;
        }

        $reducedRatePrefixes = ['30', '84', '85'];
        if (in_array($prefix, $reducedRatePrefixes)) {
            return $standardTaxRate;
        }

        return $standardTaxRate;
    }

    private static function calculateDeductions(array $flags): array
    {
        $deductions = [];

        if ($flags['RATE_MISMATCH']) $deductions['RATE_MISMATCH'] = self::DEDUCTION_RATE_MISMATCH;
        if ($flags['BUYER_RISK']) $deductions['BUYER_RISK'] = self::DEDUCTION_BUYER_RISK;
        if ($flags['BANKING_RISK']) $deductions['BANKING_RISK'] = self::DEDUCTION_BANKING_RISK;
        if ($flags['STRUCTURE_ERROR']) $deductions['STRUCTURE_ERROR'] = self::DEDUCTION_STRUCTURE_ERROR;

        return $deductions;
    }

    private static function getDetails(Invoice $invoice, array $flags): array
    {
        $details = [];

        if ($flags['RATE_MISMATCH']) {
            $mismatches = [];
            foreach ($invoice->items as $item) {
                $subtotal = $item->price * $item->quantity;
                if ($subtotal <= 0) continue;
                $effectiveRate = ($item->tax / $subtotal) * 100;
                $expectedRate = self::getExpectedRateForHsCode($item->hs_code);
                if (abs($effectiveRate - $expectedRate) > self::RATE_TOLERANCE) {
                    $mismatches[] = [
                        'hs_code' => $item->hs_code,
                        'effective_rate' => round($effectiveRate, 2),
                        'expected_rate' => $expectedRate,
                    ];
                }
            }
            $details['rate_mismatch'] = $mismatches;
        }

        if ($flags['BUYER_RISK']) {
            $details['buyer_risk'] = 'Buyer NTN missing or invalid format (Section 23 compliance)';
        }

        if ($flags['BANKING_RISK']) {
            $details['banking_risk'] = 'Section 73 violation: High-value transaction (>PKR 50,000) without valid buyer NTN';
        }

        if ($flags['STRUCTURE_ERROR']) {
            $details['structure_error'] = 'Invoice structure incomplete per Section 23 tax invoice requirements';
        }

        return $details;
    }
}
