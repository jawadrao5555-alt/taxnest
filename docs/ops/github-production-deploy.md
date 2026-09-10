# GitHub Actions → production (Nayatel VPS)

Permanent production deploy path after code is already on `main`:

```
Cloud Agent → cursor/* feature branch → PR (include deploy/elaan.yml for POS-visible changes)
  → PR checks (no deploy) → STOP for owner
  → Owner approves the exact PR + head SHA from SaaS Admin → Deploy Approvals
  → scheduled Approval Relay Dispatch authenticates with GitHub Actions OIDC
  → Owner Merge & Deploy claims a one-time owner-workflow receipt
  → squash merge pinned to that head SHA → exact squash SHA must be origin/main tip
  → workflow dispatches target_sha + approval ID + random correlation nonce
  → relay consumes the receipt while binding the earliest exact-nonce GitHub run
  → GitHub Actions workflow ".github/workflows/deploy-production.yml"
  → job "gate": OIDC run/attempt/workflow SHA + nonce must match; target must be current main tip;
    stale waiting runs are cancelled
    (in_progress SSH is never cancelled)
  → GitHub Environment "production-deploy" (secrets + main-only branch policy;
    no required reviewers — protection is repository fail-closed gates)
  → job "deploy": re-check tip, then SSH with dedicated deploy key "taxnest-production-deploy"
  → insert committed Elaan spec on live (scripts/elaan-insert.sh, idempotent)
  → existing Elaan freshness gate
  → scripts/ci-deploy-production.sh applies the exact TARGET_SHA on the VPS
  → scripts/ci-live-verify.sh (live HEAD == TARGET_SHA + NestPOS markers)
```

Deploy Production **never** pushes to `main` and **never** runs on `pull_request`.
Cursor auto-merge is **disabled**. Green PR checks are not permission to merge or
SSH. Normal trusted deploys must **not** wait for a human Environment reviewer
on `production-deploy`. Live Ops stays on a **separate** Environment
(`production`) that **keeps** required reviewers.

## Owner Merge & Deploy → Deploy Production handoff

GitHub suppresses new workflow runs for most events caused by `GITHUB_TOKEN`
(including the `push` that follows an Actions `pulls.merge` squash).

Supported handoff (no Cloud Agent secrets):

1. The owner creates and password-confirms an exact PR/SHA approval in SaaS
   Admin → **Deploy Approvals**. How-to:
   `docs/ops/owner-merge-and-deploy.md`.
2. Scheduled `.github/workflows/approval-dispatch.yml` authenticates to the
   relay with GitHub Actions OIDC, leases the approval, and dispatches
   `.github/workflows/owner-merge-and-deploy.yml`.
3. The owner workflow claims a one-time receipt, then squash-merges
   **only** a Ready, same-repo `cursor/*` PR to `main`
   whose head SHA still matches and whose **PR checks / validate** succeeded.
4. It reads the **exact squash/merge commit SHA** on `main` and refuses if that
   SHA is not the current `origin/main` **tip**.
5. It records that SHA, creates a random correlation nonce, and dispatches
   `deploy-production.yml` with `target_sha`, `approval_request_id`, and
   `handoff_nonce`. It then consumes the receipt while registering the earliest
   exact nonce/SHA GitHub run ID with the relay.
6. Deploy Production proves its registered run ID, run attempt, nonce, and
   workflow SHA through OIDC, checks out that SHA, and
   refuses it unless it is
   **exactly** the current `origin/main` tip (ancestor-only is not enough).
   `skip_elaan` / `allow_settings` fail closed here. The gate then cancels other
   Deploy Production runs that are still `waiting`. Job `deploy` uses
   Environment `production-deploy` (secrets only) + concurrency group
   `production-deploy` with `cancel-in-progress: false`, re-checks the tip,
   applies that SHA, then runs `ci-live-verify.sh` with the same SHA. Never
   auto-approve Environment deployments with a GitHub token.

`.github/workflows/enable-pr-auto-merge.yml` is **retired**: it no longer merges
or dispatches. Static proof: `bash scripts/tests/owner-merge-and-deploy-check.sh`.

## Owner merge vs production deploy vs Live Ops

These are separate gates:

| Gate | What happens | Who/what waits |
|---|---|---|
| PR checks | `.github/workflows/pr-checks.yml` on `cursor/*` PRs. Does not merge or deploy. | Agent + owner review the report. |
| Owner approval relay | Authenticated super-admin approval bound to one repository, PR, head SHA, request ID, and expiry. GitHub OIDC dispatcher starts Owner Merge & Deploy. | **Owner** approves on mobile; scheduled GitHub dispatcher waits for that approval. |
| Owner Merge & Deploy | Revalidates and squash-merges one relay-approved PR, then consumes its receipt while registering the exact nonce-correlated Deploy run. | Fail-closed relay claim; no phrase or manual workflow input is authority. |
| Deploy Production (`production-deploy`) | `.github/workflows/deploy-production.yml` on `workflow_dispatch` with exact tip SHA + approval ID + correlation nonce | Registered run ID/attempt + OIDC workflow SHA + nonce provenance, exact origin/main tip, serialized SSH (`production-deploy`, `cancel-in-progress: false`), Elaan freshness, dirty-worktree preflight, exact-SHA apply, `ci-live-verify.sh`. **No human Environment reviewer** on this Environment. |
| Live Ops (`production`) | `live-ops-diagnose.yml` / `live-ops-remediate.yml` | Required reviewers on Environment `production` — **keep MANUAL**. Also `OWNER_APPROVES_LIVE_OPS_FIX` for mutations. |

Do not give Cloud Agent production SSH keys or Environment secrets. Cloud Agents
must not merge PRs or dispatch Owner Merge & Deploy.

## What you must configure in GitHub (manual)

Two Environments exist on purpose. GitHub Environment protection is **per Environment, not per workflow**. Putting Deploy Production and Live Ops on the same Environment made every normal deploy wait for a human click.

1. **Deploy Environment name:** `production-deploy`  
   Repo → Settings → Environments → New environment → name exactly `production-deploy`.

2. **Do not add required reviewers** on `production-deploy`. That was the repetitive bottleneck. Safety is the repository gates above, plus:

   - Deployment branches: **selected branches / `main` only** (already the model on `production`)
   - Secrets stay **Environment-scoped** (never repository-wide `PRODUCTION_SSH_PRIVATE_KEY` / `LIVE_QA_PASS`)

3. **`production-deploy` secrets:**
   - Name (exact): `PRODUCTION_SSH_PRIVATE_KEY`
   - Value: the **private** half of the dedicated VPS deploy key whose public key comment is `taxnest-production-deploy`
   - Scope: Environment `production-deploy` only
   - Name (exact): `LIVE_QA_PASS`
   - Value: password for the standing live NestPOS QA login (`qa.fullaudit@taxnest.com.pk`) used **only** by `scripts/ci-live-verify.sh` after deploy
   - Scope: Environment `production-deploy` only — **never** give this to Cloud Agents

4. **Live Ops Environment `production`:** keep **required reviewers**. Copy the SSH key here only if Live Ops still SSHs (plus `LIVE_OPS_RUNNER_TOKEN` / `LIVE_OPS_BASE_URL` as today). Do **not** remove `production` reviewers to “make deploy faster” — that would auto-start Live Ops remediations.

5. **Do not** store or use the old Replit key (`.local/ssh/nayatel_vps_key`) in Actions.

6. Protect `main` with a ruleset that **requires** the status check **PR checks / validate** so Owner Merge & Deploy cannot land a commit whose latest checks failed. Optional: also require PR reviews.

7. **Never** auto-approve Environment deployments with a GitHub token. **Never** move production SSH/QA secrets to repository secrets merely to skip reviewers.

If `production-deploy` secrets are missing, Deploy Production fail-closes (empty `PRODUCTION_SSH_PRIVATE_KEY`) and does not SSH. That is safer than waiting on Live Ops reviewers.

## What the workflow does

| Step | Behavior |
|---|---|
| Trigger | `workflow_dispatch` on `main` from Owner Merge & Deploy with `target_sha`, `approval_request_id`, and `handoff_nonce`. No push trigger. |
| Deploy SHA | Exact 40-char `target_sha` recorded by the relay as the approved squash SHA. It must equal current `origin/main` tip at gate time and again immediately before SSH. Ancestor-of-main is not sufficient. Historical SHA → fail closed, no mutation. Rollback is `deployment/ROLLBACK.md`, not this workflow. |
| Concurrency | **No workflow-level group.** `gate` uses `production-deploy-gate` with `cancel-in-progress: true` (newer tip supersedes older pre-apply). `deploy` uses `production-deploy` with `cancel-in-progress: false` — at most one SSH/apply; in-flight apply is never cancelled. After a SHA proves it is the tip, `gate` cancels other runs whose status is `waiting` (never `in_progress`). |
| Gate | Job `deploy` uses `environment: production-deploy` (secrets + main-only branch policy; **no required reviewers**). Job `gate` does **not** use an Environment (no secrets, starts immediately, fail-closed on non-tip and on `skip_elaan`/`allow_settings`). |
| Checkout | Exact deploy SHA (resolved), full history |
| SSH | Writes `PRODUCTION_SSH_PRIVATE_KEY` to a temp file (mode 600), uses `scripts/lib/live-known-hosts` + `StrictHostKeyChecking=yes` |
| Elaan spec | If `deploy/elaan.yml` is in the commit, `scripts/elaan-insert.sh --from-file --deploy-sha=$TARGET_SHA` creates a published `AppUpdate` whose **title is `{spec title} [deploy {40-char SHA}]`**. A new SHA therefore cannot no-op against an older row with the same human title (Deploy Production #15 / AppUpdate #265). Idempotent on the **qualified** title: same-SHA retry is `ELAAN_EXISTS` (not duplicated or re-dated). The reserved Daily L001 title is rejected. Unattended Deploy Production **refuses** `skip_elaan`. |
| Elaan gate | Freshness check: a published `pos`/`all` row must have `created_at` after the last deploy marker for a **new** SHA. A **same-SHA** rerun/refresh (live HEAD and marker commit both equal the TARGET_SHA) may pass only when that SHA-qualified published title still exists as a published `pos`/`all` AppUpdate — the same human title from an older SHA does not count, and existing titles are never re-dated or duplicated. Infra-only deploys omit `deploy/elaan.yml` only when a qualifying published row already exists; unattended Deploy Production **cannot** skip this gate. |
| Apply | `scripts/ci-deploy-production.sh` → shared `scripts/lib/live-remote-apply.sh` |
| Live verify | Same job runs `scripts/ci-live-verify.sh`: live HEAD == deploy SHA, `/up` 200, NestPOS QA login + feature markers (not merely HTTP 200). Uses Environment secrets `PRODUCTION_SSH_PRIVATE_KEY` + `LIVE_QA_PASS`. Failure fails the workflow — Cloud Agents must start a new diagnosis cycle (`docs/ops/cloud-agent-issue-to-live.md`). |
| Semantics | Same remote core as `deploy-live.sh`: flock lock, maintenance `artisan down` (200), exact-SHA checkout, composer if needed, migrate only when the gap includes migrations, config/route/view cache rebuild, ownership + SELinux repair, PHP-FPM reload with OPcache proof, `taxnest-queue` restart, `artisan up`, homepage 200, cache-fresh probe, deploy marker. Fail closed (site stays in maintenance on apply failure). |
| PWA cache | The remote apply stamps the served `public/sw.js` `CACHE_VERSION` on live as `taxnest-<UTC date>-<sha8>` right after the exact-SHA checkout (working tree only, never committed; restored before the next checkout). New SHA ⇒ new version ⇒ devices purge old STATIC/RUNTIME caches and get the SW update badge. Log markers: `REMOTE_STEP: sw.js CACHE_VERSION stamped …`, or `REMOTE_SW_STAMP_FAILED` / `REMOTE_SW_STAMP_SKIPPED` warnings. |
| Live dirty tree | Unexpected tracked modifications fail closed (no auto-stash / reset / checkout / clean). Deploy Production #16 failed because the previous apply left the intentional `public/sw.js` stamp dirty, and the preflight treated every tracked `M` as unexpected. The preflight now classifies on the runner: `public/sw.js` is allowed **only** when `git diff HEAD -- public/sw.js` is solely the live-remote-apply stamp line. Any other file or any extra `sw.js` hunk still fails. The leftover stamp is **not** discarded in preflight; `remote_apply` still restores it immediately before the next checkout, then restamps. |
| Not run | Replit-local preflights (MySQL staging, Chromium, `.local` QA), SW `CACHE_VERSION` auto-bump **commits** (replaced by the live stamp above), any `git push`, Cloud Agent processes |

Required `workflow_dispatch` inputs:

- `target_sha` — exact 40-char current `origin/main` tip and relay-recorded squash SHA.
- `approval_request_id` — UUID of the authenticated owner approval.
- `handoff_nonce` — random 128-bit correlation value. It is not bearer authority; the relay also requires the registered GitHub run ID/attempt and exact OIDC workflow SHA.

## Committed Elaan spec (`deploy/elaan.yml`)

POS/FBR What's New still lives in `app_updates` (admin `/admin/app-updates`, popup + bell, 7-day window, `AppUpdateSeen`, `pos_whats_new_enabled`). Cloud Agents **must not** SSH or write that table.

For a production-bound PR that should announce a change:

1. Copy `deploy/elaan.example.yml` → `deploy/elaan.yml` (or edit the existing file).
2. Prefer a **new human title**. Never reuse `Daily L001 ke liye roz Reset dabana zaroori nahi`. CI always appends ` [deploy {TARGET_SHA}]` before insert, so a new SHA cannot reuse an older AppUpdate even if the human title is unchanged. Same-SHA Actions reruns no-op that qualified title (no re-date, no duplicate).
3. After the tip/Elaan gates pass, Actions inserts the SHA-qualified row on live, then the freshness gate must still pass. A new SHA needs a **time-fresh** row (the insert just created it). `ELAAN_EXISTS` on an old human title is **not** freshness.

The freshness gate counts `audience IN ('pos','all')` only. An `fbr_pos`-only spec will insert but will **not** satisfy the gate.

`scripts/elaan-insert.sh` remains the insert implementation (manual Replit path unchanged: run it before `deploy-live.sh`).

## Keys

| Key | Role |
|---|---|
| `taxnest-production-deploy` | Dedicated VPS authorized_keys entry for CI. Private key → GitHub Environment `production-deploy` secret `PRODUCTION_SSH_PRIVATE_KEY` only (Live Ops may hold a copy on Environment `production`). |
| `.local/ssh/nayatel_vps_key` | Legacy Replit manual path for `scripts/deploy-live.sh`. **Not used by Actions.** |

Host identity is pinned in `scripts/lib/live-known-hosts`. Host metadata (IP, paths, services) lives in `scripts/lib/live-host.sh`.

## Relationship to `scripts/deploy-live.sh`

`deploy-live.sh` remains the manual/Replit one-command deploy (local preflights, optional SW bump, push workspace HEAD to `main`, then remote apply). Both paths call `scripts/lib/live-remote-apply.sh` for the remote mutation core. Prefer the GitHub Actions path once Environment `production-deploy` secrets and the `main`-only branch policy are configured. Use `deploy-live.sh` for emergencies that need `--no-elaan` or `--allow-settings`.

## Issue → live (Cloud Agent)

After a `cursor/*` PR is **Ready** and green, the owner follows
**`docs/ops/owner-merge-and-deploy.md`**, then
**`docs/ops/cloud-agent-issue-to-live.md`**:

- Owner mobile approval → OIDC relay → Owner Merge & Deploy squash starts and registers the exact nonce-correlated Deploy run for the approved SHA
- Cursor does **not** auto-merge. Green PR checks are not permission to merge.
- Normal deploys do **not** wait for Environment reviewers (`production-deploy` has none)
- Actions runs post-deploy `ci-live-verify.sh` (SHA + NestPOS markers)
- Cloud Agents observe with `bash scripts/cloud-issue-to-live-observe.sh` (secret-free) only after the owner has merged
- On live-verify failure: new PR then STOP for owner (max 3); never claim LIVE VERIFIED early
- Owner-merge proof: `bash scripts/tests/owner-merge-and-deploy-check.sh`
- Handoff static proof: `bash scripts/tests/automerge-deploy-handoff-check.sh`
- Unattended-path proof: `bash scripts/tests/deploy-unattended-safety-check.sh`

## Rollback

Rollback is unchanged and is **not** automated by this workflow. Follow `deployment/ROLLBACK.md`.

## Safety checklist (automated; owner spot-checks on failure)

The unattended path already fail-closes unless:

- The commit is the **current** `origin/main` tip (not an older merge / not unmerged)
- Elaan freshness passes (`deploy/elaan.yml` insert + published pos/all row). `skip_elaan` is refused here.
- Live dirty worktree is clean or only the expected `public/sw.js` stamp
- Exact-SHA apply + `ci-live-verify.sh` succeed
- `skip_elaan` / `allow_settings` were not set

If a deploy fail-closes on a dirty live `public/sw.js` whose `git diff HEAD -- public/sw.js` is **not** solely the live-remote-apply `CACHE_VERSION` stamp, treat it as a real live edit. Do not `git reset --hard`, stash, or checkout until that diff is understood. If the diff **is** solely that stamp, no destructive reconciliation is required — the classifier allows it and `remote_apply` restores then restamps.

Live Ops Environment `production` still has **required reviewers**. Do not approve Live Ops remediations unless you intend a mutation.
