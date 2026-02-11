<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\FbrLog;
use App\Models\AnomalyLog;
use App\Models\VendorRiskProfile;
use App\Models\ComplianceReport;
use Carbon\Carbon;

class ComplianceScoreService
{
    public static function calculate(int $companyId): int
    {
        $totalInvoices = Invoice::where('company_id', $companyId)->count();

        if ($totalInvoices === 0) {
            return 100;
        }

        $base = self::calculateBaseScore($companyId);
        $anomalyWeight = self::calculateAnomalyWeight($companyId);
        $vendorWeight = self::calculateVendorWeight($companyId);
        $stabilityBonus = self::calculateStabilityBonus($companyId);

        $finalScore = $base - $anomalyWeight - $vendorWeight + $stabilityBonus;

        return (int) round(min(100, max(0, $finalScore)));
    }

    public static function calculateDetailed(int $companyId): array
    {
        $totalInvoices = Invoice::where('company_id', $companyId)->count();

        if ($totalInvoices === 0) {
            return [
                'final_score' => 100,
                'base_score' => 100,
                'anomaly_weight' => 0,
                'vendor_weight' => 0,
                'stability_bonus' => 0,
                'formula' => '100 - 0 - 0 + 0 = 100',
            ];
        }

        $base = self::calculateBaseScore($companyId);
        $anomalyWeight = self::calculateAnomalyWeight($companyId);
        $vendorWeight = self::calculateVendorWeight($companyId);
        $stabilityBonus = self::calculateStabilityBonus($companyId);

        $finalScore = (int) round(min(100, max(0, $base - $anomalyWeight - $vendorWeight + $stabilityBonus)));

        return [
            'final_score' => $finalScore,
            'base_score' => round($base, 1),
            'anomaly_weight' => round($anomalyWeight, 1),
            'vendor_weight' => round($vendorWeight, 1),
            'stability_bonus' => round($stabilityBonus, 1),
            'formula' => round($base, 1) . ' - ' . round($anomalyWeight, 1) . ' - ' . round($vendorWeight, 1) . ' + ' . round($stabilityBonus, 1) . ' = ' . $finalScore,
        ];
    }

    private static function calculateBaseScore(int $companyId): float
    {
        $successRate = self::getSuccessRate($companyId);
        $retryRatio = self::getRetryRatio($companyId);
        $draftAging = self::getDraftAgingScore($companyId);
        $failureRatio = self::getFailureRatio($companyId);

        return ($successRate * 0.4) + ($retryRatio * 0.2) + ($draftAging * 0.2) + ($failureRatio * 0.2);
    }

    private static function calculateAnomalyWeight(int $companyId): float
    {
        $recentAnomalies = AnomalyLog::where('company_id', $companyId)
            ->where('resolved', false)
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        if ($recentAnomalies->isEmpty()) return 0;

        $weight = 0;
        $highSeverity = $recentAnomalies->where('severity', 'high')->count();
        $mediumSeverity = $recentAnomalies->where('severity', 'medium')->count();
        $warningSeverity = $recentAnomalies->whereIn('severity', ['warning', 'alert'])->count();

        $weight += $highSeverity * 5;
        $weight += $mediumSeverity * 3;
        $weight += $warningSeverity * 1;

        return min(30, $weight);
    }

    private static function calculateVendorWeight(int $companyId): float
    {
        $riskyVendors = VendorRiskProfile::where('company_id', $companyId)
            ->where('vendor_score', '<', 70)
            ->get();

        if ($riskyVendors->isEmpty()) return 0;

        $totalVendors = VendorRiskProfile::where('company_id', $companyId)->count();
        if ($totalVendors === 0) return 0;

        $riskyRatio = $riskyVendors->count() / $totalVendors;
        $avgRiskyScore = $riskyVendors->avg('vendor_score');

        $weight = $riskyRatio * 15;
        if ($avgRiskyScore < 40) {
            $weight += 5;
        }

        return min(20, round($weight, 1));
    }

    private static function calculateStabilityBonus(int $companyId): float
    {
        $recentReports = ComplianceReport::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subMonths(3))
            ->get();

        if ($recentReports->count() < 5) return 0;

        $lowRiskCount = $recentReports->where('risk_level', 'LOW')->count();
        $ratio = $lowRiskCount / $recentReports->count();

        if ($ratio >= 0.9) return 10;
        if ($ratio >= 0.7) return 5;
        if ($ratio >= 0.5) return 2;
        return 0;
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

    public static function getAuditProbability(int $companyId): array
    {
        $score = self::calculate($companyId);

        $recentAnomalies = AnomalyLog::where('company_id', $companyId)
            ->where('resolved', false)
            ->where('created_at', '>=', now()->subMonths(3))
            ->get();

        $highSeverityCount = $recentAnomalies->where('severity', 'high')->count();
        $totalAnomalies = $recentAnomalies->count();

        $recentReports = ComplianceReport::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subMonths(3))
            ->get();
        $criticalCount = $recentReports->where('risk_level', 'CRITICAL')->count();
        $highCount = $recentReports->where('risk_level', 'HIGH')->count();

        $baseProbability = max(0, (100 - $score));
        $anomalyBonus = $highSeverityCount * 8 + ($totalAnomalies - $highSeverityCount) * 3;
        $criticalBonus = $criticalCount * 10;
        $highBonus = $highCount * 5;

        $riskyVendors = VendorRiskProfile::where('company_id', $companyId)
            ->where('vendor_score', '<', 50)
            ->count();
        $vendorBonus = $riskyVendors * 4;

        $probability = min(100, $baseProbability + $anomalyBonus + $criticalBonus + $highBonus + $vendorBonus);

        $level = 'LOW';
        if ($probability >= 70) $level = 'CRITICAL';
        elseif ($probability >= 50) $level = 'HIGH';
        elseif ($probability >= 25) $level = 'MODERATE';

        $levelColors = [
            'LOW' => ['bg' => 'bg-green-100', 'text' => 'text-green-800', 'border' => 'border-green-300'],
            'MODERATE' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'border' => 'border-yellow-300'],
            'HIGH' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-800', 'border' => 'border-orange-300'],
            'CRITICAL' => ['bg' => 'bg-red-100', 'text' => 'text-red-800', 'border' => 'border-red-300'],
        ];

        return [
            'probability' => round($probability),
            'level' => $level,
            'colors' => $levelColors[$level],
            'factors' => [
                'compliance_score' => $score,
                'active_anomalies' => $totalAnomalies,
                'high_severity_anomalies' => $highSeverityCount,
                'critical_reports_3m' => $criticalCount,
                'high_risk_reports_3m' => $highCount,
                'risky_vendors' => $riskyVendors,
            ],
        ];
    }
}
