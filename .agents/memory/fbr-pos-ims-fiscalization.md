---
name: FBR POS uses IMS Fiscalization, not DI
description: FBR POS (fbrpos guard) submits to FBR IMS POS Fiscalization (SRO 1279/2021), a different system/payload/token than Digital Invoicing — how the payload is built and why.
---

# FBR POS = IMS Fiscalization (SRO 1279/2021), NOT Digital Invoicing

**Rule:** FBR POS invoice submission uses the FBR **IMS POS Fiscalization** system, which is a
completely different API, payload model, and token than FBR **Digital Invoicing** (DI, `di_data/v1`).
DI and PRA POS submission are untouched by this — only the `fbrpos` guard path changed. Owner decision:
FBR POS uses IMS ONLY, no toggle/fallback.

**Why:** New POS registrations are rejected on the DI endpoint (`900908 Resource forbidden`) because a
DI token is not authorized on IMS. Owner wants FBR POS to be pure IMS.

**How to apply — all in `FbrService.php`, FBR-POS methods only:**
- Endpoints = IMS URLs (sandbox `esp.fbr.gov.pk:8244/FBR/v1/api/Live/PostData`, prod
  `gw.fbr.gov.pk/imsp/v1/api/Live/PostData`). Do NOT reuse DI `di_data/v1` URLs or the DI-only
  `fbr_production_url`/`fbr_sandbox_url` company overrides.
- Token = `company->fbr_pos_token` ONLY. There is NO fallback to DI tokens (`fbr_bearer_token` /
  env sandbox/production tokens). A DI token on IMS reproduces the 900908 error.
- Payload = IMS invoice model: flat header (POSID int from `fbr_pos_id`, USIN=`invoice_number`,
  DateTime `Y-m-d H:i:s` Asia/Karachi, Buyer NTN vs CNIC by digit-count 13=CNIC, TotalSaleValue/
  TotalTaxCharged/TotalQuantity/Discount/TotalBillAmount/PaymentMode int/InvoiceType 1) + `Items[]`
  (ItemCode/ItemName/Quantity/PCTCode≤8digits/TaxRate/SaleValue/TotalAmount/TaxCharged/Discount/
  FurtherTax/InvoiceType/RefUSIN).
- **Item amounts MUST come from the stored fiscal snapshots** `$item->subtotal` (net, excl tax, after
  item discount) and `$item->tax_amount`. NEVER re-derive from `unit_price × qty` — that silently
  over-reports **tax-inclusive** bills (cart `tax_inclusive` mode reverse-derives net into those
  columns; re-deriving adds tax on top of an already-inclusive price).
- **Bill-level discount:** header `Discount` = `$transaction->discount_amount` ONLY (bill-level
  manual + promotion, applied POST-tax in `store()`). Item SaleValues already net their own item
  discounts, so `TotalBillAmount = ΣSaleValue + ΣTax − billDiscount` (no double-subtract). This equals
  goods payable and EXCLUDES the app-only Rs 1 FBR service fee and loyalty redemption.
- **Success = Code `"100"`** (+ InvoiceNumber/FBRInvoiceNumber in the response). Store
  `fbr_response_code='100'`. Reuse `sendDirectToFbr` (Bearer + application/json) unchanged.
- **Pre-submit guards** in `submitFbrPosTransaction`: fail cleanly if POSID empty (no `fbr_pos_id`)
  or any item PCTCode empty (missing `hs_code`) — clear hash + failed log + `fbr_status='failed'` +
  return `{status:failed,errors}`. Return shape stays `{status, errors, fbr_invoice_number, fbr_response}`
  (6 consumers rely on it).
- **Anti-churn:** `SyncFbrPosOfflineInvoicesJob` must skip companies missing `fbr_pos_token` OR
  `fbr_pos_id`, else guard-failed bills get re-picked every tick → a fresh `FbrPosLog` row per bill
  per 2-min tick.

**Items table gotcha:** the relation `$transaction->items()` maps to `fbr_pos_transaction_items`
(NOT `fbr_pos_items`, which does not exist).

**Sandbox-verify (spec ambiguity, not bugs):** whether FBR expects header `Discount` = ALL discounts
(item+bill) vs bill-only, and whether item `TotalAmount` = SaleValue+Tax vs SaleValue+Tax−Discount.
Current code is internally consistent (SaleValue already net; item Discount informational). Settle by
sending one sandbox bill that has BOTH an item discount and a bill discount.
