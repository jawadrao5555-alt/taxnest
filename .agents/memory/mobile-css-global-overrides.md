---
name: mobile.css global override traps
description: public/css/mobile.css global rules out-specify Tailwind utilities on phones — check it FIRST when a mobile-only layout bug defies the Blade markup.
---

`public/css/mobile.css` (loaded by ALL 6+ layouts, cache-busted `?v=N.N` in 17 view refs — bump on every edit) contains aggressive global mobile rules that silently beat Tailwind classes. Three traps hit in Jul 2026:

1. **Forced table-cell beats `.hidden`**: the universal table rule `main table:not(.table-static):not(.table-cards) > * > tr > th/td { display: table-cell }` (specificity 0,2,4) out-ranks `.hidden` (0,1,0), so `hidden sm:table-cell` columns were force-SHOWN on phones app-wide. Fixed with exception rules right after it (th.hidden/td.hidden → none under 639.98px; `.hidden:not(.sm\:table-cell)` → none for 640–768). Any new responsive column pattern still relies on those exceptions existing.

2. **Touch-target inflation blows up toggle pills**: section 4 (`button { min-height:40px }`) and section 37 (`[x-data*="restaurantPos"] button { min-44px !important }`) turn h-5/w-10-style switch pills into 40–44px blobs. Section 38 pins all four pill variants (h-5 w-10, h-6 w-11, h-5 w-9, h-4 w-7) back to designed geometry with !important. A NEW pill size variant must be added to section 38 or it will blob on phones.

3. **Grid collapse needs `grid-cols-keep`**: section 13 collapses raw grid-cols-2/3/4 to 1fr !important on phones; 13b re-forces 2-col inside `.pos-layout-root` (higher specificity). To get a custom mobile column count: add `grid-cols-keep` class (escapes 13b) AND write the page rule with !important + ≥3-class specificity in a BODY `<style>` block (ties section 13, wins by source order). Example: POS dashboard `.pos-layout-root .grid.tile-grid` 3-col rule.

**How to apply:** any "my Tailwind class works on desktop but not on mobile" symptom → grep mobile.css for the property before touching Blade. min-height/min-width beat height/width regardless of specificity (different properties).
