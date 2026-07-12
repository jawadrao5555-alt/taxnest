---
name: POS whole-rupee rounding convention
description: Which POS money fields are whole-rupee vs 2dp, and how to test the held-order pay path in dev
---

# POS rounding convention (PRA POS only)

**Rule:** PRA POS header `tax_amount` + `total_amount` are WHOLE-RUPEE (`(float) round(...)`, 0dp) on EVERY path that writes them: PosController::storeInvoice (both completion paths) AND RestaurantPosController holdOrder/payOrder (held/table-order pay). Per-item line subtotals/discounts/item tax stay 2dp. Frontend cart uses Math.round — backend must match or receipts/PDF/DB drift (533.60 vs 534 class of bug).

**Why:** Owner requires whole-rupee bills; the held/table-order pay path was missed originally and stored 2dp totals while direct cart stored whole — same bill amount differed between receipt and DB/PDF.

**How to apply:** Any NEW code path that writes pos_transactions/restaurant_orders header totals must use whole-rupee rounding. Do NOT touch: FBR POS (decimals by design, FbrPosController round(...,2)) and DI invoicing (2dp convention). Header tax may differ from sum of 2dp line taxes by ≤Rs 0.50 — accepted.

**PRA payload is ALSO a rounding surface (the 672.88 bug):** the payload's TotalBillAmount was the sum of per-line 2dp TotalAmounts (SaleValue+TaxCharged), so PRA received 672.88 while receipt/DB said 673 (580.07 @16% = exactly that). Fix lives in PraIntegrationService::generatePayload: pick a whole-rupee target (stored total for no-exempt bills, round(sum) when exempt lines were filtered out) and absorb the paisa diff into the largest line's TaxCharged (fallback SaleValue), then recompute the three header sums — Items must always sum EXACTLY to TotalBillAmount and each line keep SaleValue+TaxCharged==TotalAmount. Any future payload change must preserve whole-rupee TotalBillAmount. Related: AgentController::pendingInvoices must skip all-exempt bills (mark exempt_internal, mirrors sendInvoice) or the fiscal-device agent gets an empty-Items payload.

**Frontend display trap (whole-rupee is NOT just a backend concern):** the PRA universal sale screen has THREE total surfaces that must all agree — cart display (`roundedTotal` getter, whole), backend stored total (round() to int, whole), and the success-popup receipt total (`lastTotal`). The popup was the leak: `lastTotal` was set from `savedTotal` (= the raw 2dp `totalAmount` getter), so the popup showed decimals while cart + DB + printed receipt were whole ("second bill" symptom — only visible when a bill's total had a fractional part). Fix = round on assignment (`Math.round(savedTotal || data.total_amount || 0)`) on BOTH lastTotal write sites (direct manual-cart pay AND payHeldOrderDirect held-order pay). The PRINTED thermal receipt is backend-URL rendered (already whole) — only the on-screen popup drifted. Rule: any client-side surface that shows the PRA final total must round; never display the raw `totalAmount`/`savedTotal`.

**Testing held-order pay in dev (company 11):** company 11 is `pharmacy` category with restaurant features OFF. The pay route (/pos/restaurant/orders/{id}/pay) is behind RestaurantOnly middleware (needs `restaurant_mode=1` or pos_type=restaurant) AND held list only renders when feature_flags kot=true AND kitchen=true (kot depends on kitchen via PosFeatureService::DEPENDENCIES). Temporarily flip via JSON_SET, seed restaurant_orders+items row directly, test, then restore flags and delete test rows.
