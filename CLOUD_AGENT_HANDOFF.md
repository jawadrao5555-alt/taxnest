# TaxNest Cloud Agent Handoff

Permanent development handoff for Cursor Cloud Agents working on **jawadrao5555-alt/taxnest**.

## Default for reported issues (REQUIRED)

When the owner reports a **real TaxNest issue**, do **not** jump straight to a
patch + PHPUnit + PR. Operate as the end-to-end issue-resolution owner using the
local-only environment:

→ **[`docs/ops/cloud-agent-issue-resolution.md`](docs/ops/cloud-agent-issue-resolution.md)** (DEFAULT policy)

Minimum bar before a `cursor/*` PR on an issue:

1. Understand the affected user/business flow
2. Inspect relevant code / models / routes / UI / tests
3. **Reproduce locally** (MariaDB + real app flow) whenever practical
4. Use **local Chrome** for UI flows; capture evidence under `.local/browser-evidence/`
5. Fix the **root cause** (smallest correct change; keep fiscal/tenancy/tax invariants)
6. Targeted tests + post-fix **re-test of the original failure**
7. Iterate if anything fails — do not stop or claim success on a red check
8. Full `php artisan test` when feasible; `npm run build` when assets change
9. One focused PR with reproduction, root cause, commands, browser evidence, limitations

Never say **DONE**, **FIXED**, or **VERIFIED** merely because code changed or
PHPUnit passed. The original reported issue must have been re-tested successfully.

**Issue → live (after the PR):** follow
**[`docs/ops/cloud-agent-issue-to-live.md`](docs/ops/cloud-agent-issue-to-live.md)**.
After PR checks pass, **STOP** (Phase 1). Do not merge, do not enable
auto-merge, do not deploy, do not SSH. After an **explicit** owner approval
phrase, run `scripts/owner-merge-and-deploy-request.sh` (Phase 2) — see
[`docs/ops/owner-merge-and-deploy.md`](docs/ops/owner-merge-and-deploy.md).
That is authorization to initiate **Owner Merge & Deploy** only (re-verify PR
number + HEAD SHA first). Owner Merge & Deploy squash-merges, then
**workflow_dispatch** hands the exact squash SHA to Deploy Production
(GITHUB_TOKEN merges do not start push workflows). Do **not** click Merge on
the PR page. Normal deploys use Environment `production-deploy` (no required
reviewers; repository fail-closed gates). Actions runs
`scripts/ci-live-verify.sh`. On failure, open a **new** PR and STOP again
(max **3** iterations). Say **LIVE VERIFIED** only after that Actions step
passes. Observe with `bash scripts/cloud-issue-to-live-observe.sh`
(secret-free). Do **not** `gh pr merge` or dispatch **Deploy Production**.

**Live Ops (NestPOS PRA investigate → explain → owner-approved fix):**
[`docs/ops/live-ops.md`](docs/ops/live-ops.md). Cloud Agents use
`scripts/cloud-live-ops-request.sh` / `scripts/cloud-live-ops-remediate-request.sh`
only. Secrets stay in Environment `production`. Diagnosis ≠ approval.

**Production is out of bounds for the agent process:** never access/modify/deploy
production directly; never use production credentials, live customer data,
production DB, FBR/PRA production tokens, or production SSH keys. Deploy remains
GitHub Actions on Environment `production-deploy` (secrets never in the agent).
Cloud Agents never receive `PRODUCTION_SSH_PRIVATE_KEY` or `LIVE_QA_PASS`.
Live Ops Environment `production` keeps required reviewers.

## Product map

`replit.md` is the authoritative product and operations map (modules, invariants, owner preferences, NestPOS PRA focus). Read the matching `.agents/memory/` topic before editing a subsystem. Do not copy or restate that map here. Also see `AGENTS.md`.

## Current focus

Owner focus is **NestPOS PRA** unless the owner explicitly expands scope. Do not propose or implement DI, FBR POS, admin/SaaS, or other-stream work unless asked.

## Git workflow (required)

1. **`main` is protected / read-only** for Cloud Agent work. Never commit application changes on `main`.
2. Before every task: fetch `origin/main` and confirm a clean working tree.
3. Start from the latest `origin/main`.
4. Create **one feature branch per task** from that baseline. The branch name **must** start with `cursor/` (example: `cursor/short-task-slug`) so Owner Merge & Deploy will accept the PR.
5. Make all edits, commits, and pushes **only** on that feature branch.
6. Commit only the task’s intentional changes.
7. Push the feature branch to GitHub.
8. Open a PR targeting **`main`**.
9. After **PR checks** succeed, report the PR number + head SHA and **STOP**. Do **not** click Merge, do **not** enable auto-merge, do **not** dispatch **Deploy Production**, do **not** SSH, and do **not** approve Live Ops Environment `production`. **Phase 2** starts only after an explicit owner approval phrase: run `scripts/owner-merge-and-deploy-request.sh` with that PR number + HEAD SHA (show both first). If dispatch is 403, print the Actions UI fill-ins. Owner Merge & Deploy squash-merges and starts **Deploy Production** on Environment `production-deploy` (no required reviewers; repository fail-closed gates).
10. If a change is wrong, **preserve branch/PR history** so the change can be safely reverted. Do not force-rewrite shared history to hide mistakes.

## Testing

- Follow **`docs/ops/cloud-agent-issue-resolution.md`** for reported bugs/features that need verification.
- Run appropriate **targeted tests** after changes.
- When feasible, run the full suite with `php artisan test` before declaring a task complete.
- Documentation-only or purely procedural tasks may skip the full suite when application tests are clearly unnecessary — still run `bash scripts/tests/cloud-agent-issue-resolution-check.sh` when editing that policy.
- For NestPOS UI/regression work, use the **local browser QA** loop (MariaDB `taxnest_dev` + `php artisan serve` + Chrome smoke) documented in `docs/ops/cloud-agent-local-browser-qa.md`:
  - `bash scripts/cloud-local-qa-seed.sh`
  - `BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-ui-smoke.mjs`
  - Evidence under `.local/browser-evidence/` (gitignored). Fail-closed: loopback only; never live QA / `taxnest.pk`.

## Cloud development environment

Local/Cloud bootstrap (MariaDB `taxnest_dev`, Vite, PHPUnit sqlite):

- How-to: `docs/ops/cloud-agent-development.md`
- Non-secret architecture/invariants: `docs/ops/cloud-agent-architecture.md`
- Issue-resolution policy (DEFAULT local): `docs/ops/cloud-agent-issue-resolution.md`
- Issue → live (merge/deploy/verify/self-heal): `docs/ops/cloud-agent-issue-to-live.md`
- Cursor config: `.cursor/environment.json` → `scripts/cloud-dev-install.sh` / `scripts/cloud-dev-start.sh`

```bash
bash scripts/cloud-dev-install.sh
bash scripts/cloud-dev-start.sh
bash scripts/cloud-dev-bootstrap.sh
php artisan test
```

Do not commit `.env`. Do not use production credentials in Cloud Agent VMs.

## Safety / never without explicit owner instruction

- **Never deploy production** unless the owner explicitly instructs you to deploy.
- Do not modify production/staging infrastructure or live customer data unless the task explicitly requires it.
- Never commit secrets, credentials, passwords, tokens, `.env` contents, or `.local` contents.
- Do not change company settings, permissions, feature toggles, or legacy chosen values unless the task explicitly requires it (see `replit.md`).

## Production deploy (GitHub Actions)

After a `cursor/` PR is squash-merged to `main` by **Owner Merge & Deploy**, production deploys via GitHub Actions on Environment `production-deploy` — **not** by the Cloud Agent SSHing to the VPS, and **not** via the GitHub PR Merge button. Owner approval of the PR is not an SSH credential. Merge-commit pushes are refused.

- Workflow: `.github/workflows/deploy-production.yml`
- Environment: `production-deploy` (secrets + `main`-only branch policy; **no required reviewers**)
- Secret: `PRODUCTION_SSH_PRIVATE_KEY` (dedicated `taxnest-production-deploy` key only)
- Docs: `docs/ops/github-production-deploy.md`
- Rollback: `deployment/ROLLBACK.md`
- **What's New / Elaan:** for a POS-visible production change, put a unique spec in `deploy/elaan.yml` (see `deploy/elaan.example.yml`). Actions inserts that `AppUpdate` on live via `scripts/elaan-insert.sh`. Agents must **not** write production `app_updates` or SSH to the VPS. Never reuse the Daily L001 title. Unattended Deploy Production **refuses** `skip_elaan`.

Agents must **not** click Merge, approve Live Ops Environment `production`, or run a real production deploy unless the owner explicitly instructs them to. They also must **not** receive production SSH keys, production secrets, live database access, or customer credentials.

## Owner Merge & Deploy (explicit owner action, squash)

Permanent workflow (applies to **future** Cloud Agent PRs once these files are on `main`):

1. Agent opens a same-repo PR to `main` from a `cursor/*` branch, then **stops**.
2. `.github/workflows/pr-checks.yml` still runs (no production secrets, no deploy), including on `ready_for_review`.
3. `.github/workflows/enable-pr-auto-merge.yml` is **retired**: it only logs. It does not squash-merge and does not dispatch Deploy Production.
4. After an explicit owner approval phrase (`Approved — Merge & Deploy`, `Deploy kar do`, `Live kar do`, or `Approved, put it live`), the agent re-verifies the PR (Ready, targets `main`, required checks green, HEAD SHA unchanged, mergeable), shows the exact PR number + HEAD SHA, and runs `scripts/owner-merge-and-deploy-request.sh`. That attempts `workflow_dispatch` of `.github/workflows/owner-merge-and-deploy.yml`. If `gh` is HTTP 403, print the Actions UI fill-ins; the owner clicks **Run workflow**. How-to: `docs/ops/owner-merge-and-deploy.md`. Agents must **not** `gh pr merge` or use the GitHub PR Merge button.
5. That job squash-merges only a Ready, same-repo `cursor/*` PR whose head SHA still matches and whose **validate** check succeeded, verifies the squash SHA is the current `origin/main` tip, then `workflow_dispatch`es **Deploy Production** with **only** `inputs.target_sha`. Environment `production-deploy` stays unattended. Live Ops stays on Environment `production` with required reviewers. Do not start Deploy Production yourself after this workflow begins.

Owner GitHub settings: **Allow squash merging**, and a ruleset/branch protection that requires the **PR checks / validate** job on `main`. Native GitHub auto-merge is no longer part of the Cursor path.

## This file’s purpose

Keep Cloud Agents on a safe, reusable **reproduce → fix → evidence → PR** path against `main`, with NestPOS PRA as default scope and `replit.md` as the product source of truth. Issue work defaults to `docs/ops/cloud-agent-issue-resolution.md`.
