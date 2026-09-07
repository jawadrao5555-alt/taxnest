# 08 — Authorization

## Architecture choice

**FACT:** TaxNest does **not** use Laravel Policies or Gate::define in application Providers.

Authorization = **middleware + model helpers + controller abort/assert**.

## Middleware allow-lists / confinements

| Middleware | Decision |
|------------|----------|
| `role:a,b` (`RoleMiddleware`) | `users.role` must be in list; `User.role=super_admin` bypasses |
| `pos.auth` | Product type pos; role confinement for kitchen/waiter/rider/viewers; optional `PosAccessService` feature grants |
| `PosAdminOnly` | Blocks bare cashiers; used as class middleware on admin POS routes |
| `health.auth` + `health.can` / `health.module` | `HealthAccessService::can()` |
| `admin.auth` | Any AdminUser session |
| Controller `assertSuperAdmin()` | SaaS privileged actions |
| `ReadOnlyImpersonation` | Blocks writes / identity swaps during view-as |

## Role helpers (User)

See `app/Models/User.php`. Key FACTS:
- `isPosAdmin()` includes `pos_manager` and `company_admin`
- `canRequeueExemptPra()` deliberately **excludes** `pos_manager`
- `canManageLocalViewers()` = `company_admin` only
- Viewer detection: `role === 'viewer'` or portal `pos_role`s

**FACT:** No method named `canManageSettings` exists under `app/`.

## Custom access

**FACT:** POS shops can grant cashiers extra features via custom access (`PosAccessService`) — orthogonal to base role.

## Healthcare

**FACT:** `HealthAccessService::ROLES` + optional JSON `health_permissions`; every screen through `can()` + `HealthScopeService` (branch/department).

## SaaS AdminUser roles

**FACT:** `AdminUser.role` — at least `super_admin` and non-super values (schema default `admin`). Non-super can authenticate to `/admin` but fail super-admin gates.

**UNKNOWN:** Which non-super AdminUser roles are used in production day-to-day beyond `support`/`viewer` appearing in tests.

## Decision chain

```text
USER → GUARD → SESSION → MIDDLEWARE (product/tenant)
    → COMPANY CONTEXT (currentCompanyId)
    → BRANCH CONTEXT (currentBranchId)
    → ROLE / pos_role / health_role
    → FEATURE GRANT / PLAN LIMIT
    → CONTROLLER assert / abort
    → OPERATION
```
