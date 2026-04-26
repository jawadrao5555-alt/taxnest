<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 PHASE 3 — Smart Pricing Service
 *
 * Two algorithms:
 *   1. calculateMarginPrice(cost, marginPercent) — straight markup
 *   2. suggestPrice(productId) — dynamic, sales-velocity-based:
 *        - fast-selling (sold > avg) → +5%
 *        - slow-selling (sold < avg) → -5%
 *        - typical → keep current
 *
 * Read-only by default (callers persist if they want).
 * NEVER touches FbrService, PRA POS, Digital Invoice.
 */
class PricingService
{
    public const STRATEGY_MANUAL = 'manual';
    public const STRATEGY_MARGIN = 'margin';
    public const STRATEGY_DYNAMIC = 'dynamic';

    public const FAST_BUMP = 0.05;   // +5%
    public const SLOW_DROP = -0.05;  // -5%

    /**
     * Pure markup: cost * (1 + margin%).
     * Returns null if cost is invalid.
     */
    public function calculateMarginPrice(?float $cost, float $marginPercent): ?float
    {
        if ($cost === null || $cost <= 0) {
            return null;
        }
        if ($marginPercent < 0) {
            $marginPercent = 0;
        }
        return round($cost * (1 + $marginPercent / 100), 2);
    }

    /**
     * Sales-velocity-based suggestion.
     *
     * @return array{
     *     product_id: int,
     *     current_price: float,
     *     suggested_price: float|null,
     *     strategy: string,
     *     verdict: string,
     *     units_last_7d: float,
     *     company_avg_units_7d: float
     * }
     */
    public function suggestPrice(int $productId): array
    {
        $product = Product::find($productId);
        if (! $product) {
            return [
                'product_id' => $productId,
                'current_price' => 0,
                'suggested_price' => null,
                'strategy' => self::STRATEGY_MANUAL,
                'verdict' => 'product_not_found',
                'units_last_7d' => 0,
                'company_avg_units_7d' => 0,
            ];
        }

        $companyId = $product->company_id;
        $since = Carbon::now()->subDays(7)->toDateString();

        // Units sold for THIS product in last 7 days (within its company)
        $myUnits = (float) DB::table('fbr_pos_transaction_items as i')
            ->join('fbr_pos_transactions as t', 't.id', '=', 'i.transaction_id')
            ->where('t.company_id', $companyId)
            ->where('t.status', 'completed')
            ->whereDate('t.created_at', '>=', $since)
            ->where(function ($q) use ($product) {
                $q->where('i.product_id', $product->id)
                    ->orWhere('i.item_name', $product->name);
            })
            ->sum('i.quantity');

        // Company-wide avg units per product (last 7d) → baseline
        $companyAvg = (float) DB::table('fbr_pos_transaction_items as i')
            ->join('fbr_pos_transactions as t', 't.id', '=', 'i.transaction_id')
            ->where('t.company_id', $companyId)
            ->where('t.status', 'completed')
            ->whereDate('t.created_at', '>=', $since)
            ->selectRaw('SUM(i.quantity) / NULLIF(COUNT(DISTINCT i.item_name), 0) AS avg_units')
            ->value('avg_units') ?? 0;

        $current = (float) $product->default_price;

        if ($companyAvg <= 0 || $myUnits <= 0) {
            // No baseline or no sales yet → no change
            return [
                'product_id' => $product->id,
                'current_price' => $current,
                'suggested_price' => $current,
                'strategy' => self::STRATEGY_DYNAMIC,
                'verdict' => 'insufficient_data',
                'units_last_7d' => round($myUnits, 4),
                'company_avg_units_7d' => round($companyAvg, 4),
            ];
        }

        $verdict = 'typical';
        $factor = 0.0;
        if ($myUnits > $companyAvg) {
            $verdict = 'fast_selling';
            $factor = self::FAST_BUMP;
        } elseif ($myUnits < $companyAvg) {
            $verdict = 'slow_selling';
            $factor = self::SLOW_DROP;
        }

        $suggested = round($current * (1 + $factor), 2);

        return [
            'product_id' => $product->id,
            'current_price' => $current,
            'suggested_price' => $suggested,
            'strategy' => self::STRATEGY_DYNAMIC,
            'verdict' => $verdict,
            'units_last_7d' => round($myUnits, 4),
            'company_avg_units_7d' => round($companyAvg, 4),
        ];
    }
}
