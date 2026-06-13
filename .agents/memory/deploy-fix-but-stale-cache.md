---
name: Fix is in the live commit but owner still sees old behavior
description: When the deployed commit already contains a confirmed fix yet the bug persists on LIVE, suspect stale caches — client PWA service-worker cache and/or server Blade view cache — before touching code.
---

# How to confirm the fix is actually deployed
- Find the live commit (see cpanel-deployment.md) and inspect the file AT that commit, plus ancestry:
  - `git show <livecommit>:path/to/file | rg "<the fixed line>"`
  - `git merge-base --is-ancestor <fixcommit> <livecommit> && echo IN || echo NOT-IN`
- If the fixed line is present in the live commit, the code is fine — the bug the owner sees is a cache artifact, NOT a missing deploy.

# Likely causes, check in this order
1. **Client-side PWA / service-worker cache.** TaxNest POS (PRA + FBR) and DI are PWAs with offline pre-caching. An installed device keeps serving the OLD cached page. Critical nuance: the sale-screen search/cart logic is **inline Alpine JS inside `universal.blade.php`** — so stale cached HTML == stale logic (there is no separate JS bundle to bust). Fix on that device: hard-refresh (Ctrl+Shift+R), use the in-app refresh/update control, or clear site data / uninstall+reinstall the PWA.
2. **Server-side compiled Blade view cache** (`storage/framework/views`). If a deploy ran `view:cache` (or the pull preserved old mtimes), the old compiled view is served. Fix: `php artisan view:clear` (cpanel runbook step 5) using `/usr/local/bin/ea-php84`.
3. **Live is not actually at the recorded commit.** The runbook records the last KNOWN deploy, not live's current state. Verify on the server: `git log -1 --oneline`; if behind, `git pull origin main`.

**Why:** because the sale-screen logic is inline JS embedded in the Blade template, BOTH a server Blade view cache and a client PWA service-worker cache can independently keep serving the old behavior long after the source is fixed and the commit is on live. Always prove the fix is in the live commit first, then chase caches — do not re-fix already-fixed code.
