---
name: SW skip-list for sale screens
description: Service worker caching rules for authenticated POS/DI screens
---

**Rule:** every NEW authenticated sale/edit screen route must be added to `skipPatterns` in `public/sw.js` AND `CACHE_VERSION` must be bumped in the same change.

**Why:** the shared suite SW (scope '/') runtime-caches all navigations network-first. FBR POS's `/fbr-pos/create` was missed while PRA POS sale screens were listed — authenticated HTML with CSRF token and company data was being cached (found in July 2026 deep audit). Skip-list matching is `pathname.includes(pattern)`.

**Known accepted gap (flagged to owner, not fixed):** RUNTIME_CACHE is never purged on logout — on a shared terminal the next user could view the previous session's cached pages while OFFLINE only (network-first hides it online). Fix if ever asked: postMessage from logout to SW to clear RUNTIME_CACHE.

Also note: all 3 apps deliberately share one `/sw.js` with scope '/' — cache entries are keyed by URL path so apps don't collide; this is suite design, not a bug.
