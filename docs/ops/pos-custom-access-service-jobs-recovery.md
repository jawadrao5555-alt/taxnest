# Recovery: service_jobs Custom Access deploy regression (PR #72)

## What happened

Deploy Production for squash `fcf5bd0f…` applied migrations, then the
settings-regression guard reported **2** existing `users.pos_custom_access`
rows changed. Live verification was skipped. Root cause in source:
`2026_09_15_011000_backfill_service_jobs_custom_access.php` previously appended
`service_jobs` to every non-null Custom Access JSON set.

## What this hotfix does

1. Retires the silent rewrite (011000 is now a no-op; 013000 documents policy).
2. Lists `service_jobs` under `NO_BACKFILL_REQUIRED` — grants only via NULL
   role defaults / new-company relevance / explicit Team edits.
3. On future deploys, `live-remote-apply.sh` auto-attempts
   `pos:settings-restore --write` from the same pre-deploy baseline, retains
   the baseline on regression, and still **fail-closes** (`REMOTE_SETTINGS_REGRESSION`).
4. Owner recovery command (dry-run first):

```bash
# On the live host only, after owner approval — NOT from Cloud Agents:
php artisan pos:settings-restore --from=/home/jawadrao5555/.taxnest-settings-before.json
php artisan pos:settings-restore --from=/home/jawadrao5555/.taxnest-settings-before.json --write
```

The command restores only rows proven to be a `service_jobs`-only append,
verifies before/after hashes, refuses ambiguity, and never prints customer
values. It does **not** strip `service_jobs` from users who already had it.

## Cloud Agent boundary

Do not execute the restore against production from a Cloud Agent. Do not pass
`allow_settings`. Do not claim LIVE VERIFIED until Actions live-verify passes.
