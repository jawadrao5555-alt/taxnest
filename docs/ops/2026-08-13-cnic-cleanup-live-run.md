# Task 592 — Live CNIC cleanup run record (13 Aug 2026)

Operational task: no new code was needed. The cleanup migration
(`database/migrations/2026_08_14_060000_normalize_legacy_cnic_digits.php`)
and the on-demand report command (`app/Console/Commands/CnicDuplicatesReport.php`)
were built in Task 585; this task deployed them to the LIVE cPanel server,
ran them, and records the outcome for the owner.

## What was done on live (`/home/taxnestc/public_html`, over SSH)

1. Reconciled deploy-lineage divergence (workspace `main` merged `origin/main`,
   which carried the PRA credit-note positive-amounts fix) and pushed.
2. Live worktree had one modified tracked file
   (`app/Services/PraIntegrationService.php`) — md5-identical to the committed
   workspace version, so it was `git stash`ed per runbook and the deploy re-run.
3. `bash scripts/deploy-live.sh` → **DEPLOY OK: live HEAD == workspace HEAD
   (c2edbcec…)**, pull + migrate --force + caches + web OPcache reset, homepage 200.

## Verified results on live

```
$ ea-php84 artisan migrate:status | grep 060000
  2026_08_14_060000_normalize_legacy_cnic_digits ................... [150] Ran

$ ea-php84 artisan cnic:duplicates
No duplicate CNICs — every company CNIC is unique.

$ ea-php84 artisan cnic:duplicates --with-deleted
No duplicate CNICs — every company CNIC is unique.
```

Direct DB double-check (bootstrap `php -r` — tinker is disabled on live):

```
dashed/spaced remaining: 0
companies with cnic: 10
```

## Owner-facing outcome

- Live CNIC data is CLEAN: all 10 stored CNICs are already plain digits,
  zero duplicate CNICs (including soft-deleted companies).
- **No owner decision is needed** — there is no duplicate list to choose from.
- The report can be re-run any time on live with `php artisan cnic:duplicates`.

Note: the migration's `[cnic-cleanup]` Log::info lines were not found in
`storage/logs/laravel.log` (migration ran during the deploy pipeline); the
zero-dashed / zero-duplicate state was instead verified directly via the
command and DB queries above, which are the authoritative check.
