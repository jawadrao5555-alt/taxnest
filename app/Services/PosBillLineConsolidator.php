<?php

namespace App\Services;

/**
 * Task 778 — CUSTOMER BILL LINE CONSOLIDATION (single truth for ALL surfaces).
 *
 * The quantity-aware KOT carry (RestaurantPosController::holdOrder) stores an
 * increased line as separate stamped ("already sent") + unprinted ("delta")
 * restaurant_order_items rows so delta tickets print only the added qty.
 * Those split rows are a kitchen-printing detail: any CUSTOMER-facing bill —
 * final transaction items (payOrder) and the proof bill on BOTH render paths
 * (cashier iframe route + silent Desktop Agent proof job) — must merge them
 * back into one line per dish via this consolidator. Never render a customer
 * bill from raw restaurant_order_items.
 *
 * Identity = type/id/name/notes/unit_price/tax-exemption/discount-type
 * (+ rate for percentage discounts). Quantities, subtotals and discount
 * amounts sum; 'amount' discount VALUES sum too (the carry split them
 * proportionally, so the merged value equals the original). Genuinely
 * distinct cart lines (different notes/price/discount) never merge.
 */
class PosBillLineConsolidator
{
    /**
     * @param iterable<\App\Models\RestaurantOrderItem> $items
     * @return \Illuminate\Support\Collection<int, \App\Models\RestaurantOrderItem> unsaved display-only copies
     */
    public static function consolidate($items)
    {
        $groups = [];
        foreach ($items as $item) {
            $discType = $item->item_discount_type ?? null;
            $key = implode('|', [
                (string) $item->item_type,
                $item->item_id !== null ? (string) $item->item_id : 'null',
                mb_strtolower(trim((string) $item->item_name)),
                trim((string) ($item->special_notes ?? '')),
                number_format((float) $item->unit_price, 2, '.', ''),
                (int) (bool) $item->is_tax_exempt,
                $discType ?? 'none',
                $discType === 'percentage' ? number_format((float) ($item->item_discount_value ?? 0), 2, '.', '') : '',
            ]);
            if (!isset($groups[$key])) {
                // Unsaved copy — display/serialization only, never persisted.
                $groups[$key] = $item->replicate();
            } else {
                $g = $groups[$key];
                $g->quantity = (float) $g->quantity + (float) $item->quantity;
                $g->subtotal = round((float) $g->subtotal + (float) $item->subtotal, 2);
                $g->item_discount_amount = round((float) ($g->item_discount_amount ?? 0) + (float) ($item->item_discount_amount ?? 0), 2);
                if ($discType === 'amount') {
                    $g->item_discount_value = round((float) ($g->item_discount_value ?? 0) + (float) ($item->item_discount_value ?? 0), 2);
                }
            }
        }
        return collect(array_values($groups));
    }
}
