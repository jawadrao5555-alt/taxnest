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

# Deploy runbook (run inside /home/taxnestc/public_html)
1. (Recommended) Back up the prod DB first: cPanel → phpMyAdmin → Export (or cPanel Backup).
2. `cd /home/taxnestc/public_html`
3. `git pull origin main`  (clean fast-forward expected; untracked server files like `error_log`, `.ftpquota`, `taxnest/` do NOT block it)
4. `php artisan migrate --force`  (REQUIRED whenever the gap includes new migrations — the server is often many commits behind, not just the last push; check `git diff --name-only <oldcommit> HEAD | grep migrations` to decide)
5. `php artisan config:clear && php artisan cache:clear && php artisan route:clear && php artisan view:clear`
6. (Optional, for speed) `php artisan config:cache && php artisan route:cache && php artisan view:cache`
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
