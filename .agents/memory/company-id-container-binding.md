---
name: Per-request company-id container binding key
description: The active company id is bound in the Laravel container under 'n' — not 'ln' or 'currentCompanyId'.
---

# Per-request company-id container binding key

The current company id for a tenant request is bound in the service container as
`app('n')` (set by `CompanyIsolation`, `PosAuth`, `FbrPosAuth` middleware via
`app()->instance('n', $user->company_id)`). `CompanyScope`, `CheckPlanLimit`,
`CheckCompanyApproval`, `PosController`, etc. all read `app('n')`.

**Rule:** To resolve the active company in Blade/controllers within a tenant request,
use `app()->bound('n') ? app('n') : (auth()->user()->company_id ?? null)`.

**Why:** A trial-lock modal used `app('ln')` (a non-existent key) and silently never
activated — `$companyId` was always null. There is no `'ln'` and no `'currentCompanyId'`
binding anywhere in the app.

**How to apply:** Admin routes do NOT run CompanyIsolation, so `'n'` is unbound there
(intended — admins are cross-tenant). Guard against unbound `'n'` before calling `app('n')`.

**Write-path fallback order:** when `'n'` is unbound and you must pick a company from the
auth guards (shared controller serving DI/PRA POS/FBR POS), loop in order
`pos → fbrpos → web`. All guards share one Laravel session, so a browser logged into
two panels passes multiple `auth($guard)->check()`; keep server-side resolution and the
form's per-guard submit action in the SAME order so a proof/upload is attached to the
company of the panel it was actually submitted from.
