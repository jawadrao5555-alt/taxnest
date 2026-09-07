# GitHub Actions → production (Nayatel VPS)

Permanent production deploy path after code is already on `main`:

```
Cloud Agent → feature branch → PR (include deploy/elaan.yml for POS-visible changes)
  → owner merges to main
  → GitHub Actions workflow ".github/workflows/deploy-production.yml"
  → GitHub Environment "production" (required reviewers approve)
  → SSH with dedicated deploy key "taxnest-production-deploy"
  → insert committed Elaan spec on live (scripts/elaan-insert.sh, idempotent)
  → existing Elaan freshness gate
  → scripts/ci-deploy-production.sh applies the exact github.sha on the VPS
```

This workflow **never** pushes to `main`. Agents and CI must not treat a green Actions run as permission to merge.

## What you must configure in GitHub (manual)

1. **Environment name:** `production`  
   Repo → Settings → Environments → New environment → name exactly `production`.

2. **Required reviewers:** add yourself (and any other owners) so every production deploy waits for explicit approval. This is the production gate.

3. **Environment secret:**
   - Name (exact): `PRODUCTION_SSH_PRIVATE_KEY`
   - Value: the **private** half of the dedicated VPS deploy key whose public key comment is `taxnest-production-deploy`
   - Scope: Environment `production` only (not a repository-wide secret unless you intentionally want that — prefer Environment)

4. **Do not** store or use the old Replit key (`.local/ssh/nayatel_vps_key`) in Actions.

5. Optional but recommended: protect `main` with required PR reviews so only merged code can trigger the push-to-main deploy path.

## What the workflow does

| Step | Behavior |
|---|---|
| Trigger | `push` to `main`, or manual `workflow_dispatch` on `main` |
| Concurrency | `production-deploy` with `cancel-in-progress: false` — at most one production deploy runs; others queue |
| Gate | Job uses `environment: production` → GitHub waits for required reviewers |
| Checkout | Exact `github.sha` (the merged main commit), full history |
| SSH | Writes `PRODUCTION_SSH_PRIVATE_KEY` to a temp file (mode 600), uses `scripts/lib/live-known-hosts` + `StrictHostKeyChecking=yes` |
| Elaan spec | If `deploy/elaan.yml` is in the commit, `scripts/elaan-insert.sh --from-file` creates a published `AppUpdate` on live (same popup/bell/7-day/seen/master-switch as before). Idempotent on **title**: a retry of the same deploy is a no-op; an older row with that title (including Daily L001) is **not** duplicated or re-dated. `skip_elaan` skips this insert. |
| Elaan gate | Unchanged freshness check: a published `pos`/`all` row must have `created_at` after the last deploy marker. Infra-only deploys omit `deploy/elaan.yml` and use `skip_elaan`, or insert on live after the last marker. |
| Apply | `scripts/ci-deploy-production.sh` → shared `scripts/lib/live-remote-apply.sh` |
| Semantics | Same remote core as `deploy-live.sh`: flock lock, maintenance `artisan down` (200), exact-SHA checkout, composer if needed, migrate only when the gap includes migrations, config/route/view cache rebuild, ownership + SELinux repair, PHP-FPM reload with OPcache proof, `taxnest-queue` restart, `artisan up`, homepage 200, cache-fresh probe, deploy marker. Fail closed (site stays in maintenance on apply failure). |
| Not run | Replit-local preflights (MySQL staging, Chromium, `.local` QA), SW `CACHE_VERSION` auto-bump commits, any `git push` |

Manual `workflow_dispatch` inputs:

- `skip_elaan` — emergency only; skips **both** committed-spec insert and the What's New freshness gate
- `allow_settings` — same meaning as `deploy-live.sh --allow-settings=...`

## Committed Elaan spec (`deploy/elaan.yml`)

POS/FBR What's New still lives in `app_updates` (admin `/admin/app-updates`, popup + bell, 7-day window, `AppUpdateSeen`, `pos_whats_new_enabled`). Cloud Agents **must not** SSH or write that table.

For a production-bound PR that should announce a change:

1. Copy `deploy/elaan.example.yml` → `deploy/elaan.yml` (or edit the existing file).
2. Use a **new unique title**. Never reuse `Daily L001 ke liye roz Reset dabana zaroori nahi`.
3. After Environment approval, Actions inserts the row on live, then the freshness gate must still pass.

The freshness gate counts `audience IN ('pos','all')` only. An `fbr_pos`-only spec will insert but will **not** satisfy the gate.

`scripts/elaan-insert.sh` remains the insert implementation (manual Replit path unchanged: run it before `deploy-live.sh`).

## Keys

| Key | Role |
|---|---|
| `taxnest-production-deploy` | Dedicated VPS authorized_keys entry for CI. Private key → GitHub Environment secret `PRODUCTION_SSH_PRIVATE_KEY` only. |
| `.local/ssh/nayatel_vps_key` | Legacy Replit manual path for `scripts/deploy-live.sh`. **Not used by Actions.** |

Host identity is pinned in `scripts/lib/live-known-hosts`. Host metadata (IP, paths, services) lives in `scripts/lib/live-host.sh`.

## Relationship to `scripts/deploy-live.sh`

`deploy-live.sh` remains the manual/Replit one-command deploy (local preflights, optional SW bump, push workspace HEAD to `main`, then remote apply). Both paths call `scripts/lib/live-remote-apply.sh` for the remote mutation core. Prefer the GitHub Actions path once the Environment secret and reviewers are configured.

## Rollback

Rollback is unchanged and is **not** automated by this workflow. Follow `deployment/ROLLBACK.md`.

## Safety checklist for reviewers

Before approving a production Environment deployment:

- Confirm the commit is the intended merge on `main`
- Confirm migrations / settings impact are expected
- Confirm an Elaan exists for this deploy (`deploy/elaan.yml` in the commit, or a live insert after the last marker) unless this is an explicit emergency with `skip_elaan`
