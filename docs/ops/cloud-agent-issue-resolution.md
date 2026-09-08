# Cloud Agent — default issue-resolution policy

**This is the DEFAULT workflow** for every real TaxNest issue reported to a
Cursor Cloud Agent on **jawadrao5555-alt/taxnest**.

The goal is Replit-Agent-class ownership: independently investigate, reproduce
locally, fix the root cause, re-test (including real Chrome UI when the flow is
visual), iterate until the original failure is gone, then open one focused
`cursor/*` PR with evidence.

Tooling already on `main` (PR #13 and cloud-dev parity):

| Capability | Where |
|---|---|
| Local MariaDB + Laravel bootstrap | `docs/ops/cloud-agent-development.md` |
| Fail-closed NestPOS Chrome smoke | `docs/ops/cloud-agent-local-browser-qa.md` |
| Invariants (fiscal / tenancy / tax) | `docs/ops/cloud-agent-architecture.md`, `replit.md` |
| Git / PR / deploy boundary | `CLOUD_AGENT_HANDOFF.md` |

This document defines **behavior**. It does not invent green fake tests.

---

## Hard safety boundary (non-negotiable)

Cloud Agents **MUST NEVER**:

- Access, modify, or deploy **production** (**Never deploy production** from a Cloud Agent)
- Use production credentials, live customer data, production DB, FBR/PRA
  production tokens, or production SSH keys
- Point browser/UI tests at `taxnest.pk` or any non-loopback host
- Use live QA accounts (e.g. `qa.fullaudit@taxnest.com.pk`)
- Run `scripts/live-screen-smoke.sh` as the Cloud verification path
- Commit `.env`, `.local/`, secrets, tokens, or customer passwords
- Click Merge or approve the GitHub Environment `production`

All reproduction, MariaDB work, destructive fixtures, and Chrome verification
use the **protected local development environment only**
(`taxnest_dev` / `taxnest_staging` on `127.0.0.1`/`localhost`).

Production deploy remains a **separate GitHub Actions**
responsibility (Environment `production-deploy`, repository fail-closed gates,
no Cloud Agent SSH). Auto-merge of a `cursor/*` PR is **not** an SSH credential.

---

## Default workflow (every reported issue)

Follow these steps in order. Skip a step only when it is objectively
impossible (document why). Do **not** skip reproduction or post-fix re-test
merely to save time.

### 1. Understand the affected flow

- Restate the reported problem in one or two concrete sentences.
- Name the exact user/business flow (panel, screen, action, expected vs actual).
- Default product scope: **NestPOS PRA** unless the owner expands scope.

### 2. Inspect relevant code

Read before editing:

- Controllers / services / models / migrations
- Routes and middleware (guards, `company` isolation, branch context)
- Blade / Alpine / JS that implement the UI
- Existing PHPUnit Feature/Unit tests and any local smoke scripts

Prefer `replit.md` + `docs/ops/cloud-agent-architecture.md` for invariants.
Do not weaken fiscal, tax, numbering, tenancy, or reporting rules.

### 3. Reproduce locally before claiming root cause

Whenever practical, reproduce the failure on local MariaDB + Laravel **before**
writing the fix. If you cannot reproduce, say so explicitly and show what you
tried — do not invent a root cause.

```bash
bash scripts/cloud-dev-start.sh
bash scripts/cloud-dev-bootstrap.sh          # if needed
bash scripts/cloud-local-qa-seed.sh           # fictional NestPOS demo shop
php artisan serve --host=127.0.0.1 --port=8000
```

### 4. Use local MariaDB for real application behavior

- Prefer MariaDB `taxnest_dev` for integration-style reproduction.
- PHPUnit remains sqlite `:memory:` via `php artisan test` — keep that compatible.
- Destructive seeders must stay behind `DevStagingGuard` + opt-in flags.

### 5. Exercise the real UI in local Chrome

For UI/regression issues (and for NestPOS sale/dashboard/login paths):

```bash
BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-ui-smoke.mjs
```

Extend with a focused `scripts/cloud-local-*-smoke.mjs` that imports
`scripts/lib/local-browser.mjs` when the base smoke is not enough.
**Do not** invent assertion-only scripts that never open the broken screen.

### 6. Capture browser evidence

- Screenshots under `.local/browser-evidence/` (gitignored)
- Console errors / pageerrors / failed requests via the shared helpers
- Note important network/application errors in the PR report

### 7. Identify the root cause

Fix the cause, not only the visible symptom. Trace data/path invariants
(tenant `company_id`, tax snapshots, `pra_status`, series numbering, etc.).

### 8. Implement the smallest correct fix

- Production-quality, minimal diff
- Preserve TaxNest fiscal, tenancy, security, numbering, tax, reporting, and
  deployment invariants
- No drive-by refactors or unrelated file churn

### 9. Test at the right levels

| Level | When |
|---|---|
| Targeted unit/feature/integration | Always for logic changes |
| MariaDB / local app behavior | When DB or multi-tenant behavior matters |
| Real Chrome UI flow | When UI or Blade/Alpine/JS is involved |
| `npm run build` | When Vite/frontend assets change |

### 10. Re-test the ORIGINAL failure after the fix

Prove the original broken behavior is gone using the same reproduction path.
PHPUnit green alone is **not** sufficient for UI issues.

### 11. Iterate — never stop on a red verification

If any targeted test, MariaDB check, browser smoke, or post-fix reproduction
fails: investigate, improve the fix or the real test, and re-run. Multiple
fix/test loops are expected.

### 12. Go deeper when the first approach fails

Use stronger local tools as needed: logging, DB queries on `taxnest_dev`,
Playwright diagnostics, existing Feature tests, artisan tinker (local only).
Do not escalate to production access.

### 13. No fake tests

Do not add superficial or assertion-only tests just to pass CI. Tests must
exercise real TaxNest behavior (or honestly document why a behavioral test is
impossible in this environment).

### 14. Full suite + relevant smoke before the PR

Before opening the PR (when reasonably feasible):

```bash
php artisan test
# UI issues:
BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-ui-smoke.mjs
# Frontend asset changes:
npm ci && npm run build
```

Documentation-only / pure procedural tasks may skip the full suite when
application tests are clearly unaffected — say so in the report.

### 15. Final diff review

Before PR creation, review the diff for:

- Unintended files / secrets / `.env` / `.local`
- Tenant-isolation holes
- Fiscal / tax / numbering / reporting regressions
- Accidental production hosts, deploy scripts, or live credentials

### 16. One focused `cursor/*` PR

Only after reproduce → fix → re-test with evidence:

1. Branch from latest `origin/main` named `cursor/<slug>-0f83`
2. Push and open **one** focused PR to `main`
3. Do **not** manually merge; do **not** deploy production

### 17. Evidence required in the PR / agent report

Every issue-resolution PR (or agent final report) must include:

1. **Original reproduction** — what failed, how, locally  
2. **Root cause** — concise and concrete  
3. **Files changed** — intentional list  
4. **Exact tests run** — commands + pass/fail  
5. **Browser flow tested** — URLs/actions (or “N/A — not UI” with reason)  
6. **Console/network findings** — important ones only  
7. **Post-fix result** — original failure gone?  
8. **Full-suite result** — `php artisan test` summary when run  
9. **Remaining limitations** — honest gaps  

### 18. Language discipline

Never say **DONE**, **FIXED**, or **VERIFIED** merely because:

- code was edited, or  
- PHPUnit passed, or  
- a PR was opened  

Those words are allowed only after the **original reported issue** was
re-tested successfully (or after an explicit, evidence-backed statement that
reproduction was impossible and why).

For **LIVE VERIFIED**, also require Actions `ci-live-verify.sh` success for the
fixing SHA — see `docs/ops/cloud-agent-issue-to-live.md`.

---

## After the PR (issue → live)

Local PR evidence is not the end of ownership. Continue with:

→ **`docs/ops/cloud-agent-issue-to-live.md`**

Merge → Deploy Production (`production-deploy` gates) → live SHA + NestPOS
marker verify → self-heal on failure (max 3 iterations). Cloud Agents remain
production-secret-free.

---

## Quick command map

| Intent | Command |
|---|---|
| Start local DB | `bash scripts/cloud-dev-start.sh` |
| Bootstrap / migrate | `bash scripts/cloud-dev-bootstrap.sh` |
| Seed fictional NestPOS QA | `bash scripts/cloud-local-qa-seed.sh` |
| Serve app | `php artisan serve --host=127.0.0.1 --port=8000` |
| Targeted tests | `php artisan test --filter=…` |
| Full suite | `php artisan test` |
| NestPOS Chrome smoke | `BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-ui-smoke.mjs` |
| Browser QA static check | `bash scripts/tests/cloud-local-browser-qa-check.sh` |
| This policy static check | `bash scripts/tests/cloud-agent-issue-resolution-check.sh` |
| Issue→live static check | `bash scripts/tests/issue-to-live-check.sh` |
| Observe deploy/verify (secret-free) | `bash scripts/cloud-issue-to-live-observe.sh` |
| Frontend build | `npm ci && npm run build` |

---

## Exceptions (narrow)

| Situation | Allowed deviation |
|---|---|
| Docs-only / workflow-only change | May skip Chrome + full suite if no app code changed; still run relevant static checks |
| Pure backend with no UI surface | May skip Chrome; still reproduce via HTTP/Feature/MariaDB when practical |
| Cannot reproduce locally | Document attempts; do not claim root cause certainty; do not claim FIXED |

---

## Related

- `CLOUD_AGENT_HANDOFF.md` — entrypoint; points here for issue work  
- `docs/ops/cloud-agent-issue-to-live.md` — merge → deploy → live-verify → self-heal  
- `docs/ops/cloud-agent-local-browser-qa.md` — fail-closed Chrome tooling  
- `docs/ops/cloud-agent-development.md` — bootstrap  
- `docs/ops/cloud-agent-architecture.md` — invariants  
- `docs/ops/github-production-deploy.md` — production Actions path  
