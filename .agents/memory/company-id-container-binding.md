---
name: Per-request company-id container binding key
description: The active company id is bound in the Laravel container under 'currentCompanyId'; 'n' is read in a few spots but never bound (always falls back).
---

# Per-request company-id container binding key

The active company id for a tenant request is bound in the service container as
`app('currentCompanyId')` — set by `CompanyIsolation`, `PosAuth`, and `FbrPosAuth`
middleware via `app()->instance('currentCompanyId', $user->company_id)`.
`PosController` and most tenant controllers/scopes read `app('currentCompanyId')`.

**Trap:** A few spots (`trial-lock-modal`, `trial-reminder-banner`,
`PaymentProofController`) read `app('n')` guarded by `app()->bound('n')`. But `'n'`
is **never bound anywhere**, so those `bound('n')` branches are effectively dead —
they always use their fallback (`auth()->user()->company_id ?? null`, or the
write-path guard loop). There is likewise no `'ln'` binding.

**Rule:** To resolve the active company in Blade/controllers within a tenant request,
use `app()->bound('currentCompanyId') ? app('currentCompanyId') : (auth()->user()->company_id ?? null)`.

**Why:** Earlier code guessed wrong keys (`'ln'`, then `'n'`) that are not bound, so the
container read silently returned null and the feature relied entirely on the fallback.
Always confirm the binding key before reading from the container.

**How to apply:** Admin routes do NOT run CompanyIsolation, so `currentCompanyId` is
unbound there (intended — admins are cross-tenant); guard with `bound()` first.

**Write-path fallback order:** when the key is unbound and you must pick a company from
auth guards (shared controller serving DI/PRA POS/FBR POS), loop in order
`pos → fbrpos → web`. All guards share one Laravel session, so keep server-side
resolution and the form's per-guard submit action in the SAME order.
