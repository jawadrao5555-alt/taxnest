# 27 — Live Ops Integration Points (NO IMPLEMENTATION)

**Do not implement Live Ops here.** This file maps where a Live Ops system must **fit into** existing architecture so it never creates parallel company/role/branch systems.

Live Ops already exists for NestPOS PRA (`docs/ops/live-ops.md`, `config/live_ops.php`, SaaS UI `/admin/live-ops`). Future work must extend this seam.

## Existing company context

| Need | Existing source |
|------|-----------------|
| Tenant id | `companies.id` |
| Product scope | `companies.product_type` — Live Ops config currently `['pos']` |
| Search/select UX | SaaS `/admin/companies` pattern (`search` + `status`) reused on Live Ops index |
| Panel context when impersonating | `session('impersonation')` + panel `currentCompanyId` |

**Must not:** invent a second company switcher for staff users.

## Super Admin context

| Need | Existing source |
|------|-----------------|
| Who may operate Live Ops UI | `AdminUser::isSuperAdmin()` — same as Live Activity |
| Dual-guard support tooling | View as / Manage as (`05`, `06`) for human investigation inside panels |
| Cross-company SaaS visibility | Admin queries without CompanyScope |

**Must not:** grant POS `pos_manager` SaaS Live Ops by creating a parallel permission.

## Product boundaries

Live Ops NestPOS-focused: PRA health, agent, printers, billing summaries for `pos`.  
DI / FBR POS / Health ops would need **separate** explicit product scopes if ever added — not silent expansion.

## Company roles (staff)

| Role | Live Ops implication |
|------|----------------------|
| SaaS super_admin | Full NestPOS Live Ops |
| Non-super AdminUser | 403 |
| company_admin / pos_manager | POS panel only — **no** `/admin/live-ops` |
| viewer / cashier / portals | No Live Ops privileges |

Proof tests: `tests/Feature/LiveOps/LiveOpsAuthorizationTest.php` A–F.

## Branch structure

Live Ops company diagnostics are company-scoped. Branch filtering uses existing `BranchContextService` if a future diagnostic needs it — do not invent branch auth.

## Agent / device / printer

| Capability | Existing seam |
|------------|---------------|
| Heartbeat delivery of commands | `AgentController@heartbeat` → `pending_commands` |
| Command results | `POST /api/agent/command-result` |
| Allow-list | `config/live_ops.php` + `pra-agent/src/live-ops-commands.js` |
| Devices | `pos_agent_devices` |
| Print | `pos_print_jobs` enqueue + claim |
| Force update advertise | `companies.agent_force_update_at` |

## Audit

Use `live_ops_audit_events` + mirror `AdminAuditLog` where appropriate (`LiveOpsAuditService`). Do not create a third unaudited mutation path.

## Billing / health data

Diagnostics already read subscriptions, agent telemetry, PRA failures, print failures, and (DAILY_OPS / SERVER_HEALTH) read-only host/queue/log signals via `LiveOpsDiagnosticsService` + `LiveOpsPlatformHealth`. Prefer these readers over raw SQL from agents.

## Runner / Cloud Agent split

Cloud Agent = planner (`scripts/cloud-live-ops-*.sh`).  
Execution = GitHub Environment + runner token / SSH artisan allow-list.  
Never put production secrets in Cloud Agent env.

## Integration checklist for future Live Ops changes

1. Reuse AdminUser super_admin gate  
2. Reuse companies search UX  
3. Reuse agent command channel  
4. Reuse print job table for test prints  
5. Reuse audit tables  
6. Add Feature tests for auth boundaries A–F  
7. Update `docs/ops/live-ops.md` + this map if seams change
