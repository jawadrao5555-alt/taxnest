<?php

namespace App\Services;

/**
 * Tax-Inclusive Pricing (Menu-Rate-Final) math — owner feature Jul 2026.
 *
 * When a company runs in inclusive mode, the menu price IS the grand total the
 * customer pays; base + tax are back-calculated per payment method at billing
 * time. Storage design ("B-prime", architect-approved):
 *
 *   - pos_transaction_items keep the INCLUSIVE (menu) prices exactly as entered
 *     (unit_price / subtotal) — so edit screens show menu prices, and promoting
 *     a provisional to a different payment method re-derives base+tax from the
 *     unchanged inclusive lines (total stays = menu, guaranteed).
 *   - HEADER columns stay ex-tax-consistent:
 *       subtotal(col)   = inclusiveSum − included tax   (2dp)
 *       tax_amount      = included tax                  (2dp — NOT whole-rupee;
 *                         whole-rupee tax would corrupt the derived base in
 *                         tax reports by up to Rs 0.50)
 *       exempt_amount   = exempt share after discount   (2dp)
 *       total_amount    = round(inclusiveSum − discount) WHOLE RUPEE (menu guarantee)
 *     so the existing report identity `subtotal − discount − exempt = base
 *     taxable` holds and tax reports / day-close / analytics need no changes.
 *
 * EXCLUSIVE mode math is untouched — this class is only entered when the
 * bill's tax_inclusive snapshot (or the company setting, for new bills) is ON.
 */
class PosTaxMath
{
    /**
     * Header figures for an inclusive bill.
     *
     * CARD-SAVE VARIANT (mode "inclusive_card_save", owner Jul 2026): when
     * $menuRate is given AND differs from $rate, the menu price is inclusive at
     * the MENU (cash) rate and the bill's own method rate is applied on the
     * derived base instead — card bills get CHEAPER (e.g. menu 590 @16% menu
     * rate → base 508.62 → card 8% → total 549). When $menuRate is null or
     * equals $rate this reduces ALGEBRAICALLY to the classic inclusive math —
     * the classic branch below is kept verbatim (zero regression risk).
     *
     * @param float $subtotalIncl   sum of line subtotals at menu (inclusive) prices, before discount
     * @param float $taxableIncl    sum of NON-EXEMPT line subtotals (inclusive)
     * @param float $discountAmount absolute discount entered on menu prices
     * @param float $rate           tax rate %, per payment method
     * @param float|null $menuRate  MENU (cash) rate the prices are inclusive at — card-save mode only
     * @return array{subtotal_col: float, tax_amount: float, exempt_amount: float, total_amount: float, taxable_incl_after_discount: float}
     */
    public static function inclusiveHeader(float $subtotalIncl, float $taxableIncl, float $discountAmount, float $rate, ?float $menuRate = null): array
    {
        $afterDiscount = $subtotalIncl - $discountAmount;
        $tia = $subtotalIncl > 0 ? round($taxableIncl / $subtotalIncl * $afterDiscount, 2) : 0.0;
        $exempt = round($afterDiscount - $tia, 2);

        if ($menuRate !== null && $menuRate > 0 && abs($menuRate - $rate) >= 0.005) {
            // Card-save: base derived at the MENU rate, bill's own rate applied on top.
            // Single-expression tax (TIA·r/(100+mr)) avoids double-rounding the base.
            $base = round($tia * 100 / (100 + $menuRate), 2);
            $tax = round($tia * $rate / (100 + $menuRate), 2);

            return [
                // Report identity subtotal − discount − exempt = taxable base HOLDS.
                'subtotal_col' => round($base + $discountAmount + $exempt, 2),
                'tax_amount' => $tax,
                'exempt_amount' => $exempt,
                'total_amount' => (float) round($base + $tax + $exempt),
                'taxable_incl_after_discount' => $tia,
            ];
        }

        $tax = $rate > 0 ? round($tia * $rate / (100 + $rate), 2) : 0.0;

        return [
            'subtotal_col' => round($subtotalIncl - $tax, 2),
            'tax_amount' => $tax,
            'exempt_amount' => $exempt,
            // Menu guarantee: customer pays exactly the menu sum minus discount,
            // whole rupee, IDENTICAL for cash and card.
            'total_amount' => (float) round($afterDiscount),
            'taxable_incl_after_discount' => $tia,
        ];
    }

    /**
     * Included-tax portion of one INCLUSIVE line (after its discount share).
     * Exempt lines must pass rate 0 (returns 0.0).
     * Card-save bills pass $menuRate (base derived at menu rate, own rate applied).
     */
    public static function inclusiveLineTax(float $lineAfterDiscount, float $rate, ?float $menuRate = null): float
    {
        if ($rate <= 0) {
            return 0.0;
        }
        if ($menuRate !== null && $menuRate > 0 && abs($menuRate - $rate) >= 0.005) {
            return round($lineAfterDiscount * $rate / (100 + $menuRate), 2);
        }
        return round($lineAfterDiscount * $rate / (100 + $rate), 2);
    }
}
