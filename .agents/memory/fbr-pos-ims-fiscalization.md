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
- **Pre-submit guard** in `submitFbrPosTransaction`: fail cleanly ONLY if POSID empty (no
  `fbr_pos_id`) — clear hash + failed log + `fbr_status='failed'` + return `{status:failed,errors}`.
  Return shape stays `{status, errors, fbr_invoice_number, fbr_response}` (6 consumers rely on it).
- **HS code / PCTCode is OPTIONAL for FBR IMS POS** — unlike DI where hsCode is mandatory. Retail POS
  items often have no HS code, so send PCTCode when present and blank otherwise; NEVER block the bill
  on a missing HS code. (Owner confirmed Jul 2026 — an earlier PCTCode-required guard was removed.)
- **Anti-churn:** `SyncFbrPosOfflineInvoicesJob` must skip companies missing `fbr_pos_token` OR
  `fbr_pos_id`, else guard-failed bills get re-picked every tick → a fresh `FbrPosLog` row per bill
  per 2-min tick.

**Items table gotcha:** the relation `$transaction->items()` maps to `fbr_pos_transaction_items`
(NOT `fbr_pos_items`, which does not exist).

**Auth errors on `/imsp/v1`:** `900901 Invalid Credentials` = the token itself is invalid/empty/
malformed to the FBR gateway (distinct from `900908 Resource forbidden` = valid token, not authorized
for that API). Since the empty-token guard already fires before send, a 900901 means a NON-empty token
was sent and rejected → causes: wrong/expired/mistyped token, sandbox token on the production URL (or
vice-versa — env must match the token), copy-paste whitespace/newline in the token (now defended by
`trim()` on both save and read), or the NTN not yet enrolled for IMS POS on the FBR portal. Token
column is TEXT (not truncated). This is a CONFIG issue, not a code bug — the endpoint/path is correct.
FBR-confirmed #1 cause of 900901 is **sandbox token on the production URL** (WSO2 routes by token type). A business
gets a **production token ONLY after passing FBR sandbox certification**, so a live 900901 usually means they only
hold a sandbox token yet → steer them to test on Sandbox first, then FBR issues the prod token.

**Disambiguation probe (proven technique):** to tell a *DI-only* token apart from a *POS-enrolled* token, POST a
throwaway `{}` (or minimal body) with the SAME token to BOTH gateways and compare the fault:
- `imsp/v1/api/Live/PostData` → `900908 Resource forbidden` **and** `di_data/v1/di/postinvoicedata` → HTTP 200 with a
  DI business-validation error (e.g. errorCode `0012` buyer-type) ⇒ the token is a **Digital Invoicing token, NOT a
  POS token**. It authenticates fine but its WSO2 application is only subscribed to `di_data/v1`, never `imsp/v1`.
  Fix is 100% on FBR's side: the owner must enroll/subscribe THIS token's application for the IMS POS (POS
  fiscalization, SRO 1279/2021) service tied to their POS Registration No — a DI token can NEVER submit POS bills.
- `900908` on BOTH endpoints ⇒ token valid but NTN not production-enrolled for either service.
- `900901` on both ⇒ token itself invalid/expired/sandbox-on-prod.
A real prod submission for X-WAY SHOES (POSID 196339) with the owner-supplied production token confirmed the first
case: 900908 on IMS, 200-with-DI-validation on DI ⇒ it is a DI token, hence every FBR POS bill fails. The settings token
mask (`$maskedPosToken`) is DISPLAY-ONLY (blade placeholder + status line); the save path only writes on
`$request->filled(...)` and always stores the full encrypted token, and getFbrPosToken sends the full decrypted
token — masking never touches what is stored or sent, so it can NEVER cause 900901. `testConnection()` now parses
the response BODY for `fault.code` (json + `\b9009\d{2}\b` regex fallback) so it reports 900901/900908/900902
accurately instead of only checking HTTP 401.

**Sandbox-verify (spec ambiguity, not bugs):** whether FBR expects header `Discount` = ALL discounts
(item+bill) vs bill-only, and whether item `TotalAmount` = SaleValue+Tax vs SaleValue+Tax−Discount.
Current code is internally consistent (SaleValue already net; item Discount informational). Settle by
sending one sandbox bill that has BOTH an item discount and a bill discount.
