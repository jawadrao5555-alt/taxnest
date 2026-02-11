<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Company;
use App\Models\FbrLog;
use App\Models\AnomalyLog;
use App\Models\ComplianceReport;
use App\Models\VendorRiskProfile;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AuditProbabilityEngine
{
    public static function calculate(int $companyId): array
    {
        $factors = [];

        $factors['failure_ratio'] = self::getFailureRatio($companyId);
        $factors['retry_ratio'] = self::getRetryRatio($companyId);
        $factors['reduced_rate_frequency'] = self::getReducedRateFrequency($companyId);
        $factors['zero_rated_spikes'] = self::getZeroRatedSpikes($companyId);
        $factors['sro_usage_anomalies'] = self::getSroUsageAnomalies($companyId);
        $factors['tax_volatility'] = self::getTaxVolatility($companyId);
        $factors['invoice_volume_spikes'] = self::getInvoiceVolumeSpikes($companyId);

        $complianceScore = ComplianceScoreService::calculate($companyId);
        $anomalyWeight = self::getAnomalyWeight($companyId);
        $stabilityBonus = self::getStabilityBonus($companyId);

        $probability = self::computeProbability($factors, $complianceScore, $anomalyWeight, $stabilityBonus);

        $level = 'LOW';
        $color = '#22c55e';
        if ($probability >= 70) {
            $level = 'CRITICAL';
            $color = '#ef4444';
        } elseif ($probability >= 50) {
            $level = 'HIGH';
            $color = '#f97316';
        } elseif ($probability >= 25) {
            $level = 'MODERATE';
            $color = '#eab308';
        }

        return [
            'probability' => round($probability),
            'level' => $level,
            'color' => $color,
            'factors' => $factors,
            'breakdown' => [
                'compliance_score' => $complianceScore,
                'anomaly_weight' => round($anomalyWeight, 1),
                'stability_bonus' => round($stabilityBonus, 1),
            ],
            'formula' => "({$complianceScore} weight) × (anomaly:{$anomalyWeight}) × (failure:{$factors['failure_ratio']['weight']}) + (stability:{$stabilityBonus})",
        ];
    }

    public static function getTrend(int $companyId, int $months = 6): array
    {
        $trend = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            $invoiceIds = Invoice::where('company_id', $companyId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->pluck('id');

            $totalLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->count();
            $failedLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->where('status', 'failed')->count();
            $failureRate = $totalLogs > 0 ? ($failedLogs / $totalLogs) * 100 : 0;

            $anomalies = AnomalyLog::where('company_id', $companyId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->count();

            $score = min(100, max(0, $failureRate * 2 + $anomalies * 5));

            $trend[] = [
                'month' => $month->format('M'),
                'probability' => round($score),
                'failures' => $failedLogs,
                'anomalies' => $anomalies,
            ];
        }
        return $trend;
    }

    private static function computeProbability(array $factors, int $complianceScore, float $anomalyWeight, float $stabilityBonus): float
    {
        $base = max(0, 100 - $complianceScore);

        $factorWeight = 0;
        $factorWeight += $factors['failure_ratio']['weight'] * 0.20;
        $factorWeight += $factors['retry_ratio']['weight'] * 0.10;
        $factorWeight += $factors['reduced_rate_frequency']['weight'] * 0.15;
        $factorWeight += $factors['zero_rated_spikes']['weight'] * 0.10;
        $factorWeight += $factors['sro_usage_anomalies']['weight'] * 0.15;
        $factorWeight += $factors['tax_volatility']['weight'] * 0.15;
        $factorWeight += $factors['invoice_volume_spikes']['weight'] * 0.15;

        $probability = ($base * 0.4) + ($factorWeight * 0.4) + ($anomalyWeight * 0.2) - ($stabilityBonus * 0.5);

        return min(100, max(0, $probability));
    }

    private static function getFailureRatio(int $companyId): array
    {
        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');
        $total = FbrLog::whereIn('invoice_id', $invoiceIds)->count();
        $failed = FbrLog::whereIn('invoice_id', $invoiceIds)->where('status', 'failed')->count();
        $ratio = $total > 0 ? ($failed / $total) * 100 : 0;
        return ['value' => round($ratio, 1), 'weight' => min(100, $ratio * 2), 'label' => 'Failure Ratio'];
    }

    private static function getRetryRatio(int $companyId): array
    {
        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');
        $total = FbrLog::whereIn('invoice_id', $invoiceIds)->count();
        $retries = FbrLog::whereIn('invoice_id', $invoiceIds)->where('retry_count', '>', 0)->count();
        $ratio = $total > 0 ? ($retries / $total) * 100 : 0;
        return ['value' => round($ratio, 1), 'weight' => min(100, $ratio * 1.5), 'label' => 'Retry Ratio'];
    }

    private static function getReducedRateFrequency(int $companyId): array
    {
        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');
        $total = InvoiceItem::whereIn('invoice_id', $invoiceIds)->count();
        $reduced = InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->whereIn('schedule_type', ['reduced', '3rd_schedule'])
            ->count();
        $ratio = $total > 0 ? ($reduced / $total) * 100 : 0;
        $weight = $ratio > 50 ? min(100, ($ratio - 50) * 3) : 0;
        return ['value' => round($ratio, 1), 'weight' => round($weight, 1), 'label' => 'Reduced Rate Frequency'];
    }

    private static function getZeroRatedSpikes(int $companyId): array
    {
        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');
        $total = InvoiceItem::whereIn('invoice_id', $invoiceIds)->count();
        $zeroRated = InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->where('schedule_type', 'zero_rated')
            ->count();
        $ratio = $total > 0 ? ($zeroRated / $total) * 100 : 0;

        $recentZeroRated = InvoiceItem::whereIn('invoice_id',
            Invoice::where('company_id', $companyId)->where('created_at', '>=', now()->subDays(30))->pluck('id')
        )->where('schedule_type', 'zero_rated')->count();

        $spike = $zeroRated > 0 ? ($recentZeroRated / max(1, $zeroRated)) * 100 : 0;
        $weight = $spike > 50 ? min(100, ($spike - 50) * 2) : 0;

        return ['value' => round($ratio, 1), 'weight' => round($weight, 1), 'label' => 'Zero-Rated Spikes'];
    }

    private static function getSroUsageAnomalies(int $companyId): array
    {
        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');
        $needsSro = InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->whereIn('schedule_type', ['reduced', 'exempt'])
            ->count();
        $hasSro = InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->whereIn('schedule_type', ['reduced', 'exempt'])
            ->whereNotNull('sro_schedule_no')
            ->where('sro_schedule_no', '!=', '')
            ->count();
        $missingSroRatio = $needsSro > 0 ? (($needsSro - $hasSro) / $needsSro) * 100 : 0;
        return ['value' => round($missingSroRatio, 1), 'weight' => min(100, $missingSroRatio * 1.5), 'label' => 'SRO Usage Anomalies'];
    }

    private static function getTaxVolatility(int $companyId): array
    {
        $monthlyRates = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $invoiceIds = Invoice::where('company_id', $companyId)
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->pluck('id');
            $avgRate = InvoiceItem::whereIn('invoice_id', $invoiceIds)->avg('tax_rate');
            if ($avgRate !== null) {
                $monthlyRates[] = $avgRate;
            }
        }

        if (count($monthlyRates) < 2) {
            return ['value' => 0, 'weight' => 0, 'label' => 'Tax Volatility'];
        }

        $mean = array_sum($monthlyRates) / count($monthlyRates);
        $variance = 0;
        foreach ($monthlyRates as $rate) {
            $variance += pow($rate - $mean, 2);
        }
        $stdDev = sqrt($variance / count($monthlyRates));
        $cv = $mean > 0 ? ($stdDev / $mean) * 100 : 0;

        return ['value' => round($cv, 1), 'weight' => min(100, $cv * 3), 'label' => 'Tax Volatility'];
    }

    private static function getInvoiceVolumeSpikes(int $companyId): array
    {
        $monthlyCounts = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $count = Invoice::where('company_id', $companyId)
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->count();
            $monthlyCounts[] = $count;
        }

        if (count($monthlyCounts) < 2) {
            return ['value' => 0, 'weight' => 0, 'label' => 'Volume Spikes'];
        }

        $avg = array_sum(array_slice($monthlyCounts, 0, -1)) / max(1, count($monthlyCounts) - 1);
        $current = end($monthlyCounts);
        $spikeRatio = $avg > 0 ? ($current / $avg) : 1;
        $weight = $spikeRatio > 3 ? min(100, ($spikeRatio - 3) * 30) : 0;

        return ['value' => round($spikeRatio, 2), 'weight' => round($weight, 1), 'label' => 'Volume Spikes'];
    }

    private static function getAnomalyWeight(int $companyId): float
    {
        $anomalies = AnomalyLog::where('company_id', $companyId)
            ->where('resolved', false)
            ->where('created_at', '>=', now()->subMonths(3))
            ->get();

        $weight = 0;
        $weight += $anomalies->where('severity', 'high')->count() * 5;
        $weight += $anomalies->where('severity', 'medium')->count() * 3;
        $weight += $anomalies->whereIn('severity', ['warning', 'alert', 'low'])->count() * 1;

        return min(30, $weight);
    }

    private static function getStabilityBonus(int $companyId): float
    {
        $reports = ComplianceReport::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subMonths(3))
            ->get();

        if ($reports->count() < 5) return 0;

        $lowRisk = $reports->where('risk_level', 'LOW')->count();
        $ratio = $lowRisk / $reports->count();

        if ($ratio >= 0.9) return 10;
        if ($ratio >= 0.7) return 5;
        if ($ratio >= 0.5) return 2;
        return 0;
    }
}
