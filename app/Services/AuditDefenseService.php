<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\ComplianceReport;
use App\Models\VendorRiskProfile;
use App\Models\ComplianceScore;
use Carbon\Carbon;

class AuditDefenseService
{
    public static function generateRiskReport(int $companyId, ?string $month = null): array
    {
        $date = $month ? Carbon::parse($month) : now()->subMonth();
        $company = Company::findOrFail($companyId);

        $reports = ComplianceReport::where('company_id', $companyId)
            ->whereMonth('created_at', $date->month)
            ->whereYear('created_at', $date->year)
            ->get();

        $totalReports = $reports->count();
        $avgScore = $totalReports > 0 ? round($reports->avg('final_score')) : 100;

        $riskDistribution = [
            'LOW' => $reports->where('risk_level', 'LOW')->count(),
            'MODERATE' => $reports->where('risk_level', 'MODERATE')->count(),
            'HIGH' => $reports->where('risk_level', 'HIGH')->count(),
            'CRITICAL' => $reports->where('risk_level', 'CRITICAL')->count(),
        ];

        $flagSummary = self::aggregateFlags($reports);

        $vendorRisks = VendorRiskProfile::where('company_id', $companyId)
            ->where('vendor_score', '<', 70)
            ->orderBy('vendor_score', 'asc')
            ->get();

        $reportHash = self::generateReportHash($companyId, $date, $avgScore, $riskDistribution);

        return [
            'company' => $company,
            'period' => $date->format('F Y'),
            'total_invoices_scored' => $totalReports,
            'average_score' => $avgScore,
            'risk_level' => HybridComplianceScorer::classifyRisk($avgScore),
            'risk_distribution' => $riskDistribution,
            'flag_summary' => $flagSummary,
            'risky_vendors' => $vendorRisks,
            'report_hash' => $reportHash,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public static function calculateAuditProbability(int $companyId): array
    {
        $company = Company::find($companyId);
        $score = $company->compliance_score ?? 100;

        $recentReports = ComplianceReport::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subMonths(3))
            ->get();

        $criticalCount = $recentReports->where('risk_level', 'CRITICAL')->count();
        $highCount = $recentReports->where('risk_level', 'HIGH')->count();

        $baseProbability = max(0, (100 - $score));
        $criticalBonus = $criticalCount * 10;
        $highBonus = $highCount * 5;

        $probability = min(100, $baseProbability + $criticalBonus + $highBonus);

        $level = 'LOW';
        if ($probability >= 70) $level = 'CRITICAL';
        elseif ($probability >= 50) $level = 'HIGH';
        elseif ($probability >= 25) $level = 'MODERATE';

        return [
            'probability' => round($probability),
            'level' => $level,
            'factors' => [
                'compliance_score' => $score,
                'critical_reports_3m' => $criticalCount,
                'high_risk_reports_3m' => $highCount,
            ],
        ];
    }

    private static function aggregateFlags($reports): array
    {
        $flags = [
            'RATE_MISMATCH' => 0,
            'BUYER_RISK' => 0,
            'BANKING_RISK' => 0,
            'STRUCTURE_ERROR' => 0,
        ];

        foreach ($reports as $report) {
            $ruleFlags = $report->rule_flags ?? [];
            foreach ($ruleFlags as $flag => $value) {
                if ($value && isset($flags[$flag])) {
                    $flags[$flag]++;
                }
            }
        }

        return $flags;
    }

    private static function generateReportHash(int $companyId, Carbon $date, int $avgScore, array $riskDistribution): string
    {
        $data = json_encode([
            'company_id' => $companyId,
            'period' => $date->format('Y-m'),
            'avg_score' => $avgScore,
            'risk_distribution' => $riskDistribution,
            'generated_at' => now()->toIso8601String(),
        ]);

        return hash('sha256', $data);
    }
}
