# 06 — As Company (Manage as Company)

## Naming clarification

The mission asked for “As Company.”

**FACT — actual UI label:** “Manage as Company”  
**FACT — mode:** `mode=full` → `readonly=false`  
**FACT — audit:** `Started manage-as (FULL ACCESS)`  
**Confirm copy:** changes are **REAL**, including live FBR/PRA submissions.

This is the **write-enabled** twin of View as Company. Same route/controller; different `mode`.

## Entry

Same endpoint as View:

```text
POST /admin/companies/{id}/impersonate
Body: mode=full
```

UI form in `saas-admin/companies/show.blade.php`.

## Behavioral difference vs View

| Concern | View (`mode=view`) | Manage / As (`mode=full`) |
|---------|--------------------|---------------------------|
| Panel writes | Blocked | **Allowed** |
| Identity swap | Blocked | Blocked |
| `/admin/*` | Allowed | Allowed |
| Write audit | N/A | Every successful non-admin write → `AdminAuditLog` via `LogImpersonatedWrites` |
| Banner | Amber view-only | Red LIVE + **Lock to view-only** button |

## Lock to view-only (without exiting)

| Route | `POST /admin/impersonation/lock` |
| Method | `AdminCompanyController@lockImpersonation` |
| Effect | Sets `mode=view`, `readonly=true`; audit `Locked view-as to read-only`; stays in panel |

**FACT:** Lock only tightens access — never upgrades view→full from banner.

## Exit

Same `stopImpersonation` as View. Audit wording is still `Stopped view-as` even when leaving a full-access session (**FACT** — slight naming inconsistency; behavior is correct).

## Side effects skipped while admin is present

**FACT:** `AppServiceProvider` Login listener skips `last_login_at` / hazri attendance inserts when `Auth::guard('admin')->check()` — so impersonation login does not pollute staff attendance.

## Danger model

**FACT:** Manage-as is intentionally dangerous: settings changes and fiscal submissions are real. Prefer View unless mutation is required. Always Exit when done.

## Contrast: Consultant switch (NOT this)

Consultant “switch into client” uses session key `consultant_console`, replaces identity on `web` guard, and grants full client-admin writes without the readonly flag. Different system — see `07-authentication.md`.
