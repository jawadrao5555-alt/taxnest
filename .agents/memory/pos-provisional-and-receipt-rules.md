---
name: POS provisional & receipt-tax rules
description: Canonical definition of a "provisional/local" POS bill and the PRA receipt-tax compliance rule.
---

# Provisional / "Local" bill definition (single source of truth)

A genuine provisional (a.k.a. "Local") POS bill is:
`status = 'completed'` **AND** `invoice_mode = 'local'` **AND** `pra_status = 'local'`.

**Why:** Drafts/held orders are created with `status='draft'` and (when PRA is on) `invoice_mode='pra'`,
yet `pra_status` defaults to `'local'`. Filtering on `pra_status='local'` alone leaks drafts into the
F10 provisional modal and mismatches the Transactions "Local" tab — this caused the owner's
"provisional billing show nahi hoti" report.

**How to apply:**
- Every endpoint that lists / promotes / deletes provisional bills (F10 modal `apiProvisionalBills`,
  `apiPromoteProvisional`, `apiDeleteProvisional`) MUST filter on all three fields, not just `pra_status`.
- On draft-resume completion (`PosController::storeInvoice` draft-update branch), ALWAYS write
  `invoice_mode => $invoiceMode`. Omitting it leaves a stale `invoice_mode='pra'` and orphans the bill
  from BOTH the Local tab and the Failed-bills list.
- Both save paths already land provisional correctly: retail `storeInvoice` and restaurant
  `RestaurantPosController::payHeldOrder` set completed + local + local when `save_as_provisional`.

# Promoting a provisional → PRA final (re-tax + renumber, Jul 2026)

Promoting an F10 "Local" bill to final is NOT a status-flip only. It must:
1. **Ask cash vs card at promote time and RE-TAX** — the two carry different PRA rates
   (global 16% cash / 8% card). The picker preselects the stored `payment_method`; the chosen
   method re-drives `PosTaxRule::getRateForMethod()` and the whole bill is recomputed.
2. **Allot a fresh POS serial** (`generateInvoiceNumber` → `POS-YYYY-NNNNN`), replacing the
   provisional `L-NNN` — a final bill must never keep the local number. New `submission_hash` too.

**Why:** cashiers save provisionals before the customer decides how to pay; the final tax + number
are only knowable at promote. `PraIntegrationService::sendInvoice` sends `invoice_number` as the
**USIN** and builds the payload from **per-item `tax_rate`** — so BOTH the renumber AND the per-item
re-tax MUST happen BEFORE the submit call, or PRA fiscalizes the L- number with stale rates.

**How to apply:** recompute mirrors `storeInvoice` exactly — from STORED data (`tx->subtotal`,
absolute `tx->discount_amount`, sum of non-exempt `item->subtotal`); header tax+total whole-rupee,
item lines 2dp. Do it all inside ONE `DB::transaction` with `lockForUpdate` + re-check the three
provisional fields (race-safe against F10 double-Enter), then PRA `sendInvoice` STRICTLY after commit.
`PosPayment::updateOrCreate` keyed on `transaction_id` (restaurant-origin provisionals may lack a row).
Frontend: the `praEnabled` gate on "Make Final" must be REMOVED (reporting-OFF finalize is valid).

# Reporting-OFF finals invariant (PRA & FBR POS — Jul 2026)

A FINAL sale for a company whose regulator reporting is OFF (`pra_reporting_enabled=0` /
`fbr_reporting_enabled=0`) is stored as **regulator mode + NULL status**:
PRA → `invoice_mode='pra'` + `pra_status=NULL`; FBR → `invoice_mode='fbr'` + `fbr_status=NULL`.
Never `'local'` — local is RESERVED for deliberate provisionals.

**Why:** 'local' finals vanish from transactions/KPIs/reports (which filter to regulator-mode/NULL)
and pollute the F10 provisional modal where cashiers can edit/delete/promote a final bill.
Found via fresh-account simulation — every pre-existing company had reporting ON, so only brand-new
registrations hit it. NULL status is safe: fail-queue/retry/agent/day-close all key off explicit
status values (`whereIn` excludes NULL). `fbr_pos_transactions.fbr_status` was made nullable for this.

**How to apply — EVERY write path must use the three-branch:**
provisional → local/'local'; reporting-ON final → regulator-mode/'pending'; reporting-OFF final →
regulator-mode/NULL. Paths already converted: `storeInvoice`, `updateTransaction` (edit must NOT
regress NULL→'local'), `retryPra` + `apiPromoteProvisional` (PRA-off promote = finalize to NULL
WITHOUT submission, not a "enable PRA first" block), `FbrPosController::store` (also skips
FbrService submission entirely and charges NO Rs-1 fee when `$initialFbrStatus === null`).
Any NEW save/edit/promote path must follow the same three-branch or the invariant breaks.

**Recurring regression (Jul 2026 e2e found 2 more violators):** write paths keep re-introducing
the old TWO-branch (`praEnabled ? 'pra' : 'local'`). Fixed then: `RestaurantPosController::payOrder`
(reporting-OFF held-order pay stored final as local/'local' with an L- number) and
`FbrPosController::apiPromoteProvisional` (unconditionally flipped to fbr/'pending' even with
reporting OFF — bill sat in the fail-queue forever). When auditing, grep every place that writes
`pra_status`/`fbr_status` and check for a ternary on the reporting flag — that shape IS the bug.
Historic prod rows from before these fixes are NOT backfilled (local/'local' finals are
indistinguishable from deliberate provisionals).

# Receipt tax display rule (OWNER OVERRIDE, Jul 2026)

The `companies.pos_receipt_show_tax` toggle applies to ALL receipts — including PRA fiscal ones.
Toggle OFF = customer copy hides BOTH the Subtotal and Tax lines and shows only the grand TOTAL
(discount line stays if present). PRA number + QR remain on the receipt.

**Why:** Owner's explicit business decision — Pakistani customers dislike seeing separate tax, shopkeepers
want tax-inclusive-looking receipts. Earlier fiscal-always-show guard was rejected by the owner. Hiding
subtotal along with tax is deliberate: subtotal alone (750 vs TOTAL 870) reveals the tax gap. Full details
remain visible via PRA Sahulat app QR scan; tax is ALWAYS submitted to PRA in full regardless of display.

**How to apply:** In receipt_80mm, receipt_58mm, invoice-pdf: a single `$showTaxLines` boolean
(`optional($transaction->company)->pos_receipt_show_tax ?? true`) guards BOTH subtotal and tax rows.
Do NOT re-add `|| invoice_mode==='pra' || pra_invoice_number` overrides. transaction-show (internal admin
view) keeps full details. restaurant/receipt.blade.php is DEAD (RestaurantPosController::receipt renders
the shared receipt_80mm/58mm templates).

**Tax-hidden mode shows ASAL (as-entered, ex-tax) line prices — owner REVERSED the gross-up (Jul 2026):**
when the toggle is OFF, item Rate/Amt print the stored `unit_price`/`subtotal` exactly as typed on the
sale screen; lines intentionally do NOT sum to the grand TOTAL (owner: shelf prices must read exactly
as typed). TOTAL + discount rows still print whole-rupee rounded. `inclusiveLineAmounts()` is now dead
code — do NOT re-wire the tax-inclusive gross-up into the templates. NT/EXEMPT tags and the invoice-pdf
Tax% column remain hidden in this mode.

**Toggle location (owner-mandated):** the checkbox lives on the Receipt Settings page
(`/pos/receipt-settings`, field `rp_show_tax`, saved in `receiptSettings()`), NOT on the Features page.
`updateFeatureSettings()` must NEVER write `pos_receipt_show_tax` — a checkbox absent from that form
would silently force it off on every Features save.
