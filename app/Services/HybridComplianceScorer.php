<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\ComplianceReport;
use App\Models\Company;
use Carbon\Carbon;

class HybridComplianceScorer
{
    public static function score(Invoice $invoice): array
    {
        $ruleResult = ComplianceEngine::validate($invoice);
        $anomalyResult = AnomalyEngine::analyze($invoice->company_id);

        $ruleDeductions = $ruleResult['total_deduction'];
        $anomalyWeight = $anomalyResult['risk_weight'];
        $stabilityBonus = self::calculateStabilityBonus($invoice->company_id);

        $score = 100 - $ruleDeductions - $anomalyWeight + $stabilityBonus;
        $score = (int) round(min(100, max(0, $score)));

        $riskLevel = self::classifyRisk($score);

        $report = ComplianceReport::create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'rule_flags' => $ruleResult['flags'],
            'anomaly_flags' => $anomalyResult,
            'final_score' => $score,
            'risk_level' => $riskLevel,
        ]);

        Company::where('id', $invoice->company_id)->update(['compliance_score' => $score]);

        return [
            'final_score' => $score,
            'risk_level' => $riskLevel,
            'rule_result' => $ruleResult,
            'anomaly_result' => $anomalyResult,
            'stability_bonus' => $stabilityBonus,
            'report_id' => $report->id,
        ];
    }

    public static function scoreForCompany(int $companyId): array
    {
        $latestInvoice = Invoice::where('company_id', $companyId)
            ->with('items', 'company')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$latestInvoice) {
            return [
                'final_score' => 100,
                'risk_level' => 'LOW',
                'rule_result' => ['flags' => [], 'deductions' => [], 'total_deduction' => 0, 'details' => []],
                'anomaly_result' => ['MOM_SPIKE' => 0, 'TAX_DROP' => 0, 'HS_SHIFT' => false, 'VALUE_TAX_ANOMALY' => false, 'risk_weight' => 0],
                'stability_bonus' => 0,
            ];
        }

        $anomalyResult = AnomalyEngine::analyze($companyId);
        $stabilityBonus = self::calculateStabilityBonus($companyId);

        $avgRuleDeduction = 0;
        $recentReports = ComplianceReport::where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        if ($recentReports->isNotEmpty()) {
            $totalDeductions = 0;
            foreach ($recentReports as $report) {
                $flags = $report->rule_flags ?? [];
                $flagCount = is_array($flags) ? count(array_filter($flags)) : 0;
                $totalDeductions += $flagCount * 10;
            }
            $avgRuleDeduction = $totalDeductions / $recentReports->count();
        }

        $score = 100 - $avgRuleDeduction - $anomalyResult['risk_weight'] + $stabilityBonus;
        $score = (int) round(min(100, max(0, $score)));

        return [
            'final_score' => $score,
            'risk_level' => self::classifyRisk($score),
            'anomaly_result' => $anomalyResult,
            'stability_bonus' => $stabilityBonus,
        ];
    }

    private static function calculateStabilityBonus(int $companyId): int
    {
        $recentReports = ComplianceReport::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subMonths(3))
            ->get();

        if ($recentReports->count() < 5) return 0;

        $lowRiskCount = $recentReports->where('risk_level', 'LOW')->count();
        $ratio = $lowRiskCount / $recentReports->count();

        if ($ratio >= 0.9) return 10;
        if ($ratio >= 0.7) return 5;
        return 0;
    }

    public static function classifyRisk(int $score): string
    {
        if ($score >= 80) return 'LOW';
        if ($score >= 60) return 'MODERATE';
        if ($score >= 40) return 'HIGH';
        return 'CRITICAL';
    }

    public static function getRiskBadge(string $riskLevel): array
    {
        return match ($riskLevel) {
            'LOW' => ['label' => 'LOW RISK', 'color' => 'green', 'bg' => 'bg-green-100', 'text' => 'text-green-800', 'border' => 'border-green-200'],
            'MODERATE' => ['label' => 'MODERATE RISK', 'color' => 'yellow', 'bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'border' => 'border-yellow-200'],
            'HIGH' => ['label' => 'HIGH RISK', 'color' => 'orange', 'bg' => 'bg-orange-100', 'text' => 'text-orange-800', 'border' => 'border-orange-200'],
            'CRITICAL' => ['label' => 'CRITICAL', 'color' => 'red', 'bg' => 'bg-red-100', 'text' => 'text-red-800', 'border' => 'border-red-200'],
            default => ['label' => 'UNKNOWN', 'color' => 'gray', 'bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'border' => 'border-gray-200'],
        };
    }
}
