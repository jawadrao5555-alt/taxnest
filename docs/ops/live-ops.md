# Live TaxNest Operations

Restores practical LIVE NestPOS PRA ops (investigate → explain → owner-directed fix → verify) **without** giving Cursor Cloud Agents production SSH, DB, or secrets.

## Roles

| Role | May do | Must not |
|------|--------|----------|
| Cloud Agent | Request diagnostics / propose remediations via `gh workflow`; read redacted artifacts; explain | Hold prod secrets; execute mutations silently; arbitrary SQL/shell |
| GitHub Environment `production` | Live Ops only: allow-listed artisan/API with secrets after **manual** required-reviewer approval | Accept freeform commands from agents; host Deploy Production |
| GitHub Environment `production-deploy` | Deploy Production SSH/apply/live-verify after repository fail-closed gates (no required reviewers) | Repository-wide secrets; `skip_elaan`; Cloud Agent SSH |
| Super-admin `/admin/live-ops` | Same diagnostics + approve/execute in browser | Expose secrets to Cloud Agent env |
| Desktop Agent | Execute allow-listed `pending_commands` from heartbeat | Arbitrary remote shell |

## Cloud Agent commands

```bash
# Investigate
bash scripts/cloud-live-ops-request.sh --operation=COMPANY_DIAGNOSTIC --company-id=35

# Propose fix (does NOT execute)
bash scripts/cloud-live-ops-remediate-request.sh --mode=propose \
  --action=ENQUEUE_AGENT_COMMAND --company-id=35 \
  --params='{"command_type":"RESYNC"}' \
  --proposal='Agent offline; resync when back'

# After owner says "Fix it" AND supplies phrase + Environment approval:
bash scripts/cloud-live-ops-remediate-request.sh --mode=approve_execute \
  --action-id=<ULID> --phrase=OWNER_APPROVES_LIVE_OPS_FIX
```

Owner approval phrase: `OWNER_APPROVES_LIVE_OPS_FIX`

## Diagnostic operations

See `docs/ops/live-ops-daily-report.md` for scope (GLOBAL / COMPANY / DUAL), required inputs, payload, and UNKNOWN gaps.

| Operation | Scope |
|-----------|--------|
| `DAILY_OPS` | GLOBAL — one daily production health report |
| `SERVER_HEALTH` | GLOBAL — host/app/DB/queue/scheduler/WS/HTTP |
| `BILLING_BY_COMPANY` | GLOBAL |
| `PROBLEMATIC_COMPANIES` | GLOBAL |
| `ERROR_SUMMARY` | DUAL (fleet when company blank) |
| `PRA_HEALTH` | DUAL |
| `AGENT_HEALTH` | DUAL |
| `BILLING_SUMMARY` | DUAL |
| `COMPANY_HEALTH` | COMPANY |
| `PRINTER_HEALTH` | COMPANY |
| `COMPANY_DIAGNOSTIC` | COMPANY |

```bash
# Daily fleet report (preferred)
bash scripts/cloud-live-ops-request.sh --operation=DAILY_OPS

# Investigate one shop
bash scripts/cloud-live-ops-request.sh --operation=COMPANY_DIAGNOSTIC --company-id=35
```

## Remediation allow-list

**Low:** `ENQUEUE_TEST_PRINT`, `FORCE_AGENT_UPDATE_ADVERTISE`, `REFRESH_OPERATIONAL_STATE`, `RETRY_ONE_PRA_INVOICE`  
**Medium:** `REBIND_ASSIGNED_PRINTER`, `ENQUEUE_AGENT_COMMAND`  
**High (denied):** regenerate API key, disable agent, destructive DB, bulk billing, arbitrary SQL/shell/artisan/SSH, credential changes

## Agent commands

`STATUS_REFRESH`, `RESYNC`, `UPLOAD_REDACTED_LOGS`, `TEST_PRINT`, `PRINTER_REFRESH`, `SAFE_AGENT_RESTART`

## Production secrets (Environment only)

- Existing: `PRODUCTION_SSH_PRIVATE_KEY`
- Optional preferred path: `LIVE_OPS_RUNNER_TOKEN` (≥32 chars) + `LIVE_OPS_BASE_URL` (`https://taxnest.pk`)

Set `LIVE_OPS_RUNNER_TOKEN` in production `.env` to match the Environment secret.

## Authorization (existing TaxNest model — do not invent a parallel one)

Live Ops lives in the **SaaS Admin** panel (`admin_users` + `admin.auth`), same gate as Live Activity / Support Inbox:

| Actor | Table / guard | Live Ops access |
|-------|---------------|-----------------|
| Super Admin | `admin_users.role=super_admin` | Full NestPOS PRA Live Ops for any company |
| SaaS support/viewer admin | `admin_users` non-super | **403** |
| POS company_admin / pos_manager | `users` + pos/web | **No** `/admin/live-ops` (redirect login) — manage shop via `/pos/*` |
| Viewer / cashier / archive_viewer / local_viewer | `users` | **No** Live Ops privileges |

There is **no** `company_user` multi-company staff pivot. One `users.company_id` only.

Related multi-entity tables that are **not** Live Ops auth:
- `company_groups` / `company_group_members` — sibling product identity insight for SaaS admins
- `franchises` + franchise portal — franchisees see their linked companies on a separate guard
- `branch_user` — Health branch scoping inside one company

Company search on Live Ops reuses the `/admin/companies` filter shape (`search` + `status` + name/ntn/owner), scoped to NestPOS PRA product types. Super Admin cross-company operation matches Live Activity / Agents / Support Inbox (`AdminUser::isSuperAdmin()`).
