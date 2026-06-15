---
name: PRA POS sale screen — live view, product loaders & visibility
description: Which Blade renders the POS sale screen, the 3 product loaders that must stay in sync, and the two-layer product visibility (incl. inventory-mode gating)
---

## Live sale screen
- The ONLY served PRA POS (`pos` guard) sale screen is `resources/views/pos/universal.blade.php`. Both `pos.invoice.create` and `pos.v2.invoice.create` render it.
- `resources/views/pos/create-invoice.blade.php` is DEAD: `PosController::createInvoice()` unconditionally `return $this->universalCreateInvoice($request)`, so that view's `view()` is never reached. Edit `universal.blade.php`, never the dead file.
  **Why:** a change once made to the dead file had zero effect and wasted a turn.

## Product loaders (keep in sync)
The cashier sale/billing product list is loaded by THREE controller methods. Any "which products appear when billing" filter must be applied to ALL three, or products leak into one register but not another:
- `PosController::universalCreateInvoice()` — the active universal POS register (default sale screen).
- `PosController::editTransaction()` — product picker when editing a non-PRA-submitted transaction.
- `RestaurantPosController::pos()` — restaurant-mode register.
The catalog management page (`PosController::products()`) and stats/count queries must NOT be filtered — they list every product.
**How to apply:** grep `PosProduct::where('company_id', ...)->where('is_active', true)` and update the three loaders only.

## Two-layer product visibility (don't confuse them)
1. **Per-product "Hidden from sale screen"** — `show_on_sale` column on `pos_products` (default true), toggled from the products page. As of Jun 2026 this means HIDDEN-FROM-GRID, **not** excluded, on the universal register: `universalCreateInvoice()` loads ALL active products (incl. `show_on_sale=false`) and `productsJson` carries the flag. `universal.blade.php` `filterProducts()` drops `show_on_sale===false` from the browsable grid **only when there is no search**; a search ALWAYS includes hidden items (and `ignoreCategory = hasSearch`, so search spans the whole catalog). `addRandomProduct()` excludes hidden; the "All (N)" count counts visible-only. **Why:** owner wanted hidden = decluttered grid yet still findable by name to add to cart. **Divergence:** `editTransaction()` and `RestaurantPosController::pos()` STILL hard-filter `show_on_sale = true` (hidden not searchable there) — intentional, since relaxing visibility on one register can't make anything vanish elsewhere; only mirror the universal behavior there if explicitly asked.
2. **Per-cashier MASTER toggle** — frontend only, in `universal.blade.php`: Alpine `showProducts` boolean persisted in `localStorage('pos_show_products')`. When OFF, `filterProducts()` returns an empty *grid* — but the **search box still works**: `onSearchInput()` suggestions are independent of the grid toggle, so typing surfaces matching catalog items in the dropdown (add to cart via click/Enter); only a no-match query falls through to the inline "Create" prompt. **Inventory-OFF ONLY** — gated server-side behind `@if(!($inventoryEnabled ?? false))`, and `init()` honors the stored '0' only when `!isInventoryEnabled()`.
   **Why search-when-grid-off:** owner (Jun 2026) wanted a clean screen (no grid) yet still be able to search a saved product by name and add it — pure manual-only was too restrictive. Keep `onSearchInput` suggestions independent of the grid toggle; the grid stays gated by `filterProducts()`.
  **Why inventory-OFF only:** inventory mode has no on-the-fly create (`quickCreateProduct()` no-ops), so hiding the catalog there BRICKS billing — caught by architect review + e2e.
  **localStorage quirk:** read any localStorage value for Alpine state inside `init()` wrapped in try/catch (never in the object-literal default) — a direct read in the literal can throw SecurityError in storage-restricted browsers and break page boot.
