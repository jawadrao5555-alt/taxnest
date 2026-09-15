# Recovery: service_jobs Custom Access deploy regression (PR #72)

## What happened

Deploy Production for squash `fcf5bd0f…` applied migrations, then the
settings-regression guard reported **2** existing `users.pos_custom_access`
rows changed. Live verification was skipped. Root cause in source:
`2026_09_15_011000_backfill_service_jobs_custom_access.php` previously appended
`service_jobs` to every non-null Custom Access JSON set.

The retained forensic baseline is:

`/home/jawadrao5555/.taxnest-settings-before.json`

## What this hotfix does

1. Retires the silent rewrite (011000 is now a no-op; 013000 documents policy).
2. Lists `service_jobs` under `NO_BACKFILL_REQUIRED`.
3. **Immutable baselines:** each deploy writes
   `~/.taxnest-settings-baselines/before-<sha>-<pid>-<utc>.json`. The canonical
   retained path is never used as `--out` for a new capture.
4. `pos:settings-snapshot --out` refuses to overwrite an existing file unless
   `--force` (deploy tooling never force-writes onto the retained path).
5. If the retained forensic file exists at deploy start: validate → dry-run
   restore plan → hash-checked write → verify → archive a forensic copy → only
   then clear the active retain slot and capture a fresh per-deploy baseline.
   Ambiguity fails closed (exit 89) without overwriting the retained file.
6. On a new regression: restore from *this* deploy baseline, keep forensic
   copies under `~/.taxnest-settings-retained/`, install the canonical retain
   slot only if empty, still emit `REMOTE_SETTINGS_REGRESSION`.
7. Clean deploys delete only their own temporary baseline.

## Owner recovery (live host only — not from Cloud Agents)

```bash
php artisan pos:settings-restore --from=/home/jawadrao5555/.taxnest-settings-before.json
php artisan pos:settings-restore --from=/home/jawadrao5555/.taxnest-settings-before.json --write
```

Never prints customer values (hashes only). Never strips a legitimate
pre-existing `service_jobs` grant. Do not pass `allow_settings`.

## Cloud Agent boundary

Do not execute restore against production from a Cloud Agent. Do not claim
LIVE VERIFIED until Actions live-verify passes.
