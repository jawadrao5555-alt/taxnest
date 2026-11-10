# TaxNest Demo General Hospital acceptance

Owner scope is #344. Use only fictional records on a fresh loopback MariaDB database. The public Healthcare registration gate stays closed. Use the existing cloud development bootstrap in docs/ops/cloud-agent-development.md. Create an empty database named taxnest_erp_demo and configure the local .env for that loopback database before running migrations. Block outbound mail, SMS and regulator traffic. Provide ERP_DEMO_PASSWORD through a private local environment variable.

Run php artisan db:seed --class=ErpDemoHospitalSeeder. Sign in on loopback /health/login as erp.owner@example.test. The fixture opens the supported module switches, provides an owner, a fictional doctor and a fictional patient. It uses a synthetic Hospital package with an active annual term. Check trial and alternate package limits using separate test companies.

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
