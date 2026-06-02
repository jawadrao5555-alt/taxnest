---
name: POS sale-screen product loaders
description: Which controller methods load products onto the POS billing/sale screen vs. catalog management
---

The POS sale/billing screen (the one cashiers use to add items to the cart) gets its product list from THREE separate controller methods. Any filter that controls "which products appear when billing" must be applied to ALL of them, or products will leak into one register but not another:

- `PosController::universalCreateInvoice()` — the active universal POS register (the default sale screen).
- `PosController::editTransaction()` — the product picker when editing an existing (non-PRA-submitted) transaction.
- `RestaurantPosController::pos()` — the restaurant-mode POS register.

**Why:** the per-product `show_on_sale` visibility toggle had to be added in all three; the catalog management page (`PosController::products()`) must NOT be filtered — it lists every product so the merchant can toggle visibility.

**How to apply:** when adding any "show/hide on sale screen" style flag for POS products, grep `PosProduct::where('company_id', ...)->where('is_active', true)` and update the three sale-screen loaders only. Leave `products()` and stats/count queries unfiltered. Note `PosController::createInvoice()` has a legacy unreachable block (early `return $this->universalCreateInvoice()`), so its product query doesn't matter.

## Two-layer product visibility on the sale screen
There are TWO independent mechanisms controlling whether products appear on the POS billing screen — do not confuse them:

1. **Per-product backend filter** (`show_on_sale` column on `pos_products`, default true): the three sale-screen loaders only fetch rows where `show_on_sale = true`. Toggled from the products management page.
2. **Per-cashier MASTER toggle** (frontend only, `create-invoice.blade.php`): Alpine `showProducts` boolean persisted in `localStorage('pos_show_products')`. When OFF, `ddGetFiltered()` returns `[]` for product rows so NO saved-product suggestions appear (cashier bills manually via the "Add as new product" path). Default true.

**Why:** user wanted ONE simple on/off on the sale screen for all products, separate from per-product hiding.
**How to apply:** read any `localStorage` value for Alpine state inside `init()` wrapped in try/catch (never in the object-literal default) — a direct read in the literal can throw SecurityError in storage-restricted browsers and break page boot.
