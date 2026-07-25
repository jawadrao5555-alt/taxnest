---
name: Site performance conventions
description: Front-end perf rules — single font CDN, self-hosted Chart.js, cached landing stats. Do not regress.
---

# Site performance conventions (Jul 2026)

Owner complained the site felt slow from Pakistan. Server itself was fast (auth pages 24–56ms); the cost was **connection round-trips**: shared cPanel host is far from PK users, so every extra HTTPS domain = DNS + TCP + TLS ≈ 300–500ms on a fresh visit.

## Rules
1. **ONE font CDN only — fonts.bunny.net.** Google Fonts (`fonts.googleapis.com` + `fonts.gstatic.com`) was loading the SAME Inter family in duplicate on pos-app, fbr-pos-app, app layouts (admin-app was Google-only → switched to bunny). Do NOT re-add Google Fonts. Multiple families go on ONE bunny request with `|` (e.g. `figtree:400,500,600,700|inter:300,...`).
   **Why:** 2 extra render-blocking domains per fresh page view.
2. **Chart.js is SELF-HOSTED** at `public/vendor/chart.umd.min.js`, referenced as `/vendor/chart.umd.min.js?v=4.4.0` in all 5 layouts. No jsdelivr. Bump `?v=` if the file is ever upgraded (static-asset-caching rule — .htaccess caches js 30d).
   **Why:** third CDN connection per fresh load; unpinned `npm/chart.js` refs were also a supply-chain risk. Admin dashboard blade must NOT re-include it (layout already loads it).
3. **Landing `/` stats are cached** (`Cache::remember('landing_stats', 600)`) — the 4 COUNTs over big tables cost ~250ms per hit uncached. try/catch OUTSIDE the remember so DB-down zeros are never cached. Live `CACHE_STORE=database`.

## How to apply
Any new layout/page: fonts via bunny only, no new third-party script domains without a reason, heavy marketing-page queries get cached. Deploying a new loose file under `public/` (like /vendor) — remember it must be included in the deploy tar or `window.Chart` goes undefined on every panel.

## Known remaining weight (accepted for now)
- POS sale screen HTML ≈ 660KB (gzip ~100KB) from the 526KB universal.blade.php — parse cost on cheap shop PCs. Restructuring = major job, not attempted.
- TLS+network from PK to the shared host ≈ 200–300ms baseline per fresh connection — hosting location, not code.
