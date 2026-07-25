---
name: Panel nav layouts — which file actually renders
description: Which layout actually renders each panel's navigation; pos-navigation.blade.php sidebar is dead; admin sidebar is INLINE in admin-app.blade.php, not layouts/navigation.blade.php
---

The live PRA POS navigation is the unified TOP NAV inside `resources/views/layouts/pos-app.blade.php` (dropdown menu items + command-palette JSON items array). `resources/views/layouts/pos-navigation.blade.php` (sidebar) is included by NOTHING — grep for "pos-navigation" across resources/views returns zero includes.

**Why:** An entire nav-visibility feature was first implemented in pos-navigation.blade.php and had zero effect; the real gate was `$inventoryEnabledLayout && !$isCashierLayout` in pos-app.blade.php.

**How to apply:** Before editing any POS nav/menu visibility, fetch a rendered page with curl and grep the HTML for the menu label — edit the file that actually produced it. Same lesson-class as the dead sale screens (see pos-sale-screen-product-loaders.md).

## SaaS admin sidebar = INLINE in layouts/admin-app.blade.php
The real admin-panel sidebar is hardcoded inside `resources/views/layouts/admin-app.blade.php` (route()-named links, `$current` route-name checks, section headers like Management/POS Engagement/Monitoring). `layouts/navigation.blade.php` ALSO contains a `role === 'super_admin'` section full of /admin/* links — that is the DI (web-guard) sidebar's legacy admin section, NOT what the admin guard renders; links added only there never appear in the admin panel.

**Why:** The Feature Suggestions + POS What's New admin links were first added to navigation.blade.php and the owner reported "admin panel mein show hi nahi ho raha" — the admin layout never includes that file.

**How to apply:** Any new admin-panel sidebar link goes in admin-app.blade.php's `<nav>`; verify by logging in as admin on dev and grepping the rendered /admin/dashboard HTML for the label.

## Sale-screen nav tools strip = overflow-x-auto, never justify-center
`#tn-nav-sale-tools` (pos-app.blade.php) receives the sale screen's teleported pills, all `flex-shrink-0`. It must stay `overflow-x-auto` (scrollbar hidden via inline CSS + `align-self: stretch` so -top-1 badges don't clip) with `mx-auto` on the teleported child (safe-center) — NOT `justify-center`.

**Why:** With justify-center + non-shrinking pills, on 1280px shop screens the strip spilled OVER the right-side user group — nav text visually merging on top of each other (live customer report, Jul 2026).

**How to apply:** Any new pill added to the teleported strip keeps `flex-shrink-0`; dropdowns inside the strip must NOT rely on a `relative` wrapper (overflow clips absolute panels) — use `position: fixed` anchored to the trigger's getBoundingClientRect() on open, like the Switches panel. Left brand group + right user group keep `flex-shrink-0`.
