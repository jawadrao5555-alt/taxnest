# Live Ops diagnostic operations

Allow-listed, redacted, read-only NestPOS PRA diagnostics. Cloud Agents never hold production SSH, DB, or runner tokens. Execution is GitHub Environment **`production`** (required reviewers) or super-admin `/admin/live-ops`.

Canonical names below. There is no `ERRORS_SUMMARY` — the operation is **`ERROR_SUMMARY`**.

## How to get a full daily report

One run, all companies (no company id):

```bash
# Owner: GitHub → Actions → Live Ops Diagnose → Run workflow from main
# Operation: DAILY_OPS  (now the workflow default)
# Dates: today or a range ≤ 31 days
# Approve Environment production
```

Or SaaS `/admin/live-ops` → operation `DAILY_OPS` → Run (read-only).

`DAILY_OPS` is a composer. It does **not** replace company-scoped `COMPANY_DIAGNOSTIC`.

## Operation catalog

| Operation | Scope | Required inputs | Reports | Stays UNKNOWN when |
|-----------|-------|-----------------|---------|-------------------|
| **DAILY_OPS** | GLOBAL | dates optional (default today) | Overall GREEN/ATTENTION/CRITICAL + every section below | Any child signal that cannot be collected safely |
| **SERVER_HEALTH** | GLOBAL | none | CPU/load, RAM, disk, process presence, allow-listed `systemctl is-active`, Laravel log tail, LogHealth flag, DB ping + thread gauges, queue/scheduler heartbeats, failed_jobs counts, WebSocket `/health`, public `/up` + `/pos/login` timing | HTTP probes disabled (tests); `/proc` missing; systemd missing; WS not listening; `information_schema` not on SQLite |
| **ERROR_SUMMARY** | DUAL | company optional | Company: PRA/print/agent_update error lines. Fleet: same across tenants + laravel.log counts + failed_jobs samples (redacted) | laravel.log unreadable; failed_jobs table missing |
| **PRA_HEALTH** | DUAL | company optional | Company: pending/failed/submitted samples + pra_logs excerpts. Fleet: counts, companies with failures/pending, same-company duplicate `pra_invoice_number` | `pos_transactions` missing |
| **AGENT_HEALTH** | DUAL | company optional | Company: devices + update telemetry + pending commands. Fleet: enabled agents online/offline/long-offline/version/silent-print | — |
| **BILLING_SUMMARY** | DUAL | company optional | Authoritative completed invoice totals (gross/subtotal/tax), PRA submitted/failed/local | — |
| **BILLING_BY_COMPANY** | GLOBAL | dates optional | Same as billing summary with no company filter | — |
| **PROBLEMATIC_COMPANIES** | GLOBAL | dates optional | Zero-billing, offline agents, PRA/print failure leaders, top/bottom billing | — |
| **COMPANY_HEALTH** | COMPANY | company_id or unique name | Profile, PRA flags, agent online, devices | — |
| **PRINTER_HEALTH** | COMPANY | company_id or unique name | Silent print, assigned printer, recent jobs | — |
| **COMPANY_DIAGNOSTIC** | COMPANY | company_id or unique name | Composite company + billing + PRA + agent + printer + errors + likely cause (advisory) | — |

Company-scoped ops still throw `company_id (or resolvable company_name) is required` when blank. GitHub Actions now fail **before SSH** for those ops if both company fields are empty.

## DAILY_OPS sections

1. Overall status (measured facts only)
2. Server health
3. Application health (LogHealth + laravel.log tail)
4. Database health
5. Queue/worker health
6. Scheduler health
7. WebSocket + agent fleet
8. Company activity counts
9. Billing by company
10. Zero-billing companies
11. Problematic companies
12. PRA/transaction health (fleet)
13. Errors (fleet)
14. Performance (public HTTP probes)
15. Security/auth observations (failed_login **counts**, demo-login flag; **no** IPs/credentials)
16. UNKNOWN / observability gaps
17. Recommended owner actions (never auto-executed)

**GREEN / ATTENTION / CRITICAL** use measured evidence. UNKNOWN is listed separately and is **not** a failure. Inference (e.g. duplicate invoice numbers might be a retry/race) is labeled as inference.

## What diagnostics never do

- Remediate, restart, install packages, edit config, or SSH as the Cloud Agent
- Submit or mutate invoices / fiscal devices
- Weaken Environment `production` reviewers or Deploy Production gates
- Print secrets, tokens, private keys, full failed_job payloads, or login IPs

Audit rows and `live_ops_diagnostic_reports` are the only designed writes.

## Remaining limitations

- HTTP probes and WebSocket `/health` run **on the production app host** (Actions SSH artisan or runner API). They are skipped in PHPUnit.
- CPU/RAM/disk come from `/proc` and `disk_free_space` on that host — not a separate metrics agent.
- laravel.log is a **tail scan** (default 256 KiB), not a complete archive.
- `systemctl is-active` is allow-listed unit names only; missing systemd → UNKNOWN.
- NestPOS `product_type=pos` only. FBR POS / DI / Health are out of scope.
- Desktop agent log files only arrive via owner-approved `UPLOAD_REDACTED_LOGS` (remediation), not this read path.
