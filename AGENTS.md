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

## Entrypoints

| Doc | Role |
|---|---|
| `CLOUD_AGENT_HANDOFF.md` | Git/PR/deploy boundary + links |
| `docs/ops/cloud-agent-issue-resolution.md` | **DEFAULT** issue-resolution workflow |
| `docs/ops/cloud-agent-development.md` | Local/Cloud bootstrap (MariaDB) |
| `docs/ops/cloud-agent-local-browser-qa.md` | Fail-closed Chrome UI smoke |
| `docs/ops/cloud-agent-architecture.md` | Non-secret invariants |
| `replit.md` | Authoritative product map |

## Hard safety boundary

Cloud Agents **MUST NEVER** access, modify, or deploy production; must never
use production credentials, live customer data, production DB, FBR/PRA
production tokens, or production SSH keys. Browser/DB testing is
**loopback / local disposable DB only**. Production deploy is GitHub Actions +
manual Environment approval — not the Cloud Agent.
