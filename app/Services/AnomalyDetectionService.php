<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\AnomalyLog;
use Carbon\Carbon;

class AnomalyDetectionService
{
    public static function detectInvoiceSpike(int $companyId): ?AnomalyLog
    {
        $currentMonth = Invoice::where('company_id', $companyId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $previousMonth = Invoice::where('company_id', $companyId)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        if ($previousMonth > 0 && $currentMonth > ($previousMonth * 3)) {
            $existing = AnomalyLog::where('company_id', $companyId)
                ->where('type', 'invoice_spike')
                ->whereMonth('created_at', now()->month)
                ->first();

            if (!$existing) {
                return AnomalyLog::create([
                    'company_id' => $companyId,
                    'type' => 'invoice_spike',
                    'severity' => 'warning',
                    'description' => "Invoice count this month ({$currentMonth}) is more than 3x last month ({$previousMonth})",
                    'metadata' => [
                        'current_month' => $currentMonth,
                        'previous_month' => $previousMonth,
                        'ratio' => round($currentMonth / $previousMonth, 2),
                    ],
                ]);
            }
        }

        return null;
    }

    public static function detectTaxSpike(int $companyId): ?AnomalyLog
    {
        $currentMonthTax = Invoice::where('company_id', $companyId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->join('invoice_items', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->sum('invoice_items.tax');

        $previousMonthTax = Invoice::where('company_id', $companyId)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->join('invoice_items', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->sum('invoice_items.tax');

        if ($previousMonthTax > 0) {
            $increase = (($currentMonthTax - $previousMonthTax) / $previousMonthTax) * 100;

            if ($increase >= 40) {
                $existing = AnomalyLog::where('company_id', $companyId)
                    ->where('type', 'tax_spike')
                    ->whereMonth('created_at', now()->month)
                    ->first();

                if (!$existing) {
                    return AnomalyLog::create([
                        'company_id' => $companyId,
                        'type' => 'tax_spike',
                        'severity' => 'alert',
                        'description' => "Tax value increased by " . round($increase, 1) . "% month-over-month",
                        'metadata' => [
                            'current_month_tax' => round($currentMonthTax, 2),
                            'previous_month_tax' => round($previousMonthTax, 2),
                            'increase_percent' => round($increase, 1),
                        ],
                    ]);
                }
            }
        }

        return null;
    }

    public static function runAllDetections(int $companyId): array
    {
        $results = [];

        $invoiceSpike = self::detectInvoiceSpike($companyId);
        if ($invoiceSpike) $results[] = $invoiceSpike;

        $taxSpike = self::detectTaxSpike($companyId);
        if ($taxSpike) $results[] = $taxSpike;

        return $results;
    }
}
