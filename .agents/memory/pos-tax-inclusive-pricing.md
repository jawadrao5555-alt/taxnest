---
name: PRA POS Tax-Inclusive Pricing (Menu-Rate-Final)
description: Storage semantics, snapshot rule, and display rules for the per-company tax-inclusive pricing mode on PRA POS
---

# Tax-Inclusive Pricing (Menu-Rate-Final) — PRA POS only

**Mode**: `companies.pos_tax_inclusive` (company toggle, Customize POS → Tax Pricing Mode, admin-only endpoint `pos.settings.tax-pricing-mode` with hasColumn→503 guard). Each bill snapshots it into `pos_transactions.tax_inclusive` — ALL later reads (edit, promote, PRA payload, receipts, reports) branch on the BILL SNAPSHOT, never the current company setting.

**Storage semantics (B-prime, architect-approved)**:
- Line items store MENU (tax-in) prices in unit_price/subtotal; item tax_amount = included portion.
- Header is EX-TAX-CONSISTENT: tax = round(TIA·r/(100+r), 2) where TIA = discounted taxable menu sum; subtotal_col = full menu subtotal − tax; total = round(discounted menu sum) whole rupee. Report identity holds: subtotal − discount − exempt = taxable base.
- Exempt lines untouched (no included-tax back-calc).
- ALL math via `App\Services\PosTaxMath` — never inline the formulas.

**Why:** menu price = customer-facing grand total regardless of payment method (cash 16% / card 8% back-calculated inside), while keeping every existing aggregate/report (which sums header columns) correct without changes.

**How to apply:**
- Any NEW write path creating/updating pos_transactions must branch on the snapshot (see storeInvoice / updateTransaction / retryPra promote / holdOrder / payOrder patterns) and guard with `Schema::hasColumn('pos_transactions','tax_inclusive')` (prod drift → fallback exclusive).
- PRA payload: SaleValue = round(lineIncl·100/(100+r),2), TaxCharged = lineIncl − SaleValue; existing whole-rupee reconciler absorbs penny drift into largest line. Tax ALWAYS submitted in full.
- DISPLAY rule: inclusive bills show menu subtotal (header subtotal + tax_amount) and "(r% incl.)" tax label — receipts 80/58mm, transaction-show, invoice-pdf, edit preview, universal screen all follow this. Show-Tax-OFF path unaffected.
- Item-level tax report SQL uses CASE on pos_transactions.tax_inclusive (`itemBaseSqlExpr()`), requires pos_transactions join with qualified columns.
- Promote/serial/day-close logic NEVER recomputes tax — stored header rides through (payment method can't change on promote).

**Gotchas**: header tax stays 2dp on inclusive bills (deviation from whole-rupee header-tax convention, intentional so base+tax reconstructs exactly; total is still whole rupee). `resources/views/pos/invoice-pdf.blade.php` is UNREFERENCED (legacy; FBR port copied from it) — receipt PDFs use receipt_80mm/58mm via $receiptView.
