<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

class SmartTaxEngine
{
    public static function recommend(string $hsCode, ?string $province = null, ?string $buyerRegistrationType = null, ?string $sectorType = null, int $companyId = 0): array
    {
        $hsLookup = ScheduleEngine::$hsLookupTable[$hsCode] ?? null;
        $hsPrefix = substr($hsCode, 0, 2);

        $historicalData = self::getHistoricalUsage($hsCode, $companyId);
        $prefixHistorical = self::getHistoricalUsageByPrefix($hsPrefix, $companyId);

        $suggestedRate = null;
        $suggestedSchedule = null;
        $suggestedSro = null;
        $confidence = 'low';
        $flags = [];
        $reasoning = [];

        if ($hsLookup) {
            $suggestedSchedule = $hsLookup['schedule_type'];
            $suggestedRate = $hsLookup['tax_rate'];
            $confidence = 'high';
            $reasoning[] = 'HS code found in PCT lookup table';
        }

        if ($historicalData && $historicalData['count'] >= 3) {
            $avgRate = $historicalData['avg_rate'];
            $mostUsedSchedule = $historicalData['most_used_schedule'];

            if ($suggestedSchedule === null) {
                $suggestedSchedule = $mostUsedSchedule;
                $suggestedRate = round($avgRate, 2);
                $confidence = $historicalData['count'] >= 10 ? 'high' : 'medium';
                $reasoning[] = "Based on {$historicalData['count']} historical invoices with this HS code";
            }

            if ($suggestedRate !== null && abs($suggestedRate - $avgRate) > ($avgRate * 0.3) && $avgRate > 0) {
                $flags[] = [
                    'type' => 'rate_deviation',
                    'message' => "Tax rate deviates >30% from historical average ({$avgRate}%)",
                    'severity' => 'warning',
                ];
            }
        }

        if ($prefixHistorical && $prefixHistorical['count'] >= 5 && $suggestedSchedule === null) {
            $suggestedSchedule = $prefixHistorical['most_used_schedule'];
            $suggestedRate = round($prefixHistorical['avg_rate'], 2);
            $confidence = 'medium';
            $reasoning[] = "Based on {$prefixHistorical['count']} invoices with HS prefix {$hsPrefix}";
        }

        if ($suggestedSchedule === null) {
            $suggestedSchedule = 'standard';
            $company = Company::find($companyId);
            $suggestedRate = $company ? $company->getStandardTaxRateValue() : 18.0;
            $confidence = 'low';
            $reasoning[] = 'No historical data found, defaulting to standard rate';
        }

        if ($suggestedSchedule === 'reduced' || ($suggestedSchedule === '3rd_schedule' && $suggestedRate !== null)) {
            $company = Company::find($companyId);
            $standardRate = $company ? $company->getStandardTaxRateValue() : 18.0;
            if ($suggestedRate < $standardRate) {
                $sroSuggestion = SroSuggestionService::suggest($suggestedSchedule, $suggestedRate, $hsCode, $standardRate);
                if ($sroSuggestion) {
                    $suggestedSro = $sroSuggestion;
                    $reasoning[] = "SRO recommended: {$sroSuggestion['sro']}";
                } else {
                    $flags[] = [
                        'type' => 'missing_sro',
                        'message' => 'Reduced rate applied but no matching SRO found',
                        'severity' => 'warning',
                    ];
                }
            }
        }

        if ($suggestedSchedule === '3rd_schedule') {
            $company = Company::find($companyId);
            $standardRate = $company ? $company->getStandardTaxRateValue() : 18.0;
            if ($suggestedRate !== null && $suggestedRate < $standardRate) {
                $flags[] = [
                    'type' => '3rd_schedule_reduced',
                    'message' => '3rd Schedule with reduced rate requires SRO + Serial + MRP',
                    'severity' => 'info',
                ];
            }
        }

        return [
            'suggested_tax_rate' => $suggestedRate,
            'suggested_schedule_type' => $suggestedSchedule,
            'suggested_sro' => $suggestedSro,
            'confidence' => $confidence,
            'flags' => $flags,
            'reasoning' => $reasoning,
            'historical_count' => $historicalData['count'] ?? 0,
        ];
    }

    public static function recommendForInvoice(Invoice $invoice): array
    {
        $invoice->load('items', 'company');
        $recommendations = [];

        foreach ($invoice->items as $item) {
            $recommendations[] = [
                'item_id' => $item->id,
                'hs_code' => $item->hs_code,
                'current_rate' => $item->tax_rate,
                'current_schedule' => $item->schedule_type,
                'recommendation' => self::recommend(
                    $item->hs_code,
                    $invoice->destination_province,
                    $invoice->buyer_registration_type,
                    $invoice->company->sector_type ?? null,
                    $invoice->company_id
                ),
            ];
        }

        return $recommendations;
    }

    private static function getHistoricalUsage(string $hsCode, int $companyId): ?array
    {
        $items = InvoiceItem::whereHas('invoice', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
            ->where('hs_code', $hsCode)
            ->select(
                DB::raw('COUNT(*) as count'),
                DB::raw('AVG(CAST(tax_rate AS FLOAT)) as avg_rate'),
                DB::raw('MODE() WITHIN GROUP (ORDER BY schedule_type) as most_used_schedule')
            )
            ->first();

        if (!$items || $items->count == 0) {
            return null;
        }

        return [
            'count' => (int) $items->count,
            'avg_rate' => round((float) $items->avg_rate, 2),
            'most_used_schedule' => $items->most_used_schedule ?? 'standard',
        ];
    }

    private static function getHistoricalUsageByPrefix(string $hsPrefix, int $companyId): ?array
    {
        $items = InvoiceItem::whereHas('invoice', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
            ->where('hs_code', 'LIKE', $hsPrefix . '%')
            ->select(
                DB::raw('COUNT(*) as count'),
                DB::raw('AVG(CAST(tax_rate AS FLOAT)) as avg_rate'),
                DB::raw('MODE() WITHIN GROUP (ORDER BY schedule_type) as most_used_schedule')
            )
            ->first();

        if (!$items || $items->count == 0) {
            return null;
        }

        return [
            'count' => (int) $items->count,
            'avg_rate' => round((float) $items->avg_rate, 2),
            'most_used_schedule' => $items->most_used_schedule ?? 'standard',
        ];
    }
}
