---
name: View-as-Company impersonation
description: How super-admin "View as Company" impersonation works (view-only phase) and what to reuse for the future full-access phase.
---

# Super-admin "View as Company" impersonation

Lets a SaaS super-admin (admin guard, `admin_users` provider) step into a company's
own panel and see it exactly as that company sees it. Phase 1 shipped is **view-only**
(all state changes blocked); full-access is a planned later phase.

## Why the dual-login works (key non-obvious fact)
The **admin guard uses a different provider (`admin_users`) than the panel guards
(`web` / `pos` / `fbrpos`, all `users`)**, and all guards share ONE browser session.
So the admin can `auth(panelGuard)->login($companyUser)` while their admin-guard login
stays intact in the same session — admin "becomes" the company user without losing
their own admin identity. Exit just logs out the panel guard; the admin guard remains.

## Entry gate must match what the panel middleware enforces
On impersonate you MUST satisfy the panel's own gates or the session gets bounced on the
first request: `company_status === 'active'` (CompanyIsolation force-logs-out non-active),
`fbrpos` additionally needs `fbr_pos_enabled`, and the picked company user must be
`is_active` (`role='company_admin'`, with `pos_role='pos_admin'` fallback for POS).
Map `product_type` → guard: `di→web`, `pos→pos`, `fbrpos→fbrpos`.

## View-only enforcement
`ReadOnlyImpersonation` middleware appended to the **web group** (NOT global — must run
after session so `session('impersonation.readonly')` is readable). When the flag is set
and the path is not under `admin/`, it blocks non-GET/HEAD/OPTIONS verbs **and** GET
`demo-login/*` (a GET authenticator). Ajax/JSON → 403; otherwise redirect back with error.
Critical: `pos/*` is CSRF-exempt, so this middleware is the ONLY write barrier there.

## Teardown
- Explicit Exit: log out ONLY the panel guard, `forget('impersonation')`, audit, redirect
  to the company show page. **Never** `session()->invalidate()` on Exit (would kill the
  admin login too).
- Admin logout DOES fully tear down impersonation for free: `AdminAuthController@logout`
  calls `session()->invalidate()`, which flushes the shared session — every panel guard
  login key AND the `impersonation` flag. Verified: after admin logout mid-impersonation,
  both `/pos/dashboard`→`/pos/login` and `/admin/dashboard`→`/admin/login`.

**Why it matters for full-access phase:** reuse the same guard-coexistence + entry-gate
logic; just drop/relax `ReadOnlyImpersonation` (or gate it on an `impersonation.readonly`
boolean) and keep the audit-log on every mutating action while impersonating.
