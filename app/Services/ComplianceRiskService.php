<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\FbrLog;
use App\Models\ComplianceScore;
use Carbon\Carbon;

class ComplianceRiskService
{
    public static function calculate(int $companyId): array
    {
        $totalInvoices = Invoice::where('company_id', $companyId)->count();

        if ($totalInvoices === 0) {
            return [
                'score' => 100,
                'success_rate' => 100,
                'retry_ratio' => 0,
                'draft_aging' => 0,
                'failure_ratio' => 0,
                'category' => 'SAFE',
            ];
        }

        $successRate = self::getSuccessRate($companyId);
        $retryRatio = self::getRetryRatioScore($companyId);
        $draftAging = self::getDraftAgingScore($companyId);
        $failureRatio = self::getFailureRatioScore($companyId);

        $score = ($successRate * 0.4) + ($retryRatio * 0.2) + ($draftAging * 0.2) + ($failureRatio * 0.2);
        $score = (int) round(min(100, max(0, $score)));

        $category = self::categorize($score);

        return [
            'score' => $score,
            'success_rate' => round($successRate, 2),
            'retry_ratio' => round(100 - $retryRatio, 2),
            'draft_aging' => round(100 - $draftAging, 2),
            'failure_ratio' => round(100 - $failureRatio, 2),
            'category' => $category,
        ];
    }

    public static function recalculateAndStore(int $companyId): void
    {
        $result = self::calculate($companyId);

        ComplianceScore::updateOrCreate(
            ['company_id' => $companyId, 'calculated_date' => now()->toDateString()],
            [
                'score' => $result['score'],
                'success_rate' => $result['success_rate'],
                'retry_ratio' => $result['retry_ratio'],
                'draft_aging' => $result['draft_aging'],
                'failure_ratio' => $result['failure_ratio'],
                'category' => $result['category'],
            ]
        );

        Company::where('id', $companyId)->update(['compliance_score' => $result['score']]);
    }

    public static function categorize(int $score): string
    {
        if ($score >= 80) return 'SAFE';
        if ($score >= 50) return 'MODERATE';
        return 'AT-RISK';
    }

    public static function getBadge(int $score): array
    {
        $category = self::categorize($score);
        return match ($category) {
            'SAFE' => ['label' => 'SAFE', 'color' => 'green', 'bg' => 'bg-green-100', 'text' => 'text-green-800'],
            'MODERATE' => ['label' => 'MODERATE', 'color' => 'yellow', 'bg' => 'bg-yellow-100', 'text' => 'text-yellow-800'],
            default => ['label' => 'AT-RISK', 'color' => 'red', 'bg' => 'bg-red-100', 'text' => 'text-red-800'],
        };
    }

    private static function getSuccessRate(int $companyId): float
    {
        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');
        $totalLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->count();
        if ($totalLogs === 0) return 100;
        $successLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->where('status', 'success')->count();
        return ($successLogs / $totalLogs) * 100;
    }

    private static function getRetryRatioScore(int $companyId): float
    {
        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');
        $totalLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->count();
        if ($totalLogs === 0) return 100;
        $retryLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->where('retry_count', '>', 0)->count();
        return (1 - ($retryLogs / $totalLogs)) * 100;
    }

    private static function getDraftAgingScore(int $companyId): float
    {
        $draftInvoices = Invoice::where('company_id', $companyId)->where('status', 'draft')->get();
        if ($draftInvoices->isEmpty()) return 100;
        $oldDrafts = $draftInvoices->filter(fn($inv) => $inv->created_at->diffInDays(now()) > 7)->count();
        return (1 - ($oldDrafts / $draftInvoices->count())) * 100;
    }

    private static function getFailureRatioScore(int $companyId): float
    {
        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');
        $totalLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->count();
        if ($totalLogs === 0) return 100;
        $failedLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->where('status', 'failed')->count();
        return (1 - ($failedLogs / $totalLogs)) * 100;
    }
}
