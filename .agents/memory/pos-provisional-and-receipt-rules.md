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

# Receipt PROVISIONAL badge = invoice_mode 'local' ONLY (client bug, Jul 2026)

The receipt templates' non-fiscal fallback branch (not submitted, not offline) must NOT
unconditionally print "PROVISIONAL BILL" — reporting-OFF finals ('pra'+NULL) land there too and
they are REAL completed sales. A ZFC client paid-as-final and the slip still said PROVISIONAL.

**How to apply:** in receipt_80mm, receipt_58mm, invoice-pdf the non-fiscal branch computes
`$rcptIsProvisional = ($transaction->invoice_mode ?? 'pra') === 'local'` — true → PROVISIONAL BILL,
false → neutral "SALE RECEIPT" + invoice number (QR payload 'type' follows the same ternary).
pending/failed fiscal bills also read SALE RECEIPT (acceptable — they are finals).
Fiscal + offline branches untouched. Any new receipt surface must use the same invoice_mode test,
never "not fiscal ⇒ provisional".

**Badge position (owner + Pizza Master photo, 22 Jul 2026):** the SALE RECEIPT / PROVISIONAL BILL
serial box prints at the TOP of the slip (right under the shop header, via `$rcptTopBadge` /
`$rcptTopProvisional` set in the header @php) for non-fiscal, non-offline bills. PRA-submitted and
offline bills KEEP the classic "POS Invoice #" box (+ OFFLINE badge). The old bottom badge boxes
were REMOVED — don't re-add a second serial box at the bottom (QR stays at the bottom). Every
branch must still print the serial somewhere — no serial-less path.

**PAYMENT box = DELIVERY bills ONLY (owner, 22 Jul 2026):** the boxed "PAYMENT: CASH/CARD" prints
only when `order_type==='delivery' || delivery_address || rider` (order_type is the primary gate —
riders are assigned board-only AFTER payment and address is optional, so the fallbacks alone miss
address-less delivery bills). Non-delivery receipts show no payment box (Local set shows a plain
Payment info row instead). Purpose: riders must see cash-to-collect at a glance.

# Per-type receipt display sets — PRA vs Local (owner, Jul 2026)

PRA and Local receipts each carry a FULL independent display set (address, NTN, email, mobile,
cashier, show_tax, footer + footer_text), both edited on `/pos/receipt-settings` (two tabs, one form —
both tab panels stay in the DOM via x-show so ONE save submits BOTH sets; `rp_*` = PRA, `lp_*` = Local).

**Storage:** PRA set = legacy `invoice_display_prefs['pos']` + `pos_receipt_show_tax` column (column
stays the PRA tax source — PRA Settings compatibility). Local set = `invoice_display_prefs['pos_local']`
incl. its own `show_tax` key; when the `pos_local` key is absent the Local set MIRRORS the PRA set
(`Company::posReceiptPrefs('local')` falls back), so pre-split companies see zero behavior change.

**Type resolution per bill** (`Company::posReceiptPrefsFor($transaction)`): PRA receipt =
`invoice_mode==='pra' && pra_status !== NULL` (fiscal POS- serials, incl. pending/failed/offline);
everything else = Local (deliberate provisionals local/'local' AND reporting-OFF finals 'pra'+NULL —
exactly the L-series serial split). Templates (receipt_80mm, receipt_58mm, invoice-pdf) read
`$rp = $company->posReceiptPrefsFor($transaction)` and `$showTaxLines = $rp['show_tax']` — do NOT
re-point them at `displayPrefs('pos')` or the raw column. Paper size stays GLOBAL (printer property).

# Receipt tax display rule (OWNER OVERRIDE, Jul 2026)

The show-tax toggle applies to ALL receipts — including PRA fiscal ones (since the per-type split,
PRA receipts read the `pos_receipt_show_tax` column, Local receipts read `pos_local.show_tax`).
Toggle OFF = customer copy hides BOTH the Subtotal and Tax lines and shows only the grand TOTAL
(discount line stays if present). PRA number + QR remain on the receipt.

**Why:** Owner's explicit business decision — Pakistani customers dislike seeing separate tax, shopkeepers
want tax-inclusive-looking receipts. Earlier fiscal-always-show guard was rejected by the owner. Hiding
subtotal along with tax is deliberate: subtotal alone (750 vs TOTAL 870) reveals the tax gap. Full details
remain visible via PRA Sahulat app QR scan; tax is ALWAYS submitted to PRA in full regardless of display.

**How to apply:** In receipt_80mm, receipt_58mm, invoice-pdf: a single `$showTaxLines` boolean
(`optional($transaction->company)->pos_receipt_show_tax ?? true`) guards BOTH subtotal and tax rows.
Do NOT re-add `|| invoice_mode==='pra' || pra_invoice_number` overrides. transaction-show (internal admin
view) keeps full details. restaurant/receipt.blade.php was DELETED (Jul 2026 — it was dead:
RestaurantPosController::receipt renders the shared receipt_80mm/58mm templates); don't recreate it.

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

**Receipt PRINT STYLE = universal default ON, per-company opt-OUT (owner decision 21 Jul 2026
"universal kr do"):** stored in `invoice_display_prefs['pos_style']` (read ONLY via
`Company::posReceiptStyle()`), GLOBAL like paper size (printer/brand property, not per-bill-type).
Defaults when unset: `bold` = TRUE (whole bill bold like the KOT — thin thermal heads print plain
weight too light) and `logo` = 'center' (large centered logo above the name; 'side' = compact row).
An explicitly saved false/'side' is RESPECTED — the earlier thin-"plain drafting"-look customer can
switch it off on /pos/receipt-settings ("Receipt Print Style" card). History: launched opt-in
(default OFF) because of that customer; owner overrode next day to universal-ON. Any new writer of
invoice_display_prefs must merge, never replace, or it wipes pos_style. Blades guard `$logoDataUri`
— no-logo companies get the plain name header even on 'center'.
**Left-edge clipping:** @media print side padding is 2.5mm (80mm) / 2mm (58mm) — 1mm sat inside some
printers' dead zone and ate the first character. Don't shrink it back.
**Printer pickers always visible:** /pos/printer-settings Bill/KOT dropdowns render even with an empty
available_printers list (hidden pickers made the kitchen-printer setting undiscoverable); empty submit
is harmless (controller only accepts agent-reported names).
