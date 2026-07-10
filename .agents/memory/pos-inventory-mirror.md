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

## Restock-on-void (bill delete/edit stock restore) — OPTIONAL per-company toggle
- Gated by `companies.pos_restock_on_void` (default TRUE) shown in Customize POS.
  Restore runs only when `inventory_enabled && pos_restock_on_void`.
- `PosInventoryController::restoreStockForInvoice()` mirrors `deductStockForInvoice`
  (company-scoped tamper-safe PosProduct lookup, lockForUpdate, keeps
  pos_products.stock_quantity in lockstep, writes InventoryMovement TYPE_RETURN_IN).
- **Every delete surface must restore, not just the transaction page.** Provisional
  bills deduct stock at sale time too, and cashiers delete them from the **F10 "Local"
  modal** (`apiDeleteProvisional`), NOT `pos/transaction/{id}` DELETE. Wiring only
  `deleteTransaction` leaves the primary provisional-delete path drifting. Both wired.
- Edit (`updateTransaction`) reconciles: snapshot OLD items BEFORE `items()->delete()`,
  then restore-old + deduct-new inside the same DB::transaction (order-independent —
  deduct has no zero-clamp). Promote-provisional does NOT touch stock (bill still exists).
- FBR POS does NOT deduct stock via deductStockForInvoice, so its delete paths are
  out of scope — do not add restore there unless FBR starts deducting.

**Why:** a deleted/voided sale should return goods to the shelf, but some owners treat
a deleted bill as consumed/damaged — hence the toggle, default ON (correct accounting).

## Known remaining drift gaps (owner decision pending, do not "fix" silently)
- CSV product import sets no stock (by design, no backfill there).
