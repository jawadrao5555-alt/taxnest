# 19 — Audit & Observability

## Audit surfaces (FACT)

| Store | Audience | Writer examples |
|-------|----------|-----------------|
| `admin_audit_logs` | SaaS | `AdminAuditLog::log` — impersonation, company edits, Live Ops mirror |
| `audit_logs` | Company | `AuditLogService` SHA256 immutable |
| `pra_logs` | NestPOS fiscal | `PraIntegrationService` |
| `fbr_logs` | DI fiscal | `FbrService` / submit paths |
| `fbr_pos_logs` | FBR POS fiscal | FBR POS paths |
| `live_ops_audit_events` | NestPOS ops | `LiveOpsAuditService` |
| `health_audit_*` | Healthcare | `HealthAuditObserver` / engine |
| `invoice_deliveries` | DI buyer send | Email/WhatsApp webhook |
| `invoice` activity / override logs | DI | activity services |

## Impersonation audit

- Start view / start manage
- Lock to view
- Stop
- Full-access writes: method/path/status via `LogImpersonatedWrites`

## Operational heartbeats

Scheduler writes SystemSetting heartbeats; watchdogs email on silence; MySQL connection health command; site uptime watch.

## Logging

Laravel `storage/logs`; MailHealth; security failed-login logging via Auth Failed listener.

## Live Ops observability

Diagnostic reports stored redacted; remediation evidence; agent command results — see `docs/ops/live-ops.md` (ops) and `27-live-ops-integration-points.md` (architecture plug-in map).
