# TaxNest Demo General Hospital acceptance

Owner scope is #344. Use only fictional records on a fresh loopback MariaDB database. The public Healthcare registration gate stays closed. Use the existing cloud development bootstrap in docs/ops/cloud-agent-development.md. Create an empty database named taxnest_erp_demo and configure the local .env for that loopback database before running migrations. Block outbound mail, SMS and regulator traffic. Provide ERP_DEMO_PASSWORD through a private local environment variable.

Set `ERP_DEMO_ALLOW_LOCAL=1` only for this named disposable database. The seeder verifies MariaDB and rejects nonempty company/user/doctor/patient data. It does not delete or reset an existing environment. Run php artisan db:seed --class=ErpDemoHospitalSeeder. Sign in on loopback /health/login as erp.owner@example.test. The fixture opens the supported module switches, provides an owner, a fictional doctor and a fictional patient. It uses a synthetic Hospital package with an active annual term. Check trial and alternate package limits using separate test companies.

Use the real existing suites before manual acceptance

- php artisan test --filter=ErpDemoHospitalSeederTest
- php artisan test --filter=HealthPilotJourneyTest
- php artisan test --filter=HealthAuditWorkspaceTest
- php artisan test --filter=HealthRegistrationPrePilotTest
- php artisan test --filter=HealthBillingFbrTest

| Run | Evidence to capture |
| --- | --- |
| Owner, reception, clinician, cashier, pharmacy, lab, nursing, accounts and auditor | Direct route allow and deny, branch scope, tenant isolation, module OFF/ON and historic access |
| Reception to OPD and lab | Patient and visit, doctor notes, prescriptions, lab orders, results and refunds |
| Pharmacy and stock | Batch, expiry, dispense, returns, purchase, stock ledger and cost |
| Inpatient and procedures | Admission, bed allocation, transfer, bed-day, procedure/OT, nursing and discharge. Log separately any unimplemented maternity or emergency workflow |
| Financial closure | Split/partial payments, advance, credit, refund, end-of-day cash, journal balance and reversals |
| HR and operations | Attendance, payroll source, audit run, scheduler, queue, recovery and simulated backup restore |
| Fiscal | Only fake responses and authorized sandbox. Verify single dispatch, immutable amounts, retry, reconciliation, credit note and fiscal QR. Review the applicable legal and interface requirements independently before any production activation |

Report pass, fail, blocked or missing for each case. Attach safe evidence, rerun the original failure after a correction and record the final Git commit. This file and the seed do not certify ERP readiness.

This integrated source #377 is a fixture and acceptance aid only, separate from standalone Hospital POS. It does not certify full HMIS/ERP readiness or permit production seeding. CI SQLite provisioning and isolated Health feature tests are evidence only for the cases actually executed; the manual MariaDB demo checklist remains pending unless recorded separately.

## Repeatable synthetic role acceptance (follow-up to source #377)

The guarded fixture now also creates reception, doctor, cashier, pharmacy, lab,
nursing, accounts, auditor and HR users. Their email aliases are respectively
`erp.reception@example.test`, `erp.doctor@example.test`,
`erp.cashier@example.test`, `erp.pharmacy@example.test`, `erp.lab@example.test`,
`erp.nursing@example.test`, `erp.accounts@example.test`,
`erp.auditor@example.test` and `erp.hr@example.test`. They use the explicit local
`ERP_DEMO_PASSWORD`, never a committed default. The fictional doctor is linked
to the doctor login. These users are employees with the existing least-privilege
Healthcare role defaults, not company administrators. All three company FBR
switches are explicitly off. This fixture does not create standalone Hospital
POS records, production accounts or a public registration route.

Run `bash scripts/check-erp-demo-acceptance.sh`. This uses the repository's
clean-environment, loopback-only egress guard and SQLite in-memory tests. The
new fixture test signs in through the real login route for all nine roles,
opens an allowed route and rejects an unrelated direct route and module-setting
write. It also tests owner module-off denial, restored access without losing the
patient record, cross-company patient URL denial, seeder production refusal,
missing-secret refusal and refusal to reseed existing data.

The wrapper includes the existing substantive OPD/prescription/pharmacy/IPD,
accounts, attendance, audit and fiscal-fake suites. Their service/HTTP assertions
are **not** MariaDB concurrency or Chrome rendering evidence. Record actual run
counts and failures in the PR, and do not replace the pending manual checklist
above with a blanket “ERP passed.”

### Explicit remaining boundaries

- **Lab order/sample/result/refund workflow is missing:** the module/capability
  definitions exist, but this tree has no `/health/lab` route family. The lab
  login's patient access and denial of unrelated clinical/accounts actions do
  not prove a lab workflow. Clinical PDF attachments are not a lab subsystem.
- **Native MariaDB + desktop/mobile role journeys remain pending.** The current
  standalone browser fixture requires a different exact database. Do not relax
  either database guard or seed into it. Provision a separate empty
  `taxnest_erp_demo` loopback lane with the existing explicit opt-in before
  collecting native/browser evidence.
- Payroll-source/attendance tests are not a payroll engine, bank payout or HR
  production acceptance. Maternity/emergency workflows, live queue/scheduler
  operation and a simulated backup/restore drill are not established here.
- Fiscal tests use fakes only; no official route, regulator acceptance or
  production submission readiness is implied.

Local acceptance execution for this change: the wrapper completed on PHP 8.4.27
with **274 tests, 1,675 assertions, no failures, 25 PHPUnit deprecations**. This
is the combined fixture/role and existing domain suite, not the full repository
suite. The native/browser and operations gaps above remain open.

## Dedicated native and browser role lane

`bash scripts/check-erp-demo-browser.sh [absolute-playwright-cache]` is a separate
lane, not a mode of the standalone POS fixture. Required installed tools are
PHP with MySQL support, MariaDB 10.6 server/client/admin/install-db, Node with
locked Playwright dependencies, trusted Chromium and built assets. The worktree
must be clean and committed before exact-SHA pixel evidence is generated.

The wrapper starts inside `rc-safe-run`, creates paired unique private
`/tmp/taxnest-erp-demo-UID-RANDOM` and
`/tmp/taxnest-rc-mariadb-erp-demo-UID-RANDOM` roots, and uses only
`taxnest_erp_demo`. The bootstrap refuses any pre-existing table before running
migrations. Only the legacy sample identities inserted by those fresh migrations
are removed before the guarded demo seed; an existing database cannot be reset.
Mail is array-only, background dispatch synchronous, fiscal switches off and
all egress remains loopback-only. The environment is scoped to the child process;
no `.env` or application config is written. Cleanup stops the private PHP server
and MariaDB and deletes private credential JSON on success or failure.

Ten roles (owner plus nine staff) sign in through the actual UI at 1366×900 and
390×844, open their allowed screen and test an unrelated denied direct route.
The owner case instead verifies settings access. These are role access/rendering
cases, not clinical end-to-end workflows. The lab role opens the patient register
because a lab workflow itself remains absent.

Screenshots use only the guarded synthetic capture helper. The explicit
`hospital-erp-demo` fixture kind, exact database, paired socket, fresh nonce and
fixture hash are checked; a separate loopback proof endpoint binds captures to
the generated fixture. Private credentials are never evidence artifacts.
Export and visual-review requirements from `docs/qa/synthetic-pixel-artifacts.md`
still apply. The JSON result summary contains roles/statuses, not passwords.

Local execution was attempted but blocked by the host before seeding:
`Can't start server : UNIX Socket : Operation not permitted`. No native/browser
pass is claimed from that attempt. Run this lane on the approved hosted native
browser runner; do not work around the socket restriction by weakening guards.
