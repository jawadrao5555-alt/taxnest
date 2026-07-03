---
name: POS whole-rupee rounding convention
description: Which POS money fields are whole-rupee vs 2dp, and how to test the held-order pay path in dev
---

# POS rounding convention (PRA POS only)

**Rule:** PRA POS header `tax_amount` + `total_amount` are WHOLE-RUPEE (`(float) round(...)`, 0dp) on EVERY path that writes them: PosController::storeInvoice (both completion paths) AND RestaurantPosController holdOrder/payOrder (held/table-order pay). Per-item line subtotals/discounts/item tax stay 2dp. Frontend cart uses Math.round — backend must match or receipts/PDF/DB drift (533.60 vs 534 class of bug).

**Why:** Owner requires whole-rupee bills; the held/table-order pay path was missed originally and stored 2dp totals while direct cart stored whole — same bill amount differed between receipt and DB/PDF.

**How to apply:** Any NEW code path that writes pos_transactions/restaurant_orders header totals must use whole-rupee rounding. Do NOT touch: FBR POS (decimals by design, FbrPosController round(...,2)) and DI invoicing (2dp convention). PRA payload is built from per-line 2dp values and never reads header total — safe. Header tax may differ from sum of 2dp line taxes by ≤Rs 0.50 — accepted.

**Testing held-order pay in dev (company 11):** company 11 is `pharmacy` category with restaurant features OFF. The pay route (/pos/restaurant/orders/{id}/pay) is behind RestaurantOnly middleware (needs `restaurant_mode=1` or pos_type=restaurant) AND held list only renders when feature_flags kot=true AND kitchen=true (kot depends on kitchen via PosFeatureService::DEPENDENCIES). Temporarily flip via JSON_SET, seed restaurant_orders+items row directly, test, then restore flags and delete test rows.
