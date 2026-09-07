# 09 — Tenant Isolation

## Goal

Prevent company A’s users/APIs/jobs from reading or writing company B’s data — while allowing intentional SaaS Super Admin cross-tenant operations.

## Mechanisms (FACT)

### 1. Authentication boundary
Panel middleware refuses wrong `product_type` and inactive users (`PosAuth`, `FbrPosAuth`, `HealthAuth`, `CompanyIsolation`).

### 2. Container binding `currentCompanyId`
Set from `$user->company_id` in panel middleware. Controllers/services commonly filter with `app('currentCompanyId')`.

### 3. Global `CompanyScope`
`app/Models/Scopes/CompanyScope.php` applies `where company_id = currentCompanyId` when bound.

**FACT bypass:** unbound / null / authenticated **User** with `role === 'super_admin'`.

**INFERENCE:** Many POS models rely on **explicit** `where('company_id', …)` rather than the global scope — do not assume every model is scoped.

### 4. Controller checks
Example closures in `routes/web.php` compare invoice `company_id` to current company and allow User `super_admin` exception.

### 5. Agent API
Key maps to **one** company; all queries constrained to that company (`AgentController`).

### 6. Impersonation
Panel user is that company’s admin → natural bind. Orphaned flag cleared if admin session dies. Identity swaps blocked to prevent mid-session tenant hop.

## Intentional Super Admin exception

| Path | How cross-company works safely |
|------|--------------------------------|
| `/admin/*` SaaS queries | No company scope; AdminUser only |
| Impersonation | Temporary dual-guard; audited; Exit restores |
| Live Ops | Super Admin / runner token only; company_id parameters required for company ops |
| Company groups | Admin insight only |

**FACT:** Ordinary managers/viewers **cannot** reach `/admin/live-ops` — different guard → redirect login (see Live Ops auth tests).

## Branch isolation (within tenant)

Orthogonal layer: `currentBranchId` filters dashboards/reports. Owner may use `ALL` sentinel.

## Negative cases

| Attack / mistake | Defense |
|------------------|---------|
| Authenticated to company A, request company B id | Query filters / 403 / not found |
| Use DI session on `/pos` | Guard mismatch → login |
| Agent key for A claiming B’s print job | Claim query includes company_id + device rules |
| Impersonation leftover cookie | Orphan cleanup in ReadOnlyImpersonation |
| Job without company_id | Must be coded carefully — **risk surface**; see invariants |

## Leakage tests to trust

- Live Ops: `LiveOpsAuthorizationTest` F + diagnostics isolation tests
- Health billing: branch-confined cashier tests
- WhatsApp webhook: never updates other companies’ deliveries
