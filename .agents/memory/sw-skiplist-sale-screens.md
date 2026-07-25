---
name: SW skip-list for sale screens
description: Service worker caching rules for authenticated POS/DI screens
---

**Rule:** every NEW authenticated sale/edit screen route must be added to `skipPatterns` in `public/sw.js` AND `CACHE_VERSION` must be bumped in the same change.

**DELIBERATE EXCEPTION (Jul 2026):** the PRA sale screen `/pos/invoice/create` (exact path, navigate, no query string) is served CACHE-FIRST from a dedicated `SALE_CACHE` for instant boot — this is NOT a violation to "fix". Safety rails: purged on ANY logout AND any POST to */login; never caches redirects/non-HTML; page self-validates via baked fingerprint + `/pos/api/boot-check`. Full rules → `pos-sale-screen-offline-boot.md`. Query-string variants and all OTHER sale screens stay in skipPatterns.

**Why:** the shared suite SW (scope '/') runtime-caches all navigations network-first. FBR POS's `/fbr-pos/create` was missed while PRA POS sale screens were listed — authenticated HTML with CSRF token and company data was being cached (found in July 2026 deep audit). Skip-list matching is `pathname.includes(pattern)`.

**Logout purge rule:** the RUNTIME_CACHE purge must run BEFORE the GET-only guard (logouts are POST) and BEFORE skipPatterns ('/logout' is in the skip list and would return early). A non-empty post-logout cache is fine as long as it holds only public pages (redirect target re-caches). Residual accepted gaps: closing browser without logout keeps cache until next logout; STATIC_CACHE keeps images (incl. /storage/ logos) across logout — images only, no HTML.

Also note: all 3 apps deliberately share one `/sw.js` with scope '/' — cache entries are keyed by URL path so apps don't collide; this is suite design, not a bug.
