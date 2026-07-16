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

**Category scoping (owner request Jul 2026):** search now RESPECTS `activeCategory` (grid + suggestions) — the old "ignore stale category while searching" rule was retired because a category `<select>` next to the search bar (bound to the same `activeCategory` as the pills) is always visible ≥sm, so the filter is never invisible. Guards that keep it safe:
- Barcode/SKU scans stay GLOBAL: `findExactCodeItem` fast path unfiltered + exact code matches from any category unshifted into suggestions (PRA only; FBR search is name-only).
- Quick-create DUPLICATE GUARD: before `quickCreateProduct()`, an exact-NAME lookup across the whole catalog adds the existing item instead of creating a 'Quick' copy.
- `toggleShowProducts()` OFF resets `activeCategory='all'` (on <sm the dropdown is `hidden sm:block`, so a pill-picked category would become an invisible filter — the one place the old stale-category trap still applied).
- Esc resets category to 'all' (pre-existing).

**Why:** took several attempts originally — the dropdown-only fix didn't satisfy the owner; later the owner explicitly asked for category-scoped browse/search, which inverted the stale-category rule but required the scan/duplicate/mobile guards above.
**How to apply:** any change to grid visibility / search gating / category filtering on either sale screen must keep BOTH surfaces working, keep scans global, and mirror PRA ↔ FBR port.
