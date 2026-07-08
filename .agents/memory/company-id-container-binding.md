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

**app() second-arg trap:** `app('currentCompanyId', null)` is a fatal TypeError — the
2nd parameter of `app()` must be an ARRAY (it's constructor $parameters, not a default
value). Any code path that reaches it 500s (this silently broke admin company-approve:
status saved, then audit-log write crashed the response). There is no "default value"
form of app(); always use `app()->bound('key') ? app('key') : $fallback`.

**isset-null container trap:** `app()->instance('key', null)` SILENTLY does nothing —
Container instances/bound() use `isset()`, so a null instance is invisible and
`app('key')` still throws `Target class [key] does not exist`. To bind a null value,
use `app()->bind('key', fn() => null)` (bindings array entry is non-null, so isset works).
CompanyIsolation now binds `currentCompanyId => null` this way for web-guard super_admins
without a company_id, so `CheckCompanyApproval` and friends no longer 500 on
/dashboard, /tax-overrides etc. Same latent pattern exists in BranchContextService
(`instance('currentBranchId', ...)`) — harmless today because all consumers guard with
`bound()`, but use closure bind if touching it.

**Write-path fallback order:** when the key is unbound and you must pick a company from
auth guards (shared controller serving DI/PRA POS/FBR POS), loop in order
`pos → fbrpos → web`. All guards share one Laravel session, so keep server-side
resolution and the form's per-guard submit action in the SAME order.
