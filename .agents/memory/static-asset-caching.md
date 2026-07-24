---
name: Static asset caching on live
description: Live .htaccess now caches static assets 30 days — every edit to a loose public asset MUST bump its ?v= or shops hold the stale file for a month.
---

# Static asset caching (live, Jul 2026)

**Rule:** `public/.htaccess` serves css/js/fonts with `Cache-Control: public, max-age=2592000` (30 days) and images 7 days. Any edit to a loose (non-Vite) public asset — `css/mobile.css`, `css/pos-saaf.css`, `js/wheel-scroll.js`, or any new one — MUST bump its `?v=` query in every blade reference, or customer shops keep the stale file up to 30 days.

**Why:** Owner's customers on slow networks complained POS was slow; the old blanket `no-cache, no-store` header forced re-download of every asset on every page load. Long cache fixed it, but it makes version discipline mandatory.

**How to apply:**
- Current versions: `mobile.css?v=2.6` (6 layouts), `wheel-scroll.js?v=1` (5 layouts), `pos-saaf.css?v=3`. Keep the version INSIDE `asset('...')` — exactly one `?v=` per reference (a doubled `?v=X?v=X` once slipped in via sed).
- `sw.js` is explicitly exempted (own FilesMatch block AFTER the general js block — later `Header set` wins) and must STAY no-cache or the PWA 60s update check breaks.
- HTML/PHP responses keep no-cache (FilesMatch only matches static extensions; rewritten routes hit index.php).
- Vite `/build` assets are content-hashed — no action needed.
- `.htaccess` is invisible in dev (`artisan serve` ignores it) — verify header changes on LIVE with `curl -sI`.
- Chart.js CDN script in pos-app layout is `defer` — safe because both `new Chart(` call sites run at DOMContentLoaded/Alpine-init; keep new Chart usages inside such handlers.
