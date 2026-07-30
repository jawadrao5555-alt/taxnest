---
name: POS wide-cart (Variant A) layout
description: Products-hidden mode desktop layout flip — cart wide LEFT, payment column RIGHT — via .tn-widecart + display:contents wrappers
---

- When `showProducts=false` (products-hidden mode), `.tn-body-row` gets `.tn-widecart` (bound `:class`) and DESKTOP (≥768px) layout flips: top bars full width, cart list wide LEFT pane, 400px payment/totals column RIGHT; `[x-ref="gridContainer"]` display:none but the cat-strip stays (it carries the "Products OFF" toggle — the only way back).
- Mechanism: two wrapper divs inside `.tn-cart-col` — `.tn-cart-main` (header+banners+cart list) and `.tn-cart-side` (footer+TABLE strip) — are `display:contents` by DEFAULT, so normal mode & mobile are bit-for-bit unchanged; only widecart CSS turns them into flex panes. `@supports not (display:contents)` fallback keeps legacy WebViews on the normal stacking.
- **Why:** owner-approved "Variant A" mockup (28 Jul 2026) — grid-off shops wasted the whole left half; cart rows now fit without scrolling.
- **How to apply:** any new cart-column child must go INSIDE one of the two wrappers (main = list side, side = payment side) or it breaks the widecart split. Only custom CSS classes used — no Tailwind rebuild needed. Fixed-position modals inside the wrappers are fine.
- Widecart totals = "C-style" WHITE card (owner pick, 28 Jul 2026): same `.tn-total-band` markup, restyled ONLY under `.tn-widecart` desktop CSS; normal + mobile keep the purple band. **Specificity trap:** the pos-app theme engine's `body:not([data-theme=purple]) .bg-purple-900 {…!important}` (0,2,1) beats a plain 2-class override — must use `body .tn-widecart .tn-total-band.bg-purple-900` (or higher). Accent color via `hsl(var(--accent-h)…)` theme vars, never hardcoded purple.
- Verifying sale-screen CSS changes in a browser: the SW serves the page cache-first — reloads (even hard) can keep showing the OLD page; logout/login purges SALE_CACHE and is the reliable refresh (curl the server directly to prove what's actually served).
- Dev QA trap: posadmin@taxnest.com (company 11) has inventory ON → the master Products toggle doesn't render; use company 13 malikchickenbroast@taxnest.com (inventory OFF, NO "pos" prefix in email) for grid-off testing.

- PORTED to fbr-pos/universal.blade.php (30 Jul 2026): same class hooks; FBR's footer div itself carries .tn-cart-side (no wrapper), only .tn-cart-main is a display:contents wrapper; FBR kept its own simpler widecart CSS (no C-style totals card).
- Guided-flow coach strip (1·Customer…5·Bill bar) REMOVED from BOTH universal screens (owner: screen space); the $hasTypeStep @php block MUST stay — Order-Type overlay uses it. Enter-chain behavior unchanged.
