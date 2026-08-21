<?php

namespace App\Services;

use App\Models\InventoryStock;
use App\Models\Product;

/**
 * FBR POS "Peti (Wholesale) Rate" (Task 1414).
 *
 * WHY a service: the peti rate must be derived in exactly ONE place — the sale
 * screen bake (create()) and any future surface both call here, so the money
 * math can never drift. Cost price NEVER leaves this class; callers only ever
 * receive the finished peti rate (a customer-facing number), never the cost.
 *
 * Derivation: peti rate = avg purchase cost × (1 + margin%/100), then two
 * SAFETY CLAMPS that make the feature go silent on a line rather than sell at a
 * bad price:
 *   - never BELOW cost (a stale/zero margin must not sell at a loss),
 *   - never ABOVE the retail price (a peti rate is a DISCOUNT; if the derived
 *     rate lands at/above retail the shop's costs already exceed its retail
 *     margin — leave the line at retail).
 * A product with no pack_size, no cost, or a clamped-out rate is returned with
 * peti_rate = null ⇒ the line behaves exactly as today.
 */
class FbrPetiRateService
{
    /**
     * Company-wide peti margin as a fraction (e.g. 0.03 for 3%). Falls back to
     * the 3% default so a NULL column never yields a divide-by-nothing.
     */
    public static function marginFraction($company): float
    {
        $pct = (float) ($company->fbr_peti_margin_pct ?? 3.0);
        if ($pct < 0) { $pct = 0.0; }
        return $pct / 100.0;
    }

    /**
     * Compute the customer-facing peti rate for a product from a preloaded
     * avg-cost value, or NULL when the feature must stay silent on this line.
     *
     * @param float|null $avgCost avg purchase cost (>0) or last cost fallback
     * @param float      $retail  the product's retail (default) price
     * @param float      $margin  margin fraction from marginFraction()
     */
    public static function deriveRate(?float $avgCost, float $retail, float $margin): ?float
    {
        // No cost known ⇒ we can't build a rate safely (plan: "kharid rate
        // maloom nahi to kuch nahi hota").
        if ($avgCost === null || $avgCost <= 0) {
            return null;
        }
        $rate = round($avgCost * (1 + $margin), 2);
        // CLAMP 1 — never below cost.
        if ($rate < $avgCost) {
            $rate = round($avgCost, 2);
        }
        // CLAMP 2 — a peti rate must be a discount off retail. At/above retail
        // ⇒ silent (plan: "kabhi retail rate se ooper nahi jata").
        if ($retail <= 0 || $rate >= $retail) {
            return null;
        }
        return $rate;
    }

    /**
     * Batch: map product_id ⇒ peti rate for a company's products, using the
     * SERVER's avg purchase cost. Only products with a usable pack_size AND a
     * derivable rate appear in the map; everything else is absent (line stays
     * retail). branchId is honoured so a per-branch cost is used when set.
     *
     * @param \Illuminate\Support\Collection $products Product models
     * @return array<int,float> product_id => peti_rate
     */
    public static function ratesForProducts($company, $products, ?int $branchId = null): array
    {
        $margin = self::marginFraction($company);
        $companyId = $company->id;

        // Products with a real pack size are the only candidates.
        $candidateIds = [];
        foreach ($products as $p) {
            if ((int) ($p->pack_size ?? 0) > 0) {
                $candidateIds[] = (int) $p->id;
            }
        }
        if (empty($candidateIds)) {
            return [];
        }

        // ONE cost lookup — cost stays server-side, only rates leave.
        $stockQ = InventoryStock::where('company_id', $companyId)
            ->whereIn('product_id', $candidateIds);
        if ($branchId !== null) {
            $stockQ->where('branch_id', $branchId);
        }
        $costByProduct = [];
        foreach ($stockQ->get(['product_id', 'avg_purchase_price', 'last_purchase_price']) as $s) {
            // Prefer avg, fall back to last (same rule as the sale cost snapshot).
            $avg = (float) $s->avg_purchase_price;
            $last = (float) $s->last_purchase_price;
            $cost = $avg > 0 ? $avg : ($last > 0 ? $last : 0.0);
            // When several branch rows exist (branchId null), keep the max avg
            // seen — a conservative cost floor keeps the rate above real cost.
            if (!isset($costByProduct[$s->product_id]) || $cost > $costByProduct[$s->product_id]) {
                $costByProduct[$s->product_id] = $cost;
            }
        }

        $rates = [];
        foreach ($products as $p) {
            if ((int) ($p->pack_size ?? 0) <= 0) { continue; }
            $cost = $costByProduct[$p->id] ?? null;
            $rate = self::deriveRate(
                $cost !== null && $cost > 0 ? $cost : null,
                (float) ($p->default_price ?? 0),
                $margin
            );
            if ($rate !== null) {
                $rates[(int) $p->id] = $rate;
            }
        }
        return $rates;
    }
}
