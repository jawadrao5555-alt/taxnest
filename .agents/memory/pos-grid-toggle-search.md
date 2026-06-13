---
name: POS grid "Products" toggle vs product search
description: How the inventory-OFF showProducts toggle interacts with the two search surfaces on universal.blade.php; why hiding the grid can hide search results.
---

# Two independent search surfaces on the POS sale screen (universal.blade.php)
- **Dropdown** (`searchSuggestions` / `showSearchDropdown`, built in `onSearchInput()`): NOT gated by `showProducts`; shows in both modes.
- **Grid** (`filteredItems` → `displayItems`, built in `filterProducts()`): gated by `showProducts`.

# The trap
`showProducts` (the inventory-OFF master "Products" toggle) hides the catalog grid. `filterProducts()` early-returns with `filteredItems=[]` whenever `showProducts` is false — so even with an active search the GRID stays empty. A fix that only un-gates the dropdown path (commit "Enable product search when the product grid is hidden") looks correct but the owner still sees an empty grid and reports "search shows nothing."

# The rule
To honour "grid hidden by default, but searching still surfaces saved products," `filterProducts()` must populate the grid when `showProducts=false` AND a search query is present — and it must IGNORE any stale `activeCategory`, because the category pills are hidden in that mode and a leftover category (chosen earlier while the grid was visible) would otherwise filter out matches in other categories. Clearing the search must re-enter the early return so the grid hides again.

**Why:** took several attempts — the dropdown-only fix was already in the live commit but didn't satisfy the owner; the real gap was the grid early-return plus the stale-category edge.
**How to apply:** any change to grid visibility / search gating on the sale screen must keep BOTH surfaces working AND handle the hidden-category case.
