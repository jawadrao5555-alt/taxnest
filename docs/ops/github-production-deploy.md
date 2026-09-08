# GitHub Actions → production (Nayatel VPS)

Permanent production deploy path after code is already on `main`:

```
Cloud Agent → cursor/* feature branch → PR (include deploy/elaan.yml for POS-visible changes)
  → PR checks (no deploy) → GitHub squash auto-merge after required checks
  → Enable PR auto-merge dispatches Deploy Production with inputs.target_sha=<squash SHA>
    (GITHUB_TOKEN merges do not start push workflows; workflow_dispatch does)
  → OR a human push to main starts Deploy Production with github.sha
  → GitHub Actions workflow ".github/workflows/deploy-production.yml"
  → job "gate": requested SHA must equal current origin/main tip; stale waiting
    Environment-approval runs are cancelled (in_progress SSH is never cancelled)
  → GitHub Environment "production" (required reviewers approve — MANUAL)
  → job "deploy": re-check tip, then SSH with dedicated deploy key "taxnest-production-deploy"
  → insert committed Elaan spec on live (scripts/elaan-insert.sh, idempotent)
  → existing Elaan freshness gate
  → scripts/ci-deploy-production.sh applies the exact TARGET_SHA on the VPS
  → scripts/ci-live-verify.sh (live HEAD == TARGET_SHA + NestPOS markers)
```

Deploy Production **never** pushes to `main` and **never** runs on `pull_request`. Auto-merge of a PR is not production approval. Agents and CI must not treat a green PR-checks run as permission to SSH or skip Environment reviewers.

## Auto-merge → Deploy Production handoff

GitHub suppresses new workflow runs for most events caused by `GITHUB_TOKEN`
(including the `push` that follows an Actions `pulls.merge` squash). That is why
cursor/* auto-merges after PR #14/#15 did not start Deploy Production.

Supported handoff (no Cloud Agent secrets):

1. `.github/workflows/enable-pr-auto-merge.yml` squash-merges (or waits for native auto-merge).
2. It reads the **exact squash/merge commit SHA** on `main`.
3. It calls `actions.createWorkflowDispatch` on `deploy-production.yml` with `inputs.target_sha=<that SHA>`.
4. `workflow_dispatch` is exempt from GITHUB_TOKEN event suppression, so Deploy Production starts.
5. Deploy Production **gate** checks out that SHA and refuses it unless it is
   **exactly** the current `origin/main` tip (ancestor-only is not enough).
   It then cancels other Deploy Production runs that are still `waiting` for
   Environment approval. Job `deploy` keeps Environment approval + concurrency
   group `production-deploy` with `cancel-in-progress: false`, re-checks the
   tip, applies that SHA, then runs `ci-live-verify.sh` with the same SHA.

Static proof: `bash scripts/tests/automerge-deploy-handoff-check.sh`.

## Cloud Agent PR auto-merge vs production approval

These are separate gates:

| Gate | What happens | Who/what waits |
|---|---|---|
| PR checks + GitHub auto-merge | Enables **squash** auto-merge on same-repo `cursor/*` PRs to `main` after `.github/workflows/pr-checks.yml` succeeds (`enablePullRequestAutoMerge`). Does not use `PRODUCTION_SSH_PRIVATE_KEY` or Environment `production`. | GitHub required status checks (configure **PR checks / validate** as required on `main`). |
| Production Environment | `.github/workflows/deploy-production.yml` on **push to `main`** (or `workflow_dispatch`) | Required reviewers on Environment `production` — **keep MANUAL**. |

One-time repo settings: Settings → General → **Allow auto-merge** and **Allow squash merging**. Do not give Cloud Agent production SSH keys or Environment secrets.

## What you must configure in GitHub (manual)

1. **Environment name:** `production`  
   Repo → Settings → Environments → New environment → name exactly `production`.

2. **Required reviewers:** add yourself (and any other owners) so every production deploy waits for explicit approval. This is the production gate.

3. **Environment secrets:**
   - Name (exact): `PRODUCTION_SSH_PRIVATE_KEY`
   - Value: the **private** half of the dedicated VPS deploy key whose public key comment is `taxnest-production-deploy`
   - Scope: Environment `production` only (not a repository-wide secret unless you intentionally want that — prefer Environment)
   - Name (exact): `LIVE_QA_PASS`
   - Value: password for the standing live NestPOS QA login (`qa.fullaudit@taxnest.com.pk`) used **only** by `scripts/ci-live-verify.sh` after deploy
   - Scope: Environment `production` only — **never** give this to Cloud Agents

4. **Do not** store or use the old Replit key (`.local/ssh/nayatel_vps_key`) in Actions.

5. Protect `main` with a ruleset that **requires** the status check **PR checks / validate** so squash auto-merge cannot land a commit whose latest checks failed. Optional: also require PR reviews. Production Environment approval stays independent and **manual**.

## What the workflow does

| Step | Behavior |
|---|---|
| Trigger | `push` to `main`, or `workflow_dispatch` on `main` (auto-merge handoff passes `target_sha`) |
| Deploy SHA | `push` → `github.sha`; `workflow_dispatch` with `target_sha` → that exact 40-char SHA. **Must equal current `origin/main` tip** at gate time and again after Environment approval, immediately before SSH. Ancestor-of-main is not sufficient. Historical SHA → fail closed, no mutation. Rollback is `deployment/ROLLBACK.md`, not this workflow. |
| Concurrency | **No workflow-level group.** `gate` uses `production-deploy-gate` with `cancel-in-progress: true` (newer tip supersedes older pre-apply). `deploy` uses `production-deploy` with `cancel-in-progress: false` — at most one SSH/apply; in-flight apply is never cancelled. After a SHA proves it is the tip, `gate` cancels other runs whose status is `waiting` (Environment approval only — never `in_progress`). |
| Gate | Job `deploy` uses `environment: production` → GitHub waits for required reviewers. Job `gate` does **not** use the Environment (no secrets, starts immediately, fail-closed on non-tip). |
| Checkout | Exact deploy SHA (resolved), full history |
| SSH | Writes `PRODUCTION_SSH_PRIVATE_KEY` to a temp file (mode 600), uses `scripts/lib/live-known-hosts` + `StrictHostKeyChecking=yes` |
| Elaan spec | If `deploy/elaan.yml` is in the commit, `scripts/elaan-insert.sh --from-file` creates a published `AppUpdate` on live (same popup/bell/7-day/seen/master-switch as before). Idempotent on **title**: an existing exact title is a successful no-op (not duplicated or re-dated). The reserved Daily L001 title is rejected and never inserted. `skip_elaan` skips this insert. |
| Elaan gate | Freshness check: a published `pos`/`all` row must have `created_at` after the last deploy marker for a **new** SHA. A **same-SHA** rerun/refresh (live HEAD and marker commit both equal the TARGET_SHA) may pass only when the committed `deploy/elaan.yml` exact title still exists as a published `pos`/`all` AppUpdate — unrelated old announcements do not count, and existing titles are never re-dated or duplicated. Infra-only deploys omit `deploy/elaan.yml` and use `skip_elaan`, or insert on live after the last marker. |
| Apply | `scripts/ci-deploy-production.sh` → shared `scripts/lib/live-remote-apply.sh` |
| Live verify | Same job runs `scripts/ci-live-verify.sh`: live HEAD == deploy SHA, `/up` 200, NestPOS QA login + feature markers (not merely HTTP 200). Uses Environment secrets `PRODUCTION_SSH_PRIVATE_KEY` + `LIVE_QA_PASS`. Failure fails the workflow — Cloud Agents must start a new diagnosis cycle (`docs/ops/cloud-agent-issue-to-live.md`). |
| Semantics | Same remote core as `deploy-live.sh`: flock lock, maintenance `artisan down` (200), exact-SHA checkout, composer if needed, migrate only when the gap includes migrations, config/route/view cache rebuild, ownership + SELinux repair, PHP-FPM reload with OPcache proof, `taxnest-queue` restart, `artisan up`, homepage 200, cache-fresh probe, deploy marker. Fail closed (site stays in maintenance on apply failure). |
| PWA cache | The remote apply stamps the served `public/sw.js` `CACHE_VERSION` on live as `taxnest-<UTC date>-<sha8>` right after the exact-SHA checkout (working tree only, never committed; restored before the next checkout). New SHA ⇒ new version ⇒ devices purge old STATIC/RUNTIME caches and get the SW update badge. Log markers: `REMOTE_STEP: sw.js CACHE_VERSION stamped …`, or `REMOTE_SW_STAMP_FAILED` / `REMOTE_SW_STAMP_SKIPPED` warnings. |
| Not run | Replit-local preflights (MySQL staging, Chromium, `.local` QA), SW `CACHE_VERSION` auto-bump **commits** (replaced by the live stamp above), any `git push`, Cloud Agent processes |

Manual `workflow_dispatch` inputs:

- `target_sha` — exact 40-char **current origin/main tip** to deploy (used by auto-merge handoff). A historical SHA that is still on main history is **rejected** with a diagnostic; it will not wait for approval or SSH. Leave empty only for emergency dispatch of `github.sha`, which still must equal the tip at run time.
- `skip_elaan` — emergency only; skips **both** committed-spec insert and the What's New freshness gate
- `allow_settings` — same meaning as `deploy-live.sh --allow-settings=...`

## Committed Elaan spec (`deploy/elaan.yml`)

POS/FBR What's New still lives in `app_updates` (admin `/admin/app-updates`, popup + bell, 7-day window, `AppUpdateSeen`, `pos_whats_new_enabled`). Cloud Agents **must not** SSH or write that table.

For a production-bound PR that should announce a change:

1. Copy `deploy/elaan.example.yml` → `deploy/elaan.yml` (or edit the existing file).
2. Use a **new unique title**. Never reuse `Daily L001 ke liye roz Reset dabana zaroori nahi`. Never reuse a title that already exists in live `app_updates` for a **new** SHA — that insert is a successful no-op and will **not** pass the time-based freshness gate. (A same-SHA Actions rerun of an already-deployed commit may reuse the original title as evidence that the announcement for that SHA still exists; it will not re-date or duplicate the row.)
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

## Issue → live (Cloud Agent)

After a `cursor/*` PR merges, follow **`docs/ops/cloud-agent-issue-to-live.md`**:

- Auto-merge squash + `workflow_dispatch` handoff starts Deploy Production with the exact squash SHA (GITHUB_TOKEN merges do not fire `push` workflows)
- Environment approval stays **manual**
- Actions runs post-deploy `ci-live-verify.sh` (SHA + NestPOS markers)
- Cloud Agents observe with `bash scripts/cloud-issue-to-live-observe.sh` (secret-free)
- On live-verify failure: autonomous fix→PR→redeploy cycle (max 3); never claim LIVE VERIFIED early
- Handoff static proof: `bash scripts/tests/automerge-deploy-handoff-check.sh`

## Rollback

Rollback is unchanged and is **not** automated by this workflow. Follow `deployment/ROLLBACK.md`.

## Safety checklist for reviewers

Before approving a production Environment deployment:

- Confirm the commit is the **current** `origin/main` tip (not an older merge). If main has moved, reject / ignore the request and approve the newest Deploy Production run instead.
- Confirm migrations / settings impact are expected
- Confirm an Elaan exists for this deploy (`deploy/elaan.yml` in the commit, or a live insert after the last marker) unless this is an explicit emergency with `skip_elaan`
