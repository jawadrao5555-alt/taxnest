---
name: cPanel production deployment runbook
description: Exact paths/commands to deploy TaxNest to the owner's shared cPanel host (git pull + migrate + cache + cron). Non-secret facts only.
---

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
- `composer install` only when `composer.json/lock` changed in the gap (usually not).

# Cron (one-time setup — REQUIRED for trial reminders / scheduled jobs)
Add via cPanel → **Cron Jobs** UI (NOT by typing the line in the bash prompt — that does nothing). Set timing to every minute, and put ONLY the command:
`/usr/local/bin/ea-php84 /home/taxnestc/public_html/artisan schedule:run >> /dev/null 2>&1`
Verify with `/usr/local/bin/ea-php84 /home/taxnestc/public_html/artisan schedule:list`.
See `prod-scheduled-jobs-cron.md` for why this is mandatory.

# Notes
- Migrations here are idempotent / self-healing (see `prod-schema-drift-selfheal.md`), so `migrate --force` is safe to re-run; already-applied migrations are skipped.
- Owner is non-technical and deploys manually over SSH/cPanel — give exact copy-paste blocks, not abstract steps.
