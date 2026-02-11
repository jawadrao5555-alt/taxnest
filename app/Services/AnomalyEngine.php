<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AnomalyEngine
{
    public static function analyze(int $companyId): array
    {
        $momSpike = self::detectMomSpike($companyId);
        $taxDrop = self::detectTaxDrop($companyId);
        $hsShift = self::detectHsShift($companyId);
        $valueTaxAnomaly = self::detectValueTaxAnomaly($companyId);

        $riskWeight = self::calculateRiskWeight($momSpike, $taxDrop, $hsShift, $valueTaxAnomaly);

        return [
            'MOM_SPIKE' => $momSpike,
            'TAX_DROP' => $taxDrop,
            'HS_SHIFT' => $hsShift,
            'VALUE_TAX_ANOMALY' => $valueTaxAnomaly,
            'risk_weight' => $riskWeight,
        ];
    }

    private static function detectMomSpike(int $companyId): float
    {
        $currentMonth = Invoice::where('company_id', $companyId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $previousMonth = Invoice::where('company_id', $companyId)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        if ($previousMonth === 0) return 0;

        $change = (($currentMonth - $previousMonth) / $previousMonth) * 100;
        return round($change, 1);
    }

    private static function detectTaxDrop(int $companyId): float
    {
        $currentTax = DB::table('invoices')
            ->join('invoice_items', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.company_id', $companyId)
            ->whereMonth('invoices.created_at', now()->month)
            ->whereYear('invoices.created_at', now()->year)
            ->sum('invoice_items.tax');

        $previousTax = DB::table('invoices')
            ->join('invoice_items', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.company_id', $companyId)
            ->whereMonth('invoices.created_at', now()->subMonth()->month)
            ->whereYear('invoices.created_at', now()->subMonth()->year)
            ->sum('invoice_items.tax');

        if ($previousTax == 0) return 0;

        $drop = (($previousTax - $currentTax) / $previousTax) * 100;
        return round(max(0, $drop), 1);
    }

    private static function detectHsShift(int $companyId): bool
    {
        $currentHsCodes = DB::table('invoices')
            ->join('invoice_items', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.company_id', $companyId)
            ->whereMonth('invoices.created_at', now()->month)
            ->whereYear('invoices.created_at', now()->year)
            ->select(DB::raw("SUBSTRING(invoice_items.hs_code, 1, 4) as hs_prefix"))
            ->distinct()
            ->pluck('hs_prefix')
            ->toArray();

        $previousHsCodes = DB::table('invoices')
            ->join('invoice_items', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.company_id', $companyId)
            ->whereMonth('invoices.created_at', now()->subMonth()->month)
            ->whereYear('invoices.created_at', now()->subMonth()->year)
            ->select(DB::raw("SUBSTRING(invoice_items.hs_code, 1, 4) as hs_prefix"))
            ->distinct()
            ->pluck('hs_prefix')
            ->toArray();

        if (empty($previousHsCodes)) return false;

        $newCategories = array_diff($currentHsCodes, $previousHsCodes);
        $totalPrevious = count($previousHsCodes);

        return count($newCategories) > ($totalPrevious * 0.5);
    }

    private static function detectValueTaxAnomaly(int $companyId): bool
    {
        $items = DB::table('invoices')
            ->join('invoice_items', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.company_id', $companyId)
            ->whereMonth('invoices.created_at', now()->month)
            ->whereYear('invoices.created_at', now()->year)
            ->select('invoice_items.price', 'invoice_items.quantity', 'invoice_items.tax')
            ->get();

        if ($items->isEmpty()) return false;

        $highValueLowTax = 0;
        foreach ($items as $item) {
            $subtotal = $item->price * $item->quantity;
            if ($subtotal > 10000) {
                $effectiveRate = $subtotal > 0 ? ($item->tax / $subtotal) * 100 : 0;
                if ($effectiveRate < 5) {
                    $highValueLowTax++;
                }
            }
        }

        return $highValueLowTax > ($items->count() * 0.3);
    }

    private static function calculateRiskWeight(float $momSpike, float $taxDrop, bool $hsShift, bool $valueTaxAnomaly): float
    {
        $momThreshold = (float) \App\Models\SystemSetting::get('mom_spike_threshold', '200');
        $taxDropThreshold = (float) \App\Models\SystemSetting::get('tax_drop_threshold', '60');

        $weight = 0;

        if ($momSpike > $momThreshold) $weight += 20;
        elseif ($momSpike > 100) $weight += 10;
        elseif ($momSpike > 50) $weight += 5;

        if ($taxDrop > $taxDropThreshold) $weight += 20;
        elseif ($taxDrop > 40) $weight += 15;
        elseif ($taxDrop > 20) $weight += 8;

        if ($hsShift) $weight += 10;

        if ($valueTaxAnomaly) $weight += 15;

        return min(50, $weight);
    }
}
