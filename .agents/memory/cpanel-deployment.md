---
name: cPanel production deployment runbook
description: Exact paths/commands to deploy TaxNest to the owner's shared cPanel host (git pull + migrate + cache + cron). Non-secret facts only.
---

# Direct SSH access (agent can deploy itself — Jul 2026)
- Agent has key-based SSH: key at `.local/ssh/cpanel_deploy_key` (ed25519, public key authorized in `~/.ssh/authorized_keys` on the server). Connect: `ssh -i /home/runner/workspace/.local/ssh/cpanel_deploy_key -p 22 -o BatchMode=yes taxnestc@taxnest.com.pk`.
- Host/port/user also in env vars CPANEL_SSH_HOST / CPANEL_SSH_PORT / CPANEL_SSH_USERNAME (no password needed).
- So the flow is now: `git push origin HEAD:main` from the workspace, then run the deploy runbook below OVER SSH yourself — no more copy-paste blocks for the owner (still offer them as fallback if SSH breaks).

# Where the live site actually is
- Host: shared cPanel, server node `eu1`, cPanel user `taxnestc`.
- LIVE Laravel app root: `/home/taxnestc/public_html` — this IS the git repo (remote = GitHub `jawadrao5555-alt/taxnest`, branch `main`). Document root serves from `public_html/public/` (there is NO `public_html/index.php`; the real entry is `public_html/public/index.php`).
- **Decoy/duplicate copies — NOT live, never pull or edit there:** `/home/taxnestc/public_html/taxnest`, `/home/taxnestc/repositories/taxnest`, `/home/taxnestc/.trash/taxnest`. All three are stale clones of the same GitHub repo; touching them does nothing to the live site and only causes confusion.
- PHP 8.4 binary: `/usr/local/bin/ea-php84`. Plain `php` may be the wrong version on this host — prefer the full binary path in cron and when in doubt.
- `.env` is gitignored, so it (and the prod DB credentials inside it) survives every `git pull`. Never commit/overwrite it.

# Partial tar-deploy = live 500 for EVERYONE (19 Jul 2026 incident)
- A tar-deploy that ships a VIEW but not its MODEL/MIGRATION silently breaks live: universal.blade.php (calls `Company::posTaxPricingMode()`) was live but Company.php + the pos_tax_pricing_mode migration were NOT → every POS sale-screen load 500'd for ALL live companies until the gap was found.
- **How to apply:** after ANY tar-deploy, treat the FEATURE (not the file) as the deploy unit — md5-compare every file the feature touched (models, services, migrations, views, receipts) local vs live, run `git status --porcelain` on live to see the full drift list, run `migrate --force`, then curl the affected page expecting 200. Never assume an earlier session deployed its whole feature.
- The live worktree is often DIRTY (tracked files modified by past scp hot-fixes). `git pull` then aborts ("Please commit or stash"). Safe fix ONCE the hot-fix content is committed upstream: verify `git diff origin/main --stat` shows NO app-code differences (only docs/memory), then `git stash && git pull origin main` — stash kept as backup, content identical.
- Feature not yet committed in the workspace (checkpoint commits only happen at turn end): deploy by streaming files over SSH — `tar czf - <paths> | ssh ... "cd /home/taxnestc/public_html && tar xzf -"` preserves relative paths in one shot; then migrate+caches+opcache as usual. Next turn's checkpoint commit makes a later pull a clean no-op (identical content).
- Workspace-side: a stale `.git/refs/remotes/origin/main.lock` makes `git push` error AFTER succeeding ("Everything up-to-date" = remote already has it); sandbox blocks removing the lock — verify true remote state with `git ls-remote origin main` instead.

# Deploy runbook (run inside /home/taxnestc/public_html)
1. (Recommended) Back up the prod DB first: cPanel → phpMyAdmin → Export (or cPanel Backup).
2. `cd /home/taxnestc/public_html`
3. `git pull origin main`  (clean fast-forward expected; untracked server files like `error_log`, `.ftpquota`, `taxnest/` do NOT block it)
   - **Untracked build assets DO block the pull** (19 Jul 2026): if a past tar-deploy shipped `public/build/assets/*` files that the incoming commit now TRACKS, pull aborts "untracked working tree files would be overwritten". md5-verify they match the workspace copies, `rm` them, re-pull. ALWAYS confirm success with `git log -1` — the abort message can scroll past and the pull silently never happened.
4. `php artisan migrate --force`  (REQUIRED whenever the gap includes new migrations — the server is often many commits behind, not just the last push; check `git diff --name-only <oldcommit> HEAD | grep migrations` to decide)
5. `php artisan config:clear && php artisan cache:clear && php artisan route:clear && php artisan view:clear`
6. (Optional, for speed) `php artisan config:cache && php artisan route:cache && php artisan view:cache`
   - **route:clear/route:cache is NOT optional when the gap adds routes** (19 Jul 2026): live keeps a route cache, so a new route 500s with "Route [name] not defined" from views even though routes/web.php md5-matches. view:clear alone does NOT fix it. Always rebuild the route cache after any pull that touches routes/web.php.
7. **Reset the WEB OPcache — the step everyone forgets; it burned us for many rounds.** `optimize:clear`/`view:clear` run under CLI PHP and do NOT touch the PHP-FPM (web) OPcache. With `opcache.validate_timestamps=0` the web server keeps serving the OLD compiled blade forever even though source is updated and CLI caches are cleared — the live page looks frozen on old code. Fix: `echo '<?php opcache_reset(); echo "OK ".__DIR__; ?>' > public/r.php`, then OPEN `https://<domain>/r.php` in a browser (must be a WEB hit, not CLI), then `rm public/r.php`. The printed `__DIR__` also proves the true served docroot.
- CONFIRMED served docroot (via the r.php probe) = `/home/taxnestc/public_html/public` — so public_html IS the live app; decoys are not served.
- `composer install` only when `composer.json/lock` changed in the gap (usually not).

# Cron (one-time setup — REQUIRED for trial reminders / scheduled jobs)
Add via cPanel → **Cron Jobs** UI (NOT by typing the line in the bash prompt — that does nothing). Set timing to every minute, and put ONLY the command:
`/usr/local/bin/ea-php84 /home/taxnestc/public_html/artisan schedule:run >> /dev/null 2>&1`
Verify with `/usr/local/bin/ea-php84 /home/taxnestc/public_html/artisan schedule:list`.
See `prod-scheduled-jobs-cron.md` for why this is mandatory.

# Notes
- Migrations here are idempotent / self-healing (see `prod-schema-drift-selfheal.md`), so `migrate --force` is safe to re-run; already-applied migrations are skipped.
- Owner is non-technical and deploys manually over SSH/cPanel — give exact copy-paste blocks, not abstract steps.
- **Give ONE `&&`-chained one-liner that STARTS with `cd /home/taxnestc/public_html`, never a multi-line block.** A multi-line paste can run the `artisan` lines from the home dir (the `cd` line gets separated / pasted out of order) → every artisan call prints `Could not open input file: artisan` while git pull/migrate silently never happen. The single cd-prefixed one-liner guarantees cwd.
- **OPcache reset from SSH needs NO browser:** `curl -s https://taxnest.com.pk/r.php` IS a real web (PHP-FPM) hit, so it resets the web OPcache just like opening it in a browser. Bake it into the one-liner: `... && echo '<?php opcache_reset(); ?>' > public/r.php && curl -s https://taxnest.com.pk/r.php ; rm -f public/r.php ; echo DONE` (use `;` before rm so r.php is always cleaned up even if curl fails).

## PENDING live local edit (21 Jul 2026)
- `resources/views/pos/universal.blade.php` was patched DIRECTLY on live via sed (search-suggestion name: `truncate block` → `block leading-snug`), identical change committed in workspace. Before the NEXT `git pull` on live, restore the file first (`git checkout -- resources/views/pos/universal.blade.php` on live — safe, the commit carries the same fix). Remove this note once deployed via git.
