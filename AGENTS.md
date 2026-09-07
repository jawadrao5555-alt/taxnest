# TaxNest — agent instructions

This repository is **jawadrao5555-alt/taxnest** (Laravel NestPOS / TaxNest).

## Default for reported issues

For every real product issue, follow the **default end-to-end issue-resolution
policy**:

→ [`docs/ops/cloud-agent-issue-resolution.md`](docs/ops/cloud-agent-issue-resolution.md)

That policy requires: understand the flow → inspect code → **reproduce locally**
→ fix root cause → targeted + Chrome/MariaDB verification as needed →
**re-test the original failure** → full `php artisan test` when feasible →
one focused `cursor/*` PR with evidence.

Do **not** claim DONE / FIXED / VERIFIED merely because code changed or
PHPUnit passed.

After the PR merges, continue with the **issue → live** chain
([`docs/ops/cloud-agent-issue-to-live.md`](docs/ops/cloud-agent-issue-to-live.md)):
PR auto-merge → protected Deploy Production (owner Environment approval) →
Actions live-verify → self-heal loops on failure (max 3). Cloud Agents stay
**production-secret-free**; say **LIVE VERIFIED** only after Actions
`ci-live-verify.sh` passes.

## Entrypoints

| Doc | Role |
|---|---|
| `CLOUD_AGENT_HANDOFF.md` | Git/PR/deploy boundary + links |
| `docs/ops/cloud-agent-issue-resolution.md` | **DEFAULT** local issue-resolution workflow |
| `docs/ops/cloud-agent-issue-to-live.md` | **Issue → live** merge/deploy/verify/self-heal |
| `docs/ops/cloud-agent-development.md` | Local/Cloud bootstrap (MariaDB) |
| `docs/ops/cloud-agent-local-browser-qa.md` | Fail-closed Chrome UI smoke |
| `docs/ops/cloud-agent-architecture.md` | Non-secret invariants |
| `docs/architecture/README.md` | **Permanent SaaS reverse-engineering knowledge base** (read before large changes) |
| `replit.md` | Authoritative product map |

## Hard safety boundary

Cloud Agents **MUST NEVER** access, modify, or deploy production; must never
use production credentials, live customer data, production DB, FBR/PRA
production tokens, or production SSH keys. Browser/DB testing is
**loopback / local disposable DB only**. Production deploy is GitHub Actions +
manual Environment approval — not the Cloud Agent. Authenticated live smoke
runs only in Actions (`LIVE_QA_PASS` Environment secret).

## Live Ops (NestPOS PRA)

For LIVE company/agent/printer/PRA investigation without production secrets in
the Cloud Agent environment, use:

→ [`docs/ops/live-ops.md`](docs/ops/live-ops.md)  
→ [`docs/ops/live-ops-security.md`](docs/ops/live-ops-security.md)

Cloud Agents may run `scripts/cloud-live-ops-request.sh` (diagnose) and
`scripts/cloud-live-ops-remediate-request.sh` (propose / request
approve+execute). Trusted runners hold secrets. Diagnosis never equals approval.
Owner phrase: `OWNER_APPROVES_LIVE_OPS_FIX`.
