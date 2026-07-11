---
name: View-as-Company impersonation
description: How super-admin "View as Company" (view-only) AND "Manage as Company" (full-access) impersonation work — modes, lock downgrade, audit, and the identity/logout blocking gotcha.
---

# Super-admin "View as Company" impersonation

Lets a SaaS super-admin (admin guard, `admin_users` provider) step into a company's
own panel. Two modes share ONE session flag `impersonation` (with `mode` +
`readonly`):
- **view-only** (`readonly=true`, amber banner "View as Company") — every state change blocked.
- **full-access** (`readonly=false`, red banner "Manage as Company") — writes pass
  through and are audited; changes are REAL (incl. live FBR/PRA gov submissions).

Mode is chosen by a `mode` (`view|full`) form input on the company show page; anything
other than exactly `'full'` falls back to view (tamper-safe). Lock = one-way downgrade
full→view (`POST admin/impersonation/lock`); there is NO upgrade path.

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

## Enforcement (two middlewares, web group, order matters)
Both appended to the **web group** (NOT global — must run after StartSession so
`session('impersonation')` is readable), in this order: `ReadOnlyImpersonation` THEN
`LogImpersonatedWrites` (so blocked writes short-circuit before the logger runs).
- `ReadOnlyImpersonation`: `admin/*` always allowed (keeps exit/lock reachable). In
  **view mode** blocks all non-GET verbs. In **BOTH modes** blocks identity/session
  paths: `demo-login/*`, and POST `login`/`logout`/`pos/login`/`pos/logout`/
  `fbr-pos/login`/`fbr-pos/logout`. Ajax/JSON → 403 `{view_only}`; else redirect back.
- `LogImpersonatedWrites`: in full mode only, audits successful (<400) non-GET,
  non-`admin/*` writes to `admin_audit_logs`. **Snapshot `$imp` BEFORE `$next`** (a
  write could clear the session and drop/misattribute the row).
Critical: `pos/*` is CSRF-exempt, so `ReadOnlyImpersonation` is the ONLY write barrier there.

## Why login/logout MUST be blocked in every mode (non-obvious)
Two real gaps caught in review: (1) a panel **login** POST in full mode would auth a
DIFFERENT company into the panel while the `impersonation` flag still points at the
original `company_id` → every later audit row misattributed. (2) a panel **logout**
POST calls `session()->invalidate()` → nukes the admin's own session mid-impersonation
AND escapes the audit trail. So the ONLY way out is the banner's Exit button.

## Teardown
- Explicit Exit: log out ONLY the panel guard, `forget('impersonation')`, audit, redirect
  to the company show page. **Never** `session()->invalidate()` on Exit (would kill the
  admin login too).
- Admin logout DOES fully tear down impersonation for free: `AdminAuthController@logout`
  calls `session()->invalidate()`, which flushes the shared session — every panel guard
  login key AND the `impersonation` flag. Verified: after admin logout mid-impersonation,
  both `/pos/dashboard`→`/pos/login` and `/admin/dashboard`→`/admin/login`.

**Owner tradeoff to remember:** full-access permits REAL FBR/PRA government submissions
(they are NOT blocked). If the owner later wants a guardrail, add a per-submission
confirm/gate on the FBR/PRA submit endpoints — do not silently block them (would look
like a bug). Surfaced to owner when full-access shipped.
