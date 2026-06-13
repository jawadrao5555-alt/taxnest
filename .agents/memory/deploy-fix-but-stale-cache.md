---
name: Fix exists in code but not live — it's almost always a DEPLOY GAP, not cache
description: When a confirmed fix doesn't show on LIVE, the real cause on this repo has been a deployment gap (old code on the cPanel server and/or unpushed local commits), NOT a stale cache. Verify live's ACTUAL commit before chasing caches.
---

# Pipeline (three hops — the fix can be stuck at any hop)
Replit working tree  --(push)-->  GitHub origin/main  --(git pull on cPanel)-->  LIVE (/home/taxnestc/public_html)

- **Main agent CANNOT do git writes** (push, rm of .git/*.lock, etc.) — the sandbox blocks all of them. A background Project Task does NOT help: task work merges into the Replit main branch, it does NOT push to the external GitHub origin. **The only way local commits reach GitHub is the OWNER pushing via Replit's version-control UI.** That is how older commits (e.g. the GitHub HEAD) got there.
- So a fix can be: (a) only in Replit, never pushed to GitHub; or (b) on GitHub but the cPanel server never ran `git pull`. Both look identical to the owner ("still broken on live").

# Diagnose in this order (get ground truth, stop guessing)
1. **Is the fix on GitHub?** `git merge-base --is-ancestor <fixcommit> origin/main` (fetch first). If not, it must be pushed from Replit first.
2. **What commit is LIVE actually on?** The decisive fact. Ask the owner to run on cPanel: `cd /home/taxnestc/public_html && git log -1 --oneline`. If it predates the fix commit, live is just running old code → `git pull origin main` + clear caches fixes it.
3. Only AFTER confirming live runs code that contains the fix should you consider caches.

# Why the service worker is a RED HERRING for sale-screen bugs
- `public/sw.js` `skipPatterns` includes `/pos/invoice/create` (and `/login`, `/api/`, `/admin/`...). The SW returns early for these — **the POS sale screen is never cached; it's always network-first/fresh.** So search/cart/keyboard bugs on the sale screen are NEVER a client SW-cache problem.
- `CACHE_VERSION` in sw.js is NOT a reliable deploy marker: `taxnest-v31` was set 2026-05-08 and was NOT bumped when later fixes landed. "Live serves vNN" only proves live is >= the commit that set vNN, not that any later fix is present.

# The fix (covers all cases)
1. Owner pushes latest Replit commits to GitHub via the Replit version-control UI (delivers anything not yet on origin, e.g. keyboard fixes).
2. On cPanel: `git pull origin main` → `ea-php84 artisan migrate --force` → `view:clear` + `cache:clear` + `config:clear` + `route:clear` (paths/PHP in cpanel-deployment.md).
3. Cashier just reloads the sale screen (not SW-cached, so the fix shows immediately). A one-time hard refresh covers any browser HTTP cache.

**Why:** repeatedly the owner reported "still broken on live" for fixes that were already correct in code — every time the true cause was that the code had not actually been deployed to the live server (and/or not pushed to GitHub), not a caching artifact. Prove the fix is in the live commit FIRST; never re-fix already-fixed code, and don't prescribe cache-clearing as the primary remedy.
