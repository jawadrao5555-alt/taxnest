# Health / Hospital Release-Candidate Hardening Record

- Scope: Nest ERPS Health/Hospital only. This does not cover the separate `clinic` POS category.
- UTC window: 2026-09-16T09:50:15Z to 2026-09-16T10:24:12Z.
- Baseline supplied for this review: `0677abbc`.

## Changes made

| Area | Change | Boundary protected |
|---|---|---|
| Shared Health controller guards | `requireBranch()` and `requireDepartment()` now first establish that a posted id belongs to the active company, then apply the actor scope. | Cross-company branch/department ID injection, including an administrative user whose scope otherwise covers all branches/departments. |
| Subscription money-write lock | Missing company context, a missing `subscriptions` table, or an unresolved company now deny non-read requests rather than passing them through. | Charges, bills, payments, IPD money actions, and pharmacy money writes cannot proceed when subscription access cannot be verified. |
| Clinical attachments | Attachment delete now enforces the visit branch boundary before clinical-write permission; attachment download uses the shared tenant-aware branch guard. | Same-company cross-branch deletion/read of clinical attachments. |
| Billing day-close | A requested `branch_id` must pass the shared branch guard before reports are computed. | Cross-company/cross-branch day-close disclosure through a query parameter. |
| Billing day-close default | A non-administrative user who omits `branch_id` is constrained to their one reachable branch; a user with zero or multiple reachable branches must choose explicitly. Only an administrative `NULL` boundary retains the company-wide report. | The absence of a filter can no longer turn a branch-limited report into a company-wide disclosure. |
| Operations | Schedule/reschedule references are resolved to the current company and actor scope; selected doctors, procedures, theatres, branches, and departments are checked. Catalogue, operation detail pickers, and procedure mutation paths apply their relevant scope. Team member doctor ids are also checked. | Cross-company and cross-branch/department operation scheduling, staffing, and catalogue IDOR. |

Files changed for this scope:

- `app/Http/Controllers/Health/HealthPanelController.php`
- `app/Http/Controllers/Health/HealthOperationController.php`
- `app/Http/Controllers/Health/HealthClinicalController.php`
- `app/Http/Controllers/Health/HealthBillingController.php`
- `app/Http/Middleware/HealthSubscriptionLock.php`
- `tests/Feature/HealthIpdOperationsTest.php`
- `tests/Feature/HealthSubscriptionLockTest.php`
- `tests/Feature/HealthOpdCoreTest.php`
- `tests/Feature/HealthPharmacyBranchAccessTest.php`
- `tests/Feature/HealthAccountsSettlementsTest.php`
- `tests/Feature/HealthcareFoundationTest.php`

## Verification evidence

Commands run locally in the isolated worktree, with exit status:

```text
php -l app/Http/Controllers/Health/HealthPanelController.php &&
php -l app/Http/Controllers/Health/HealthOperationController.php &&
php -l app/Http/Controllers/Health/HealthClinicalController.php &&
php -l app/Http/Controllers/Health/HealthBillingController.php &&
php -l app/Http/Middleware/HealthSubscriptionLock.php &&
php -l tests/Feature/HealthSubscriptionLockTest.php &&
php -l tests/Feature/HealthIpdOperationsTest.php
# exit 0

php vendor/bin/phpunit tests/Feature/HealthSubscriptionLockTest.php tests/Feature/HealthIpdOperationsTest.php --testdox
# exit 0; first run: 47 tests, 171 assertions

php -l tests/Feature/HealthIpdOperationsTest.php &&
php vendor/bin/phpunit tests/Feature/HealthSubscriptionLockTest.php tests/Feature/HealthIpdOperationsTest.php --testdox &&
git diff --check
# exit 0; final run: 48 tests, 173 assertions

php vendor/bin/phpunit tests/Feature/HealthOpdCoreTest.php tests/Feature/HealthPharmacyBranchAccessTest.php tests/Feature/HealthAccountsSettlementsTest.php --testdox
# exit 0; 72 tests, 646 assertions

php vendor/bin/phpunit tests/Feature/HealthAccountsSettlementsTest.php --testdox
# exit 0; 51 tests, 560 assertions

php vendor/bin/phpunit tests/Feature/HealthcareFoundationTest.php tests/Feature/HealthAccountsSettlementsTest.php --testdox
# exit 0; 88 tests, 738 assertions

env -i PATH="$PATH" HOME="$HOME" APP_ENV=testing php vendor/bin/phpunit tests/Feature/Health*.php tests/Feature/Healthcare*.php --testdox
# exit 0; final run: 416 tests, 2,261 assertions; PHPUnit reported 25 deprecations and no test failures

git diff --check
# exit 0
```

Focused additions prove that a money write denies when company context is absent or cannot resolve, that a foreign hospital's theatre id cannot be scheduled, and that a doctor assigned to one branch cannot delete an attachment belonging to another branch. The additional access tests prove that a non-treating doctor cannot read or alter another patient's confidential visit, a pharmacist cannot alter another hospital's lot, and an accountant cannot open another hospital's journal by id. Existing tests in the same focused run also exercised IPD lifecycle, payments, operation locking, package charging, module capability separation, and a cross-branch bed write refusal.

## Pilot/readiness suite references

- `HealthPilotJourneyTest` exercises an OPD-to-pharmacy-to-billing/payment journey, IPD admission/operation/discharge, doctor-share accrual, accounting balance, HR attendance, audit execution, and two-hospital data isolation.
- `HealthPilotSecurityTest` covers cross-company patient reads/writes, inactive members, payroll export capability, setup importer ownership, module-off route denial, and guard separation.
- `HealthPilotReadinessTest` exercises `health:pilot-readiness --company=<id>` against both ready and deliberately unready fixtures. The command checks schema tables, queue/scheduler/bed-day activity, import storage, translations, company approval/product/modules, active owner/staff, and accounting readiness. It is documented in `app/Console/Commands/HealthPilotReadiness.php` as read-only.

## Branch-authority policy

`HealthScopeService::branchIdsFor()` deliberately retains the pre-existing compatibility default for a non-administrative staff record with no `branch_user` rows: it resolves that user to the active head office (or first active branch). This is documented in the service and aligns with the platform `BranchContextService` auto-selection behavior; it is not changed here. An explicit `branch_user` assignment remains authoritative and is already covered by the branch-scoped tests.

The day-close report uses a different decision: no supplied `branch_id` previously meant no SQL branch predicate. For an administrative account, that is the authorised company-wide view. For a non-administrative account, it is now constrained to its sole reachable branch (including the established legacy fallback), and an ambiguous or empty non-administrative boundary is refused until a branch is explicitly selected. A supplied branch id still uses the existing tenant-aware `requireBranch()` gate.

## Synthetic browser-fixture handoff

Use only an isolated test database and synthetic identities; do not use a real hospital, patient, or operational credential.

1. Create an approved, active hospital company under `NestErps::PRODUCT_TYPE` with `NestErps::VERTICAL_COLUMN = NestErps::HEALTH`, `health_org_type = hospital`, and enabled modules `opd`, `pharmacy`, `ipd`, `lab`, `accounts`, `hr`. Create an active plan/subscription whose `health_modules` contains the same modules.
2. Create an active `health_owner` (`role = company_admin`) for setup and broad navigation. Create active `health_doctor`, `health_nurse`, `health_receptionist`, `health_pharmacist`, and `health_accountant` users for capability-negative browser assertions. Synthetic test fixtures use `Passw0rd!2026`; fixture builders may choose a different non-production test-only password.
3. Link the doctor user to an active `HealthDoctor`; create two active branches and attach the pharmacist/accountant to only Branch A through `branch_user`. Seed an active ward, room, available bed, theatre, medicine, and stock batch at Branch A. Keep a separate Branch B record for denied-ID scenarios.
4. Seed a patient/visit owned by the doctor and a second confidential patient/visit owned by that doctor. A second doctor must receive 403 for the confidential visit URL and notes POST; the treating doctor can open it. Create a second hospital with its own batch/journal/visit solely for company-boundary denial checks.
5. Browser scenarios should use `/health/login`, then verify: owner dashboard and module navigation; receptionist cannot open clinical notes; accountant cannot open clinical routes; Branch-A pharmacist cannot alter Branch-B or foreign-company stock; disabled modules deny their URLs; and an inactive Health user cannot retain panel access.

## Healthcare readiness gate status

| Domain | Status | Evidence / limitation |
|---|---|---|
| Health authentication and product isolation | Partial | Existing Health guard and focused authorization paths were reviewed; no live SSO or future-vertical integration exercise was performed. |
| Company, branch, and department isolation | Improved / partial | Shared tenant-aware branch/department guards and focused cross-branch attachment test passed. Broader controller matrix remains for the main release test owner. |
| Patient identity, search, and confidentiality | Partial | Full clean-environment Health suite passed; focused regression proves a non-treating doctor cannot view or write another patient's confidential visit. No browser workflow was run. |
| OPD and clinical permissions | Partial | Full clean-environment Health suite passed, including the confidentiality and capability regressions. Browser workflow was not run. |
| IPD, procedures, and theatre safety | Partial | 38 `HealthIpdOperationsTest` cases passed, including operation exclusivity, lifecycle protections, and a foreign-theatre HTTP IDOR refusal. |
| Pharmacy and stock | Partial | Full clean-environment Health suite passed; focused regressions cover foreign-company batch denial, cross-branch stock/sale/prescription boundaries, and prescription-line ownership. |
| Billing, refunds, day-close, and panels | Partial | Subscription fail-closed behavior passed. Day-close branch guard was implemented but needs a dedicated report-parameter authorization test. |
| Accounting and doctor settlements | Partial | Full clean-environment Health suite passed; focused regression confirms a foreign journal id redirects to the Health dashboard rather than disclosing lines. |
| HR and attendance | Unverified in this task | No focused execution performed here. |
| Audit trail and clinical redaction | Partial | Attachment access is strengthened; audit export/redaction was not re-executed. |
| FBR / fiscal integration | Unverified — mandatory external decision gate | No sandbox/live credentials, endpoint exercise, registration confirmation, or regulatory acceptance evidence was available or attempted. |
| Backup, restore, monitoring, incident response, device/printer workflow | Unverified — mandatory operational gates | No real deployment, backup/restore drill, alerting, hardware, or browser/device workflow evidence was available. |

## Release statement

This record does **not** certify Health/Hospital as production-ready, certified, or compliant. It records source-level hardening and focused local test evidence only. Mandatory external, operational, browser, deployment, database-engine, FBR, and healthcare governance gates remain to be evidenced by the release owner before any such claim.