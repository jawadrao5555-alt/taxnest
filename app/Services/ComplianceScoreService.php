<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\FbrLog;
use Carbon\Carbon;

class ComplianceScoreService
{
    public static function calculate(int $companyId): int
    {
        $totalInvoices = Invoice::where('company_id', $companyId)->count();

        if ($totalInvoices === 0) {
            return 100;
        }

        $successRate = self::getSuccessRate($companyId);
        $retryRatio = self::getRetryRatio($companyId);
        $draftAging = self::getDraftAgingScore($companyId);
        $failureRatio = self::getFailureRatio($companyId);

        $score = ($successRate * 0.4) + ($retryRatio * 0.2) + ($draftAging * 0.2) + ($failureRatio * 0.2);

        return (int) round(min(100, max(0, $score)));
    }

    public static function recalculate(int $companyId): void
    {
        $score = self::calculate($companyId);
        Company::where('id', $companyId)->update(['compliance_score' => $score]);
    }

    private static function getSuccessRate(int $companyId): float
    {
        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');
        $totalLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->count();

        if ($totalLogs === 0) {
            return 100;
        }

        $successLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->where('status', 'success')->count();
        return ($successLogs / $totalLogs) * 100;
    }

    private static function getRetryRatio(int $companyId): float
    {
        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');
        $totalLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->count();

        if ($totalLogs === 0) {
            return 100;
        }

        $retryLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->where('retry_count', '>', 0)->count();
        return (1 - ($retryLogs / $totalLogs)) * 100;
    }

    private static function getDraftAgingScore(int $companyId): float
    {
        $draftInvoices = Invoice::where('company_id', $companyId)
            ->where('status', 'draft')
            ->get();

        if ($draftInvoices->isEmpty()) {
            return 100;
        }

        $oldDrafts = $draftInvoices->filter(function ($inv) {
            return $inv->created_at->diffInDays(now()) > 7;
        })->count();

        $totalDrafts = $draftInvoices->count();
        return (1 - ($oldDrafts / $totalDrafts)) * 100;
    }

    private static function getFailureRatio(int $companyId): float
    {
        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');
        $totalLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->count();

        if ($totalLogs === 0) {
            return 100;
        }

        $failedLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->where('status', 'failed')->count();
        return (1 - ($failedLogs / $totalLogs)) * 100;
    }

    public static function getBadge(int $score): array
    {
        if ($score >= 80) {
            return ['label' => 'SAFE', 'color' => 'green', 'bg' => 'bg-green-100', 'text' => 'text-green-800'];
        } elseif ($score >= 50) {
            return ['label' => 'MODERATE', 'color' => 'yellow', 'bg' => 'bg-yellow-100', 'text' => 'text-yellow-800'];
        } else {
            return ['label' => 'RISK', 'color' => 'red', 'bg' => 'bg-red-100', 'text' => 'text-red-800'];
        }
    }
}
