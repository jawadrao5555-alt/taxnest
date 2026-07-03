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

**Tax-hidden mode must self-reconcile (owner: "larai ho sakti hai"):** when the toggle is OFF, item
Rate/Amt print TAX-INCLUSIVE via `PosTransaction::inclusiveLineAmounts()` (largest-remainder allocation),
and TOTAL + discount rows print whole-rupee rounded — so itemsSum − printedDiscount == printedTotal
ALWAYS holds. Never print raw pre-tax item lines next to an inclusive total. NT/EXEMPT tags and the
invoice-pdf Tax% column are also hidden in this mode.

**Toggle location (owner-mandated):** the checkbox lives on the Receipt Settings page
(`/pos/receipt-settings`, field `rp_show_tax`, saved in `receiptSettings()`), NOT on the Features page.
`updateFeatureSettings()` must NEVER write `pos_receipt_show_tax` — a checkbox absent from that form
would silently force it off on every Features save.
