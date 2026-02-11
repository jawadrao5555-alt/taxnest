<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Company;
use App\Models\AnomalyLog;
use App\Models\VendorRiskProfile;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RiskIntelligenceEngine
{
    public static function analyzeInvoice(Invoice $invoice, bool $persistAnomalies = false): array
    {
        $invoice->load('items', 'company');
        $companyId = $invoice->company_id;

        $risks = [];

        $hsTaxRisks = self::detectHsTaxMismatch($invoice);
        if (!empty($hsTaxRisks)) {
            $risks = array_merge($risks, $hsTaxRisks);
        }

        $sroRisk = self::detectReducedWithoutSro($invoice);
        if ($sroRisk) {
            $risks[] = $sroRisk;
        }

        $mrpRisk = self::detectMissingMrp($invoice);
        if ($mrpRisk) {
            $risks[] = $mrpRisk;
        }

        $zeroRatedRisk = self::detectZeroRatedDomesticAnomaly($invoice);
        if ($zeroRatedRisk) {
            $risks[] = $zeroRatedRisk;
        }

        $spikeRisk = self::detectInvoiceSpike($companyId);
        if ($spikeRisk) {
            $risks[] = $spikeRisk;
        }

        $priceRisks = self::detectPriceDeviation($invoice);
        if (!empty($priceRisks)) {
            $risks = array_merge($risks, $priceRisks);
        }

        $riskScore = self::calculateRiskScore($risks);
        $riskLevel = self::classifyRiskLevel($riskScore);
        $riskColor = self::getRiskColor($riskLevel);

        if ($persistAnomalies) {
            foreach ($risks as &$risk) {
                self::logAnomalyIdempotent($companyId, $risk, $invoice->id);
            }
        }

        return [
            'risks' => $risks,
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel,
            'risk_color' => $riskColor,
            'risk_count' => count($risks),
            'should_block' => $riskLevel === 'critical',
            'invoice_id' => $invoice->id,
        ];
    }

    public static function analyzeForPreSubmission(Invoice $invoice): array
    {
        $result = self::analyzeInvoice($invoice, true);

        $company = Company::find($invoice->company_id);
        if ($company && $company->is_internal_account) {
            $result['should_block'] = false;
            $result['internal_bypass'] = true;
        }

        return $result;
    }

    private static function detectHsTaxMismatch(Invoice $invoice): array
    {
        $risks = [];

        foreach ($invoice->items as $index => $item) {
            $hsCode = $item->hs_code ?? '';
            if (empty($hsCode)) continue;

            $lookup = ScheduleEngine::lookupByHsCode($hsCode);
            if (!$lookup) continue;

            $expectedRate = $lookup['tax_rate'];
            $expectedSchedule = $lookup['schedule_type'];

            $itemTaxRate = $item->tax_rate ?? null;
            if ($itemTaxRate === null) {
                $subtotal = $item->price * $item->quantity;
                if ($subtotal > 0) {
                    $itemTaxRate = ($item->tax / $subtotal) * 100;
                }
            }

            $actualSchedule = $item->schedule_type ?? 'standard';

            if ($expectedSchedule !== $actualSchedule) {
                $risks[] = [
                    'type' => 'hs_tax_mismatch',
                    'severity' => 'high',
                    'weight' => 25,
                    'item_index' => $index + 1,
                    'message' => "Item #{$index}: HS code {$hsCode} expects '{$expectedSchedule}' schedule but '{$actualSchedule}' is set",
                    'details' => [
                        'hs_code' => $hsCode,
                        'expected_schedule' => $expectedSchedule,
                        'actual_schedule' => $actualSchedule,
                        'expected_rate' => $expectedRate,
                        'actual_rate' => $itemTaxRate,
                    ],
                ];
            } elseif ($itemTaxRate !== null && abs($itemTaxRate - $expectedRate) > 2) {
                $risks[] = [
                    'type' => 'hs_tax_mismatch',
                    'severity' => 'medium',
                    'weight' => 15,
                    'item_index' => $index + 1,
                    'message' => "Item #{$index}: HS code {$hsCode} expects {$expectedRate}% tax but {$itemTaxRate}% applied",
                    'details' => [
                        'hs_code' => $hsCode,
                        'expected_rate' => $expectedRate,
                        'actual_rate' => $itemTaxRate,
                    ],
                ];
            }
        }

        return $risks;
    }

    private static function detectReducedWithoutSro(Invoice $invoice): ?array
    {
        foreach ($invoice->items as $index => $item) {
            $scheduleType = $item->schedule_type ?? 'standard';
            $taxRate = $item->tax_rate ?? null;

            if ($scheduleType === '3rd_schedule' && $taxRate !== null && $taxRate < 18) {
                if (empty($item->sro_schedule_no)) {
                    return [
                        'type' => 'reduced_without_sro',
                        'severity' => 'high',
                        'weight' => 20,
                        'item_index' => $index + 1,
                        'message' => "Item #{$index}: 3rd Schedule at reduced rate ({$taxRate}%) without SRO reference - FBR may reject",
                        'details' => [
                            'schedule_type' => $scheduleType,
                            'tax_rate' => $taxRate,
                            'missing_field' => 'sro_schedule_no',
                        ],
                    ];
                }
            }
        }

        return null;
    }

    private static function detectMissingMrp(Invoice $invoice): ?array
    {
        foreach ($invoice->items as $index => $item) {
            $scheduleType = $item->schedule_type ?? 'standard';

            if ($scheduleType === '3rd_schedule') {
                if (empty($item->mrp) || floatval($item->mrp) <= 0) {
                    return [
                        'type' => 'missing_mrp',
                        'severity' => 'medium',
                        'weight' => 15,
                        'item_index' => $index + 1,
                        'message' => "Item #{$index}: 3rd Schedule item missing MRP/Retail Price - required for FBR compliance",
                        'details' => [
                            'schedule_type' => $scheduleType,
                            'missing_field' => 'mrp',
                        ],
                    ];
                }
            }
        }

        return null;
    }

    private static function detectZeroRatedDomesticAnomaly(Invoice $invoice): ?array
    {
        $hasZeroRated = false;
        foreach ($invoice->items as $item) {
            if (($item->schedule_type ?? 'standard') === 'zero_rated') {
                $hasZeroRated = true;
                break;
            }
        }

        if (!$hasZeroRated) return null;

        $buyerNtn = $invoice->buyer_ntn ?? '';
        $isPakistaniNtn = !empty($buyerNtn) && (
            preg_match('/^\d{7}-?\d{1}$/', $buyerNtn) ||
            preg_match('/^\d{13}$/', $buyerNtn)
        );

        if ($isPakistaniNtn || empty($buyerNtn)) {
            return [
                'type' => 'zero_rated_domestic',
                'severity' => 'medium',
                'weight' => 15,
                'message' => "Zero-rated items on domestic invoice (buyer NTN: {$buyerNtn}) - zero-rating typically applies to exports",
                'details' => [
                    'buyer_ntn' => $buyerNtn,
                    'is_domestic' => true,
                ],
            ];
        }

        return null;
    }

    private static function detectInvoiceSpike(int $companyId): ?array
    {
        $currentMonth = Invoice::where('company_id', $companyId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $avgMonthly = Invoice::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subMonths(3)->startOfMonth())
            ->where('created_at', '<', now()->startOfMonth())
            ->count();

        $monthsCount = min(3, now()->diffInMonths(
            Invoice::where('company_id', $companyId)->min('created_at') ?? now()
        ));

        if ($monthsCount > 0) {
            $avgMonthly = $avgMonthly / $monthsCount;
        }

        if ($avgMonthly > 0 && $currentMonth > ($avgMonthly * 3)) {
            $ratio = round($currentMonth / $avgMonthly, 1);
            return [
                'type' => 'invoice_spike',
                'severity' => $ratio > 5 ? 'high' : 'medium',
                'weight' => $ratio > 5 ? 20 : 12,
                'message' => "Invoice volume spike: {$currentMonth} this month vs {$avgMonthly} avg ({$ratio}x increase) - may trigger FBR audit",
                'details' => [
                    'current_month' => $currentMonth,
                    'monthly_average' => round($avgMonthly, 1),
                    'ratio' => $ratio,
                ],
            ];
        }

        return null;
    }

    private static function detectPriceDeviation(Invoice $invoice): array
    {
        $risks = [];
        $companyId = $invoice->company_id;

        foreach ($invoice->items as $index => $item) {
            $hsCode = $item->hs_code ?? '';
            if (empty($hsCode)) continue;

            $avgPrice = InvoiceItem::whereHas('invoice', function ($q) use ($companyId) {
                    $q->where('company_id', $companyId);
                })
                ->where('hs_code', $hsCode)
                ->where('invoice_id', '!=', $invoice->id)
                ->avg('price');

            if ($avgPrice && $avgPrice > 0) {
                $deviation = abs($item->price - $avgPrice) / $avgPrice * 100;

                if ($deviation > 40) {
                    $direction = $item->price > $avgPrice ? 'above' : 'below';
                    $risks[] = [
                        'type' => 'price_deviation',
                        'severity' => $deviation > 80 ? 'high' : 'medium',
                        'weight' => $deviation > 80 ? 18 : 10,
                        'item_index' => $index + 1,
                        'message' => "Item #{$index}: Price Rs. " . number_format($item->price, 2) . " is " . round($deviation) . "% {$direction} avg (Rs. " . number_format($avgPrice, 2) . ") for HS {$hsCode}",
                        'details' => [
                            'hs_code' => $hsCode,
                            'current_price' => $item->price,
                            'average_price' => round($avgPrice, 2),
                            'deviation_percent' => round($deviation, 1),
                            'direction' => $direction,
                        ],
                    ];
                }
            }
        }

        return $risks;
    }

    public static function calculateRiskScore(array $risks): int
    {
        if (empty($risks)) return 0;

        $totalWeight = 0;
        foreach ($risks as $risk) {
            $totalWeight += $risk['weight'] ?? 10;
        }

        return min(100, $totalWeight);
    }

    public static function classifyRiskLevel(int $score): string
    {
        if ($score <= 15) return 'safe';
        if ($score <= 40) return 'review';
        if ($score <= 70) return 'high';
        return 'critical';
    }

    public static function getRiskColor(string $level): array
    {
        return match ($level) {
            'safe' => ['label' => 'Safe', 'color' => 'green', 'bg' => 'bg-green-100', 'text' => 'text-green-800', 'border' => 'border-green-300', 'icon' => 'check-circle'],
            'review' => ['label' => 'Review', 'color' => 'yellow', 'bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'border' => 'border-yellow-300', 'icon' => 'exclamation'],
            'high' => ['label' => 'High Risk', 'color' => 'orange', 'bg' => 'bg-orange-100', 'text' => 'text-orange-800', 'border' => 'border-orange-300', 'icon' => 'exclamation-triangle'],
            'critical' => ['label' => 'Critical', 'color' => 'red', 'bg' => 'bg-red-100', 'text' => 'text-red-800', 'border' => 'border-red-300', 'icon' => 'x-circle'],
            default => ['label' => 'Unknown', 'color' => 'gray', 'bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'border' => 'border-gray-300', 'icon' => 'question-mark-circle'],
        };
    }

    public static function getRiskBadgeHtml(int $score): array
    {
        $level = self::classifyRiskLevel($score);
        $color = self::getRiskColor($level);
        return [
            'score' => $score,
            'level' => $level,
            'color' => $color,
        ];
    }

    private static function logAnomaly(int $companyId, array $risk, int $invoiceId): void
    {
        self::logAnomalyIdempotent($companyId, $risk, $invoiceId);
    }

    private static function logAnomalyIdempotent(int $companyId, array $risk, int $invoiceId): void
    {
        $existing = AnomalyLog::where('company_id', $companyId)
            ->where('type', $risk['type'])
            ->where('resolved', false)
            ->whereRaw("(metadata->>'invoice_id')::text = ?", [(string)$invoiceId])
            ->first();

        if (!$existing) {
            AnomalyLog::create([
                'company_id' => $companyId,
                'type' => $risk['type'],
                'severity' => $risk['severity'],
                'description' => $risk['message'],
                'metadata' => array_merge($risk['details'] ?? [], ['invoice_id' => $invoiceId]),
            ]);
        }
    }

    public static function getActiveRisksForInvoice(Invoice $invoice): array
    {
        return self::analyzeInvoice($invoice);
    }

    public static function getCompanyRiskSummary(int $companyId): array
    {
        $recentAnomalies = AnomalyLog::where('company_id', $companyId)
            ->where('resolved', false)
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        $risksByType = $recentAnomalies->groupBy('type')->map->count();
        $totalRisks = $recentAnomalies->count();

        $severityBreakdown = [
            'high' => $recentAnomalies->where('severity', 'high')->count(),
            'medium' => $recentAnomalies->where('severity', 'medium')->count(),
            'low' => $recentAnomalies->where('severity', 'low')->count(),
        ];

        return [
            'total_active_risks' => $totalRisks,
            'risks_by_type' => $risksByType->toArray(),
            'severity_breakdown' => $severityBreakdown,
        ];
    }
}
