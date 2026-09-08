# Cloud Agent — issue → live autonomous workflow

**Authority for the full chain** after a reported TaxNest issue, from local
fix through protected production deploy and live verification.

Companion docs:

| Stage | Doc / tooling |
|---|---|
| Local reproduce → fix → PR | `docs/ops/cloud-agent-issue-resolution.md` |
| Local Chrome | `docs/ops/cloud-agent-local-browser-qa.md` |
| PR auto-merge | `CLOUD_AGENT_HANDOFF.md`, `.github/workflows/enable-pr-auto-merge.yml` |
| Production deploy | `docs/ops/github-production-deploy.md` |
| Rollback | `deployment/ROLLBACK.md` |

This document adds the **post-PR** half: merge → hand to Deploy Production →
live smoke → self-heal loops — while keeping Cloud Agents **production-secret-free**.

---

## Intended chain

```
Issue (owner)
  → Cloud Agent investigation + local MariaDB + Chrome reproduction
  → root-cause fix + targeted tests + original-issue re-test
  → full php artisan test (+ npm build if assets)
  → one focused cursor/* PR with evidence
  → PR checks → automatic squash-merge (cursor/*)
  → Enable PR auto-merge dispatches Deploy Production with exact squash SHA
    (workflow_dispatch; required because GITHUB_TOKEN merges suppress push workflows)
  → Deploy Production **gate**: SHA must be current origin/main tip; stale
    Environment-waiting runs are cancelled (SSH in_progress is never cancelled)
  → GitHub Environment "production" MANUAL approval (owner — not the agent)
  → Actions SSH (PRODUCTION_SSH_PRIVATE_KEY) applies exact target_sha
  → Actions runs scripts/ci-live-verify.sh (SHA + NestPOS markers; LIVE_QA_PASS)
  → PASS ⇒ may say LIVE VERIFIED (cite SHA + Actions URL)
  → FAIL ⇒ autonomous diagnosis/fix/test/PR/merge/redeploy cycle (below)
```

Cloud Agent **hands** the main commit to the protected deploy workflow via
auto-merge plus an explicit `workflow_dispatch` of Deploy Production with
`inputs.target_sha` set to the squash commit. The agent does **not** approve
the Environment, hold SSH keys, or run authenticated live smoke itself.

Human merges / non-token pushes to `main` still start Deploy Production via
the normal `push` trigger (same Environment + exact SHA + live-verify).

---

## Production security boundary (non-negotiable)

| Secret / capability | Where it lives | Cloud Agent |
|---|---|---|
| `PRODUCTION_SSH_PRIVATE_KEY` | Environment `production` | **Never** |
| `LIVE_QA_PASS` | Environment `production` | **Never** |
| Production `.env` / DB / FBR / PRA tokens | VPS only | **Never** |
| Customer credentials | — | **Never** |
| Local Chrome BASE_URL | loopback only | Required |
| `scripts/live-screen-smoke.sh` | Desktop/legacy with local secrets | **Do not run from Cloud** |
| `scripts/ci-live-verify.sh` | Actions only | Observe conclusion only |
| `scripts/cloud-issue-to-live-observe.sh` | Secret-free gh + public `/up` | Allowed |

Local browser tests remain fail-closed against production URLs/IPs
(`scripts/lib/local-browser.mjs`).

Preserved deploy invariants:

- Exact-SHA checkout (`github.sha` / `inputs.target_sha`) and **origin/main tip** equality
- Concurrency group `production-deploy` on the SSH/apply job (`cancel-in-progress: false`)
- Cancellable pre-apply `gate` (`production-deploy-gate`) so a newer tip can supersede a stale approval wait
- Elaan freshness + idempotent insert (`deploy/elaan.yml`)
- Never use `skip_elaan` merely to bypass a failure
- Never bypass Environment approval
- Never destructive customer-data tests on production
- Rollback remains `deployment/ROLLBACK.md` (not auto-invoked by Cloud Agent)

---

## Live verification strategy

After a successful remote apply, **the same** Deploy Production job runs
`scripts/ci-live-verify.sh`, which must:

1. Confirm live `git rev-parse HEAD` **equals** `EXPECTED_SHA` / `github.sha`
2. Confirm public `/up` returns 200
3. Login as the standing **live QA** company (Environment secret `LIVE_QA_PASS`)
4. Assert NestPOS feature markers (dashboard / sale / extended pages) — **not**
   merely HTTP 200
5. Optionally assert commit-specific markers from `deploy/live-verify.markers`

Default profile skips destructive bill-seed probes. Do not invent green fake
markers.

**LIVE VERIFIED** is allowed only when this Actions step passes for the
commit that fixed the reported issue. Public `/up` alone is insufficient.

### One-time owner setup

On GitHub → Settings → Environments → `production`, add secret:

- `LIVE_QA_PASS` — password for the standing live QA NestPOS login
  (`qa.fullaudit@taxnest.com.pk`), same identity historically used by
  `scripts/live-screen-smoke.sh`

`PRODUCTION_SSH_PRIVATE_KEY` remains required as before.

---

## Cloud Agent behavior after opening the PR

1. Subscribe to PR checks / CI (`cursor-subscriptions`) — do not busy-poll.
2. After squash-merge to `main`, observe Deploy Production with
   `bash scripts/cloud-issue-to-live-observe.sh --sha=<merged-sha>`.
3. Owner must still **manually approve** Environment `production` when prompted.
4. If Actions **succeeds** (deploy + live-verify): report **LIVE VERIFIED** with
   SHA + workflow URL + what markers were covered.
5. If Actions **fails**: enter the self-heal cycle below. Do **not** say DONE /
   FIXED / SUCCESS / LIVE VERIFIED.

---

## Self-healing cycle (smoke/deploy failure)

Treat a post-deploy live-verify failure (or deploy failure) as a **new
diagnosis cycle for the ORIGINAL issue**:

```
FAIL (Actions evidence)
  → collect exact logs (gh run view --log / step summary) — no production secrets
  → diagnose root cause
  → use stronger tools / subagents when useful (see Multi-agent strategy)
  → implement a better fix on a NEW cursor/* branch from latest origin/main
  → reproduce locally (MariaDB + Chrome)
  → targeted PHPUnit + original-issue re-test
  → full suite when feasible
  → new focused PR with iteration audit trail
  → PR checks → auto-merge → Deploy Production → live-verify again
```

Rules:

- Each iteration must include **new evidence** or a **materially improved fix**
- Never silently change unrelated features
- Never use `skip_elaan` to paper over verify failures
- Never claim success because an earlier local PHPUnit run passed
- If rollback is required, follow `deployment/ROLLBACK.md` and stop for owner
  confirmation before any destructive production recovery

---

## Retry / iteration limits

| Limit | Value |
|---|---|
| Max autonomous fix→PR→deploy→verify iterations per reported issue | **3** |
| Infinite loops | **Forbidden** |
| After 3 failed live-verify cycles | **STOP** — report exact blocker, all SHAs, Actions URLs, local evidence |
| Unsafe / destructive production action required | **STOP** — owner decision |

Environment override (documentation only): treat
`TAXNEST_MAX_ISSUE_TO_LIVE_ITERATIONS` as informational if an operator sets it
in a run prompt; default remains 3.

---

## Multi-agent / stronger-capability strategy

Objective is **successful resolution**, not minimizing agent usage.

When the first approach stalls or live-verify fails with unclear cause, Cloud
Agents SHOULD use stronger available capabilities, for example:

| Need | Approach |
|---|---|
| Broad codebase search | `Task` / explore subagent |
| Hard bug isolation | Debug-oriented subagent or deeper logging locally |
| UI confirmation | Local Chrome via `scripts/lib/local-browser.mjs` (never production Chrome from Cloud) |
| Parallel research | Multiple subagents on **disjoint** questions; do not dual-edit the same files |
| CI / PR waiting | `cursor-subscriptions` (CI + PR), not sleep-poll loops |

Do not spawn conflicting writers on one branch. One focused `cursor/*` PR per
iteration.

---

## Audit trail (every iteration)

Record in the PR body and final agent report:

1. Iteration number (1..3)
2. Original issue statement
3. Local reproduction evidence
4. Root cause
5. Files changed
6. Exact tests + browser flows
7. PR number + head SHA
8. Merged main SHA
9. Deploy Production run URL + conclusion
10. Live-verify result (PASS/FAIL) and markers checked
11. If FAIL: next hypothesis

Template snippet for PR bodies:

```markdown
## Issue-to-live audit
- Iteration: N/3
- Original issue: …
- Local reproduction: …
- Root cause: …
- Tests: …
- Browser: …
- Prior live-verify failure (if any): Actions URL …
```

---

## Language discipline

| Phrase | Allowed when |
|---|---|
| DONE / FIXED / SUCCESS | Original issue re-tested locally **and** (for live claims) live-verify passed |
| LIVE VERIFIED | Deploy Production job including `ci-live-verify.sh` **passed** for the fixing SHA |
| Deployed | Actions apply succeeded (still need live-verify for LIVE VERIFIED) |

Never use those phrases merely because code changed, PHPUnit passed, or a PR merged.

---

## Related commands

| Intent | Who | Command |
|---|---|---|
| Local NestPOS smoke | Cloud | `BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-ui-smoke.mjs` |
| Observe deploy/verify | Cloud | `bash scripts/cloud-issue-to-live-observe.sh --sha=…` |
| Live verify | Actions only | `scripts/ci-live-verify.sh` |
| Static guardrail | Anyone | `bash scripts/tests/issue-to-live-check.sh` |
| Policy static | Anyone | `bash scripts/tests/cloud-agent-issue-resolution-check.sh` |
