---
name: POS grid "Products" toggle vs product search
description: How the inventory-OFF showProducts toggle interacts with the two search surfaces on universal.blade.php; how the category dropdown changed the stale-category rule.
---

# Two independent search surfaces on the POS sale screen (universal.blade.php)
- **Dropdown** (`searchSuggestions` / `showSearchDropdown`, built in `onSearchInput()`): NOT gated by `showProducts`; shows in both modes.
- **Grid** (`filteredItems` → `displayItems`, built in `filterProducts()`): gated by `showProducts`.

# The trap
`showProducts` (the inventory-OFF master "Products" toggle) hides the catalog grid. `filterProducts()` early-returns with `filteredItems=[]` whenever `showProducts` is false — so even with an active search the GRID stays empty. A fix that only un-gates the dropdown path looks correct but the owner still sees an empty grid and reports "search shows nothing."

# The rule (updated Jul 2026 — category dropdown)
`filterProducts()` must populate the grid when `showProducts=false` AND a search query is present; clearing the search must re-enter the early return so the grid hides again.

**Search is GLOBAL again (customer complaint, 22 Jul 2026 — PRA only):** the earlier category-scoped search caused real shops to think items were missing. Final rule: `activeCategory` (pills + dropdown) filters the GRID only; the moment a search query exists, BOTH surfaces search the WHOLE catalog (deals + products + services, `show_on_sale` hidden items included). In `filterProducts()` the category filter AND the show_on_sale filter live inside the `if (!hasSearch)` branch; `onSearchInput()` pool is always `[...allDeals, ...allProducts, ...allServices]`. FBR port NOT changed (owner focus = PRA only) — its search stays name-only/as-is; mirror there only when the owner asks.
- Barcode/SKU scans were already global; the scanner unshift-guard became redundant and was removed with the pool change.
- Quick-create DUPLICATE GUARD stays: exact-NAME lookup across the whole catalog before `quickCreateProduct()`.
- `toggleShowProducts()` OFF still resets `activeCategory='all'` (grid-off UX, not a search correctness need anymore).

**Real-world dead-end (Frost & Brew, Jul 2026):** a cashier accidentally clicking "Products OFF" persists per-browser (`pos_show_products` localStorage) — every account on that PC then sees an empty grid across reloads and the shop reports "products gone / no slips since last night". The empty-state "Show All Products" button previously only reset category/search (did nothing with grid OFF). Rule: the empty-state rescue button must ALSO flip `showProducts` back ON + persist (`restoreProductGrid()` in both PRA and FBR universal screens), and the empty-state copy must say the grid is OFF instead of "No products match". Diagnose these reports from the video/screenshot toggle label first — server data was never the problem.

**Why:** this rule has now FLIPPED TWICE (global → category-scoped on owner request → global on customer complaints). Search scope is a customer-facing sore spot — never narrow search silently; if scoping is ever requested again, keep the grid scoped but leave typed search global.

**STRICT PREFIX name matching (owner, 24 Jul 2026 — PRA only):** BOTH surfaces (dropdown matcher in `onSearchInput()` + grid filter in `filterProducts()`) match names via `startsWith(q)` ONLY — mid-name/mid-word hits are excluded by owner decision ("zi" → only names starting with Zi). Barcode/SKU substring matching is gated on `codeSearch = /[^a-z\s]/.test(q)` (query contains a digit/symbol): letters-only typing = NAME search, else SKUs like `CHI-001` leak "Chai" into a "chi" search (caught by e2e). Scanner flow safe: the Enter fast path `findExactCodeItem()` is exact-match and NOT gated; scanned digits always set codeSearch=true. Do NOT strict-prefix the Quick-Type bulk matcher (deliberate exact>startsWith>includes>token ladder), customer search, or transaction search. Accepted cost: inventory-OFF cashier typing "roll"+Enter gets quick-CREATE instead of finding "Chicken Roll" — KB teaches "naam shuru se likhein".
**How to apply:** any change to grid visibility / search gating / category filtering must keep BOTH surfaces working and keep typed search + scans global; Madadgar KB "Sale Screen" + troubleshooting lines describe search behavior — update them in the same deploy.
