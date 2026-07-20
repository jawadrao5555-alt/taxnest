---
name: Fix exists in code but not live — it's almost always a DEPLOY GAP, not cache
description: When a confirmed fix doesn't show on LIVE, the real cause on this repo has been a deployment gap (old code on the cPanel server and/or unpushed local commits), NOT a stale cache. Verify live's ACTUAL commit before chasing caches.
---

# Pipeline (three hops — the fix can be stuck at any hop)
Replit working tree  --(push)-->  GitHub origin/main  --(git pull on cPanel)-->  LIVE (/home/taxnestc/public_html)

- **Main agent CAN push to GitHub directly.** The `origin` remote carries an embedded GitHub token (`git remote -v` → `x-access-token:…@github.com/…`), so `git push origin HEAD:main` ships a fast-forward — verify first with `git push --dry-run origin HEAD:main`. Caveats: (a) the agent canNOT `git commit` and `rm` of `.git/*.lock` is blocked, but a plain push is allowed; (b) since commits come from the platform's end-of-turn auto-commit, a brand-new edit must auto-commit this turn and be pushed on a LATER turn (two-turn ship). The platform auto-pushes only to the `gitsafe-backup` remote, NEVER to `origin` — that is why local commits silently never reached GitHub until pushed.
- **The local `origin/main` ref can be STALE.** A leftover `.git/refs/remotes/origin/main.lock` makes `git fetch` fail ("unable to update local ref"), so the LOCAL `origin/main` may show an old commit. A real push still updates GitHub — you'll see `OLD..NEW  HEAD -> main` and exit 0 even though it warns it couldn't update the local ref. Trust the push / `--dry-run` output, NOT the local ref.
- So a fix can be: (a) only in Replit, never pushed to GitHub; or (b) on GitHub but the cPanel server never ran `git pull`. Both look identical to the owner ("still broken on live").

# Diagnose in this order (get ground truth, stop guessing)
1. **Is the fix on GitHub?** `git merge-base --is-ancestor <fixcommit> origin/main` (fetch first). If not, it must be pushed from Replit first.
2. **What commit is LIVE actually on?** The decisive fact. Ask the owner to run on cPanel: `cd /home/taxnestc/public_html && git log -1 --oneline`. If it predates the fix commit, live is just running old code → `git pull origin main` + clear caches fixes it.
3. Only AFTER confirming live runs code that contains the fix should you consider caches.

# CONFIRMED real-cache case: web OPcache holding stale compiled blade
Once the deploy gap is CLOSED — live `git log -1` is on the correct commit, the served docroot IS the dir you pulled (verified `public_html/public`), AND `optimize:clear` ran — but the page STILL serves old code, the culprit is the **PHP-FPM (web SAPI) OPcache**, not git.
- `optimize:clear` / `view:clear` run under **CLI PHP**: they delete compiled blade in `storage/framework/views`. Compiled-view filenames are deterministic by source path, so the file is recreated with the SAME name, and an OPcache with `opcache.validate_timestamps=0` keeps serving the OLD opcode for that filename → stale blade forever.
- CLI clears do NOT reset the web OPcache (different process/SAPI). Reset it via a **web request**: `echo '<?php opcache_reset(); echo "OK ".__DIR__; ?>' > public/r.php`, open `https://<domain>/r.php` in a browser, then `rm public/r.php`. (Restarting PHP-FPM also works but cPanel rarely exposes it.)
- The same `public/r.php` that prints `__DIR__` doubles as the docroot probe — the printed dir is the TRUE served docroot, so it also catches decoy-copy docroots.
**Why:** spent many owner round-trips assuming a deploy/push gap when the code was already correct on the live server at the right commit; the live site stayed frozen purely because the web OPcache never got reset. After confirming the live commit is correct, reset the web OPcache before anything else.

# Third look-alike: the feature's OWN STATE GATE (neither deploy nor cache)
- New UI "missing" on live can simply be the feature hiding itself by design. Confirmed case: a new dashboard card gated on day-state rendered NOTHING because the QA company's day was already closed (a 1:26 AM close had locked the new date) — files on live were md5-identical, compiled views contained the new markup, route registered. Hours of deploy/cache chasing for correct behavior.
- **How to apply:** after md5-matching live files + finding the new markup in `storage/framework/views`, STOP suspecting deploy/cache — query the DB for the feature's gating state (e.g. does a day-close report exist for today?) before anything else. Also: design UI states so a "locked/blocked" state renders a visible note rather than nothing — silent-empty states cause exactly this misdiagnosis.

# Why the service worker is a RED HERRING for sale-screen bugs
- `public/sw.js` `skipPatterns` includes `/pos/invoice/create` (and `/login`, `/api/`, `/admin/`...). The SW returns early for these — **the POS sale screen is never cached; it's always network-first/fresh.** So search/cart/keyboard bugs on the sale screen are NEVER a client SW-cache problem.
- `CACHE_VERSION` in sw.js is NOT a reliable deploy marker: `taxnest-v31` was set 2026-05-08 and was NOT bumped when later fixes landed. "Live serves vNN" only proves live is >= the commit that set vNN, not that any later fix is present.

# The fix (covers all cases)
1. Ship Replit→GitHub: `git push origin HEAD:main` (agent can do this once the commit exists; a brand-new edit waits for the end-of-turn auto-commit, then is pushed next turn).
2. On cPanel: `git pull origin main` → `ea-php84 artisan migrate --force` → `view:clear` + `cache:clear` + `config:clear` + `route:clear` (paths/PHP in cpanel-deployment.md).
3. Cashier just reloads the sale screen (not SW-cached, so the fix shows immediately). A one-time hard refresh covers any browser HTTP cache.

**Why:** repeatedly the owner reported "still broken on live" for fixes that were already correct in code — every time the true cause was that the code had not actually been deployed to the live server (and/or not pushed to GitHub), not a caching artifact. Prove the fix is in the live commit FIRST; never re-fix already-fixed code, and don't prescribe cache-clearing as the primary remedy.
