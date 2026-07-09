---
name: POS inventory mirror & shared-table trap
description: How POS inventory stock sync works, the DI-products FK trap, and remaining drift gaps
---

## The FK trap (root cause of "inventory never updates")
`inventory_stocks` + `inventory_adjustments` originally had FKs on `product_id`
referencing the **DI `products` table**, but the POS module writes
`pos_products` ids into the same column. Every POS stock insert failed with
MySQL 1452 and was **silently swallowed** by try/catch in the deduction path —
so the module looked "on" but never tracked anything. Fix: FKs dropped by
migration (constraint-name based — on cPanel PROD verify the constraint name
exists before trusting the drop; if named differently it silently skips).

**Why:** DI and POS share the inventory tables, isolated only by `company_id`.
`product_id` is DI `products.id` for DI companies and `pos_products.id` for POS
companies. Never re-add a FK on that column to either table.

## How to apply
- Models have BOTH relations: `product()` (DI) and `posProduct()` (POS,
  `belongsTo(PosProduct,'product_id')`). POS controllers/views must use
  `posProduct`. **PosProduct has NO global CompanyScope** — every lookup must be
  explicitly `where('company_id', $companyId)` (findOrFail for user input,
  scoped `find` inside deduction fallback) or you get cross-tenant leakage.
- Mirror convention: `pos_products.stock_quantity` (products page + sale-screen
  loaders) mirrors `inventory_stocks.quantity` (authoritative, float). ALL write
  paths keep both in step: sale deduction (atomic decrement, whereNotNull
  guard), product create (seed row + opening movement inside the create
  transaction), product edit (changed value applied as audited 'set'
  adjustment), module adjust (writes back rounded int), delete (removes stock
  row, keeps movements). NULL stock_quantity = untracked product — never force 0.
- Sale deduction reads items by key **`item_id`** (not `product_id`) with
  `type=product`; a curl test with `product_id` silently deducts nothing.
- Negative inventory_stocks quantities are allowed by design (oversell warning,
  no clamp).

## Known remaining drift gaps (owner decision pending, do not "fix" silently)
- Bill delete / edit-transaction do NOT restore deducted stock.
- CSV product import sets no stock (by design, no backfill there).
