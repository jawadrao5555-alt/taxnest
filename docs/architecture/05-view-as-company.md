# 05 — View as Company

## Naming

**FACT — UI label:** “View as Company”  
**FACT — mode value:** `mode=view` → `session('impersonation.readonly') = true`  
**FACT — audit string:** `Started view-as (read-only)`

This is **not** the same as Manage as Company (`06-as-company.md`).

## Entry

| Item | Value |
|------|-------|
| Route | `POST /admin/companies/{id}/impersonate` |
| Name | `saas.admin.companies.impersonate` |
| Controller | `AdminCompanyController@impersonate` |
| Gate | `assertSuperAdmin()` |
| UI | `resources/views/saas-admin/companies/show.blade.php` (hidden `mode` omitted / not `full` → view) |

## Execution path (FACT)

1. Super Admin posts form from company show page
2. Company must be `company_status=active`
3. Resolve panel guard from `product_type` / NestErps vertical
4. Find first active `users.role=company_admin` (POS fallback `pos_role=pos_admin`)
5. Logout any previous impersonation panel guard
6. `auth($guard)->login($user)` — **admin guard untouched**
7. `session()->regenerate()`
8. Put `impersonation` array with `mode=view`, `readonly=true`
9. Audit log
10. Redirect to product dashboard

## What Super Admin can do in View mode

- Browse the company panel exactly as that company’s admin UI allows for **reads**
- Use `/admin/*` simultaneously (admin always allowed)
- Exit via banner
- Downgrade is N/A (already view)

## What Super Admin cannot do in View mode

**FACT — `ReadOnlyImpersonation`:**
- Any non-GET/HEAD/OPTIONS inside company panels → blocked (403 JSON or redirect with error)
- Identity swaps (login/logout/demo-login on panel paths) → blocked in **both** modes
- Must Exit via banner — cannot panel-logout

## Exit

| Route | `POST /admin/impersonation/stop` |
| Controller | `AdminCompanyController@stopImpersonation` |
| Effect | Logout panel guard only; forget `impersonation`; audit `Stopped view-as`; redirect to company show |

## Middleware stack (web group append, FACT)

1. `ReadOnlyImpersonation` — blocks writes when readonly
2. `LogImpersonatedWrites` — no-ops for readonly (nothing to log)
3. `ConsultantSwitchGuard`
4. …

## Banner

**FACT:** `resources/views/partials/impersonation-banner.blade.php` — amber/eye styling for view-only.

## Negative paths

| Case | Behavior |
|------|----------|
| Non-super AdminUser | 403 from `assertSuperAdmin` |
| Inactive company | Back with error |
| No company_admin user | Back with error |
| Orphaned impersonation flag (admin session gone) | Flag cleared; panel user logged out (`ReadOnlyImpersonation`) |
| Write attempt | Blocked with “View-only mode…” |
| Login POST while impersonating | Redirect `/admin/dashboard` with Exit message |
