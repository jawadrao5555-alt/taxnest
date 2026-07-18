---
name: PRA POS Tax-Inclusive Pricing (Menu-Rate-Final)
description: Storage semantics, snapshot rule, and display rules for the 3-mode tax pricing system (exclusive / inclusive / inclusive_card_save) on PRA POS
---

# Tax-Inclusive Pricing (Menu-Rate-Final) — PRA POS only

**THREE modes (Jul 2026)**: `companies.pos_tax_pricing_mode` = `exclusive` | `inclusive` | `inclusive_card_save` (legacy bool `pos_tax_inclusive` kept in sync: true for both inclusive modes). Read via `Company::posTaxPricingMode()` ONLY — it validates + falls back to the legacy bool when the column is missing (prod drift). Customize POS → Tax Pricing Mode = 3 cards ("Standard — Tax Upar Se" / "Menu Rate Final — Sab Same" / "Menu Rate Final — Card Bachat"); admin-only endpoint `pos.settings.tax-pricing-mode` accepts `{"mode": ...}` (new) AND `{"inclusive": bool}` (back-compat), hasColumn→503 guard. Each bill snapshots into `pos_transactions.tax_inclusive` + `tax_menu_rate` — ALL later reads (edit, promote, PRA payload, receipts, reports) branch on the BILL SNAPSHOT, never the current company setting.

**Card-save mode (`inclusive_card_save`)**: menu price is inclusive at the CASH rate ("menu rate"); every bill stores `tax_menu_rate` = cash rate at sale time. Base = menu·100/(100+menuRate) for ALL methods; the bill's OWN method rate applies on top → cash total = menu price, card/digital total = cheaper (e.g. 590 menu @16/8% → cash 590, card 549). Detection on displays: `tax_inclusive && tax_menu_rate>0 && |menu−rate|≥0.005` → show "Menu Total" (items menu sum) + "Card Discount −X" rows; Card Discount row stays visible even when Show-Tax is OFF. Plain inclusive bills have tax_menu_rate = tax_rate (or NULL legacy) → classic display.

**Storage semantics (B-prime, architect-approved)**:
- Line items store MENU (tax-in) prices in unit_price/subtotal; item tax_amount = included portion.
- Header is EX-TAX-CONSISTENT: tax = round(TIA·r/(100+r), 2) where TIA = discounted taxable menu sum; subtotal_col = full menu subtotal − tax; total = round(discounted menu sum) whole rupee. Report identity holds: subtotal − discount − exempt = taxable base.
- Exempt lines untouched (no included-tax back-calc).
- ALL math via `App\Services\PosTaxMath` — never inline the formulas. Card-save uses the same entry points with the optional `?float $menuRate` param (null/same-rate = classic inclusive).

**Why:** menu price = customer-facing grand total regardless of payment method (cash 16% / card 8% back-calculated inside), while keeping every existing aggregate/report (which sums header columns) correct without changes.

**How to apply:**
- Any NEW write path creating/updating pos_transactions must branch on the snapshot (see storeInvoice / updateTransaction / retryPra promote / holdOrder / payOrder patterns) and guard with `Schema::hasColumn('pos_transactions','tax_inclusive')` (prod drift → fallback exclusive).
- PRA payload: SaleValue = round(lineIncl·100/(100+r),2), TaxCharged = lineIncl − SaleValue; existing whole-rupee reconciler absorbs penny drift into largest line. Tax ALWAYS submitted in full.
- DISPLAY rule: inclusive bills show menu subtotal (header subtotal + tax_amount) and "(r% incl.)" tax label — receipts 80/58mm, transaction-show, invoice-pdf, edit preview, universal screen all follow this. Show-Tax-OFF path unaffected.
- Item-level tax report SQL uses CASE on pos_transactions.tax_inclusive (`itemBaseSqlExpr()`), requires pos_transactions join with qualified columns.
- Promote/serial/day-close logic NEVER recomputes tax — stored header rides through (payment method can't change on promote).

**Gotchas**: header tax stays 2dp on inclusive bills (deviation from whole-rupee header-tax convention, intentional so base+tax reconstructs exactly; total is still whole rupee). `resources/views/pos/invoice-pdf.blade.php` is UNREFERENCED (legacy; FBR port copied from it) — receipt PDFs use receipt_80mm/58mm via $receiptView. Card-save frontend (universal `taxMenuRate` prop + `cardSaveTotalForRate`, edit-transaction snapshot prop) mirrors the backend but paisa-level diffs at .5 rounding boundaries are accepted — backend is authoritative. Mixed payments land in the card bucket → full card saving. Promote modal (F10) intentionally untouched — server re-taxes on promote.
