---
name: POS live nav = pos-app top-nav
description: Which layout actually renders POS navigation; pos-navigation.blade.php sidebar is dead
---

The live PRA POS navigation is the unified TOP NAV inside `resources/views/layouts/pos-app.blade.php` (dropdown menu items + command-palette JSON items array). `resources/views/layouts/pos-navigation.blade.php` (sidebar) is included by NOTHING — grep for "pos-navigation" across resources/views returns zero includes.

**Why:** An entire nav-visibility feature was first implemented in pos-navigation.blade.php and had zero effect; the real gate was `$inventoryEnabledLayout && !$isCashierLayout` in pos-app.blade.php.

**How to apply:** Before editing any POS nav/menu visibility, fetch a rendered page with curl and grep the HTML for the menu label — edit the file that actually produced it. Same lesson-class as the dead sale screens (see pos-sale-screen-product-loaders.md).
