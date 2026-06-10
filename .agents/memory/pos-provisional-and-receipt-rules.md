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

# PRA fiscal receipt tax rule

The `companies.pos_receipt_show_tax` toggle hides the tax line ONLY on local / non-fiscal receipts.

**Why:** Pakistan PRA fiscal receipts that carry a PRA invoice number/QR must display the tax amount;
hiding it risks non-compliance and makes receipt arithmetic (Subtotal − Discount = Total) inconsistent.

**How to apply:** Guard the tax line in all receipt templates (receipt_80mm, receipt_58mm, invoice-pdf) with:
`@if((optional($transaction->company)->pos_receipt_show_tax ?? true) || $transaction->invoice_mode === 'pra' || !empty($transaction->pra_invoice_number))`
i.e. fiscal bills always show tax; the toggle only affects local bills. Tax is always SUBMITTED to PRA
regardless of this display toggle.
