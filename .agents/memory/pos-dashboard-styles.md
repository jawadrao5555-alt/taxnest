---
name: POS dashboard styles & Saaf skin
description: per-company pos_dashboard_style rules — Saaf style/skin, global sale-screen compaction, style picker location, new-style checklist. Moved from replit.md Jul 2026.
---

# POS dashboard styles & Saaf — full rules

- Per-company `companies.pos_dashboard_style` — 7 styles incl. `saaf` (owner-approved "Saaf — Simple", Jul 2026: teal clean KPIs, Roman Urdu copy, dual retail/restaurant branches; hides Universal CTA + Profit&BI + adds simplified 5-pill top-nav in `pos-app.blade.php` gated on the style; Settings pill hidden for cashiers).
- **Saaf ALSO skins the SALE SCREEN** (Jul 2026): `body[data-saaf]` attr (pos-app layout) + `public/css/pos-saaf.css` CSS-only skin (teal recolor, motion off, big search) + tiny `@if($isSaaf)` blade tweaks in universal.blade.php — secondary toolbar buttons (Rush/Fit/Keys/Quick) AND (customer feedback 22 Jul 2026) the guided-flow stepper strip (`.tn-flow-strip`) hidden behind the "Mazeed" toggle (`[data-saaf-secondary]` pattern; category dropdown stays visible).
- **Customer box stays FIRST in the action bar for ALL styles** (shared partial `pos/partials/sale-customer-box.blade.php` — a brief saaf-only move to the Current Order panel was REVERTED, owner 23 Jul 2026: guided flow starts with customer; do NOT relocate it per-style). Search dropdown compact (category sub-label hidden, tighter rows).
- **GLOBAL sale-screen compaction** (customer feedback, deployed 23 Jul 2026): compact search dropdown + compact cart rows for ALL styles; category-PILLS strip REMOVED globally (`tn-cat-strip` now renders for ALL companies since 25 Jul 2026 — hosts the per-user "Grid Tarteeb" edit chip; the master Products toggle inside it stays `!$inventoryEnabled`-only — do NOT re-add pills); per-USER grid show/hide (25 Jul 2026): every role hides/shows items on their OWN grid, user pref overrides admin show_on_sale BOTH directions → `pos-user-grid-prefs.md`; category dropdown is the only category filter and is visible on MOBILE too (no `hidden sm:block`).
- Restaurant receipts print a DINE-IN/TAKE AWAY/DELIVERY badge from the order_type snapshot; ALL features + F-keys keep working; Full-style companies' HTML stays byte-identical (verified by diff).
- **Sale-screen SEARCH is GLOBAL for ALL companies** (22 Jul 2026): category dropdown filters the GRID only — typed search covers deals+products+services incl. show_on_sale-hidden; do NOT re-scope search to category.
- **Discount rule (owner, 22 Jul 2026)**: limit applies to BOTH types — amount discounts capped at limit% of subtotal (client + storeInvoice + updateTransaction + restaurant holdOrder).
- **Style choice lives ONLY in `/pos/customize#style` "POS Ka Style" section** (owner, 22 Jul 2026): 2 big cards Full(default)/Saaf + 5 legacy fancy styles under collapsed "Mazeed styles"; the old dashboard `_style-picker` DROPDOWN is REMOVED (partial keeps PRA toggle + admin-only link to customize#style — do NOT re-add the dropdown).
- **New style checklist**: blade in `pos/dashboard-styles/` + 3 `$allowed` lists (PosController ×2, RestaurantPosController) + customize.blade.php `$allowedStyles`/`$fancyStyles`.
