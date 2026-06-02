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
