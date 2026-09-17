# Release-candidate files by phase

**Baseline:** `0677abbc3fec912b3011dd6449be6a17263db84d` (audited remote
`main`; not an assertion of the current production SHA)
**Code/tooling checkpoint:** `75466819e4b5bcefff37b28fbd51d8f8d0025721`
**Last pre-loss checkpoint:** `93de2ccf` (historical reference only; not current head)
**Held workflow state:** full build workflow recovered and held local-only by
scope; actual `pr-checks` remains baseline
**Report-only handoff:** this inventory, final report, category matrix and
traceability ledger; held workflow self-hash intentionally omitted
**Worktree:** `/tmp/taxnest-maturity-rc`
**Recovery window:** temporary worktree/raw logs lost 2026-09-16 11:04–11:06 UTC

This inventory is baseline-relative to audited remote `main` and the recovered
code/tooling checkpoint. It lists actual source, docs, tests, migrations and
deletion filenames only; generated CSS text is included, while generated
binaries, hosted archives, secrets, temporary QA JSON, `vendor/`,
`node_modules/`, production data, deleted sensitive contents and raw Git diffs
are excluded from delivered contents. Unrecovered or held paths are listed
separately below and are not delivered. No new production read was performed.

## Phase A — baseline, findings and traceability

| Recovery state | Files |
|---|---|
| Retained documentation scope | `docs/release-candidate/README.md`; `docs/release-candidate/traceability.md`; `docs/release-candidate/category-matrix.md`; `docs/release-candidate/final-report.md`; `docs/release-candidate/files-by-phase.md` |

## Phase B — security and dependency hardening

| Recovery state | Files |
|---|---|
| Historical retained paths | `composer.lock`; `package.json`; `package-lock.json`; `pra-agent/package.json`; `pra-agent/package-lock.json`; `artifacts/mockup-sandbox/package-lock.json`; `.gitignore`; `app/Http/Controllers/FbrPosController.php`; `app/Http/Controllers/PosController.php`; `app/Services/FbrService.php`; `app/Services/PraIntegrationService.php` |
| Historical checks/evidence | `scripts/verify-repository-artifacts.sh`; `scripts/ops/repository-artifact-guard.sh`; `tests/Feature/FbrPosDayCloseUndispatchedDeliveryTest.php`; `tests/Feature/PraSubmitIdempotencyTest.php`; `tests/Unit/RepositoryArtifactGuardTest.php`; `docs/release-candidate/phase-b-security-and-artifacts.md`; `docs/release-candidate/phase-b-security-evidence.json` |

## Phase C — Hotel canonical-category hotfix

| Recovery state | Files |
|---|---|
| Historical retained paths | `app/Services/HotelShell.php`; `tests/Feature/HotelCategoryNativeUiTest.php`; `resources/views/layouts/pos-app.blade.php`; `resources/views/partials/impersonation-banner.blade.php`; `docs/release-candidate/phase-c-h-hotel-service-workflows.md` |
| Current acceptance | First browser pass `9` failures retained; corrected rerun unconfirmed after loss |

## Phase D — DI F01–F11

| Recovery state | Files |
|---|---|
| Historical source | `app/Services/DiFiscalSubmissionState.php`; `app/Services/FbrService.php`; `app/Services/IntegrityHashService.php`; `app/Http/Controllers/InvoiceController.php`; `app/Http/Controllers/Api/DiInvoiceApiController.php`; `app/Jobs/BulkSubmitInvoiceJob.php`; `app/Jobs/RetryFailedFbrInvoicesJob.php`; `app/Jobs/SeedBulkSubmitBatchJob.php`; `app/Jobs/SendInvoiceToFbrJob.php`; `app/Models/Invoice.php`; `config/queue.php`; `resources/views/invoice/index.blade.php`; `resources/views/invoice/show.blade.php` |
| Historical migrations/tests | `database/migrations/2026_09_16_120000_harden_di_fiscal_submission_state.php`; `database/migrations/2026_09_16_130000_add_di_bulk_submission_outbox.php`; `tests/Feature/DiFiscalSubmissionStateTest.php`; `tests/Feature/InvoiceBulkSubmitTest.php`; `tests/Unit/DiFiscalQueueTimeoutContractTest.php`; `docs/release-candidate/di-f01-f11.md` |

## Phase E — agents, printing, offline and release provenance

| Recovery state | Files |
|---|---|
| Historical retained source | `app/Services/AgentReleaseManifest.php`; `app/Http/Controllers/AgentController.php`; `app/Http/Controllers/AgentManagementController.php`; `app/Http/Controllers/PosController.php`; `config/services.php`; `resources/views/downloads.blade.php`; `resources/views/saas-admin/companies/show.blade.php`; `pra-agent/main.js`; `pra-agent/src/agent.js`; `pra-agent/src/callback-retry-policy.js`; `pra-agent/src/heartbeat-diagnostics.js`; `pra-agent/src/release-manifest.js` |
| Historical tests/checks | `tests/Feature/AgentHeartbeatUpdateTelemetryClearTest.php`; `tests/Feature/AgentReleaseAvailabilityTest.php`; `tests/Unit/AgentReleaseManifestTest.php`; `pra-agent/test/callback-retry-policy.test.js`; `pra-agent/test/heartbeat-diagnostics.test.js`; `pra-agent/test/release-manifest.test.js`; `scripts/android-release-provenance-check.sh`; `scripts/apk-release-check.sh`; `scripts/tests/android-release-provenance-check-test.sh`; `scripts/tests/agent-release-chain-check.sh`; `docs/release-candidate/phase-e-agent-pra.md` |
| Latest retained regression | `PosDayCloseAutoFinalizeTest`: separate clean-env `22/168` pass at `10:58:17`; no other agent changes reconstructed |

## Phase F — database integrity and MariaDB

| Recovery state | Files |
|---|---|
| Historical harness paths | `scripts/rc-mariadb-lab.sh`; `scripts/rc-mariadb-labs.sh`; `scripts/rc-mariadb-migration-lab.sh`; `scripts/tests/di-fiscal-mariadb-check.php`; `scripts/tests/di-fiscal-mariadb-check.sh`; `scripts/tests/di-fiscal-mariadb-claim-worker.php`; `tests/native/rc_mariadb_schema.php`; `tests/native/rc_mariadb_concurrency.php` |
| Historical evidence | `docs/release-candidate/database.md` |
| Recovery status | Restore result retained as observed at 10:28:27; latest worker full `541` migrations exit `0`; schema-only 482-prefix→541 exit `0` (not data-bearing proof); generic lock/fiscal claim, seven skipped cases `6/23 + 1/4`, category-native lab and all proposed-SQL `EXPLAIN` checks exit `0`; DI bulk race unexecuted; raw logs lost; retained migration fix uses `expires_at DATETIME` with a short index |

## Phase G — CI and release-confidence gates

| Recovery state | Files |
|---|---|
| Historical safe-run/network/CI paths | `scripts/rc-safe-run`; `scripts/rc-syntax-check.sh`; `scripts/rc-network-build.sh`; `scripts/rc-network-guard.c`; `scripts/rc-network-probe.php`; `scripts/rc-network-verify.sh`; `scripts/rc-test-report.py`; `scripts/tests/rc-ci-gates-check.sh` |
| Historical browser/fixture paths | `scripts/rc-browser-acceptance.mjs`; `scripts/rc-browser-fixture-resume.php`; `scripts/rc-browser-fixture-seed.php`; `scripts/rc-browser-fixture.sh`; `scripts/rc-di-browser-fixture.php`; `scripts/tests/rc-browser-acceptance-check.sh` |
| Historical CI/workflow paths | `.github/workflows/pr-checks.yml`; `.github/workflows/build-agent.yml`; `tests/TestCase.php`; `docs/release-candidate/proposed-workflows/build-agent.yml`; `docs/release-candidate/proposed-workflows/pr-checks.yml`; `docs/release-candidate/proposed-workflows/README.md`; `docs/release-candidate/ci.md` |
| Recovery status | Actual `.github` changes and uncommitted helpers are not claimed restored; first browser `9` failures retained, corrected rerun unconfirmed |

The synthetic fixture JSON is deliberately omitted as temporary QA JSON. Its
historical corrected state is reported in `final-report.md`; omission is not an
instruction to delete it.

## Phase H — category-native engines and service workflows

| Recovery state | Files |
|---|---|
| Historical retained source | `app/Services/PosCategoryProfiles.php`; `app/Services/PosServiceWorkflowProfiles.php`; `app/Services/PosServiceWorkOrderService.php`; `app/Http/Controllers/PosServiceWorkOrderController.php`; `resources/views/pos/service-work-orders/create.blade.php`; `resources/views/pos/service-work-orders/index.blade.php`; `resources/views/pos/service-work-orders/show.blade.php` |
| Historical tests/evidence | `tests/Unit/PosCategoryProfilesTest.php`; `tests/Feature/PosServiceWorkOrderTest.php`; `tests/Feature/PosServiceWorkOrderInvoiceTest.php`; `tests/Feature/PosServiceWorkOrderNativeUiTest.php`; `docs/release-candidate/category-matrix.md` |

## Phase I — Healthcare/Hospital

| Recovery state | Files |
|---|---|
| Historical retained source | `app/Http/Controllers/Health/HealthBillingController.php`; `app/Http/Controllers/Health/HealthClinicalController.php`; `app/Http/Controllers/Health/HealthOperationController.php`; `app/Http/Controllers/Health/HealthPanelController.php`; `app/Http/Middleware/HealthSubscriptionLock.php` |
| Historical tests/evidence | `tests/Feature/HealthAccountsSettlementsTest.php`; `tests/Feature/HealthcareFoundationTest.php`; `tests/Feature/HealthIpdOperationsTest.php`; `tests/Feature/HealthOpdCoreTest.php`; `tests/Feature/HealthPharmacyBranchAccessTest.php`; `tests/Feature/HealthSubscriptionLockTest.php`; `docs/release-candidate/health-hospital.md` |
| Coverage note | Historical Health `416/2261` includes HR tests; no-hospital-pilot coverage is separate |

## Phase J — backup, recovery and operations

| Recovery state | Files |
|---|---|
| Historical operations paths | `scripts/ops/local-encrypted-backup-rehearsal.sh`; `scripts/ops/local-mariadb-recovery-rehearsal.sh`; `scripts/ops/production-backup-restore.sh`; `scripts/ops/production-permissions-hardening.sh`; `scripts/ops/monitoring-readiness-guard.sh`; `scripts/tests/release-candidate-operations-check.sh`; `docs/ops/release-candidate-operations.md`; `docs/release-candidate/operations-artifacts.md` |
| Recovery status | Native encrypted restore `10:28:27` result retained; raw logs unavailable; no production restore |

## Phase K — repository and artifact reconciliation

| Recovery state | Files |
|---|---|
| Historical guard paths | `scripts/ops/branch-equivalence-inventory.sh`; `scripts/ops/repository-artifact-guard.sh`; `scripts/verify-repository-artifacts.sh`; `.gitignore`; `docs/release-manifests/artifact-inventory.json` |
| Recovery status | Historical artifact fix and suite exit-0 observation retained; current guard/lint not rerun |

The artifact inventory is documentation metadata, not customer or QA fixture
data. Generated binaries, sensitive/deleted contents and raw logs remain
excluded.

## Baseline-relative actual-path inventory

The following filenames are the actual baseline-relative source, tooling,
documentation, tests and migrations represented by the recovered checkpoint.
The generated CSS file is text and is included; generated binaries are not.

### Application, configuration, dependencies and migrations

`app/Http/Controllers/AgentController.php`; `app/Http/Controllers/AgentManagementController.php`;
`app/Http/Controllers/Api/DiInvoiceApiController.php`;
`app/Http/Controllers/FbrPosController.php`;
`app/Http/Controllers/Health/HealthBillingController.php`;
`app/Http/Controllers/Health/HealthClinicalController.php`;
`app/Http/Controllers/Health/HealthOperationController.php`;
`app/Http/Controllers/Health/HealthPanelController.php`;
`app/Http/Controllers/InvoiceController.php`;
`app/Http/Controllers/PosController.php`;
`app/Http/Controllers/PosServiceWorkOrderController.php`;
`app/Http/Middleware/HealthSubscriptionLock.php`;
`app/Jobs/BulkSubmitInvoiceJob.php`;
`app/Jobs/RetryFailedFbrInvoicesJob.php`;
`app/Jobs/SeedBulkSubmitBatchJob.php`;
`app/Jobs/SendInvoiceToFbrJob.php`;
`app/Models/Invoice.php`;
`app/Services/AgentReleaseManifest.php`;
`app/Services/DiFiscalSubmissionState.php`;
`app/Services/FbrService.php`;
`app/Services/HotelShell.php`;
`app/Services/IntegrityHashService.php`;
`app/Services/PosCategoryProfiles.php`;
`app/Services/PosServiceWorkOrderService.php`;
`app/Services/PosServiceWorkflowProfiles.php`;
`app/Services/PraIntegrationService.php`;
`artifacts/mockup-sandbox/package-lock.json`; `.gitignore`; `composer.lock`;
`config/queue.php`; `config/services.php`;
`database/migrations/2026_09_16_120000_harden_di_fiscal_submission_state.php`;
`database/migrations/2026_09_16_130000_add_di_bulk_submission_outbox.php`;
`database/migrations/2026_12_01_000000_create_owner_deployment_approval_requests.php`;
`package.json`; `package-lock.json`; `pra-agent/package.json`;
`pra-agent/package-lock.json`; `pra-agent/main.js`;
`pra-agent/src/agent.js`; `pra-agent/src/callback-retry-policy.js`;
`pra-agent/src/heartbeat-diagnostics.js`; `pra-agent/src/release-manifest.js`.

### Documentation and proposal files

`docs/ops/release-candidate-operations.md`;
`docs/release-candidate/README.md`;
`docs/release-candidate/category-matrix.md`;
`docs/release-candidate/ci.md`;
`docs/release-candidate/database.md`;
`docs/release-candidate/di-f01-f11.md`;
`docs/release-candidate/evidence-observed.json`;
`docs/release-candidate/files-by-phase.md`;
`docs/release-candidate/final-report.md`;
`docs/release-candidate/health-hospital.md`;
`docs/release-candidate/operations-artifacts.md`;
`docs/release-candidate/phase-b-security-and-artifacts.md`;
`docs/release-candidate/phase-b-security-evidence.json`;
`docs/release-candidate/phase-c-h-hotel-service-workflows.md`;
`docs/release-candidate/phase-e-agent-pra.md`;
`docs/release-candidate/proposed-workflows/build-agent.yml`;
`docs/release-candidate/proposed-workflows/pr-checks.yml`;
`docs/release-candidate/traceability.md`;
`docs/release-manifests/artifact-inventory.json`.

### Views, scripts and generated CSS text

`resources/views/downloads.blade.php`; `resources/views/invoice/index.blade.php`;
`resources/views/invoice/show.blade.php`; `resources/views/layouts/pos-app.blade.php`;
`resources/views/partials/impersonation-banner.blade.php`;
`resources/views/pos/service-work-orders/create.blade.php`;
`resources/views/pos/service-work-orders/index.blade.php`;
`resources/views/pos/service-work-orders/show.blade.php`;
`resources/views/saas-admin/companies/show.blade.php`;
`public/build/assets/app-Cru4vlSd.css`; `public/build/manifest.json`;
`scripts/android-release-provenance-check.sh`; `scripts/apk-release-check.sh`;
`scripts/ops/branch-equivalence-inventory.sh`;
`scripts/ops/local-encrypted-backup-rehearsal.sh`;
`scripts/ops/local-mariadb-recovery-rehearsal.sh`;
`scripts/ops/monitoring-readiness-guard.sh`;
`scripts/ops/production-backup-restore.sh`;
`scripts/ops/production-permissions-hardening.sh`;
`scripts/ops/repository-artifact-guard.sh`;
`scripts/rc-browser-acceptance.mjs`; `scripts/rc-browser-fixture-resume.php`;
`scripts/rc-browser-fixture-seed.php`; `scripts/rc-browser-fixture.sh`;
`scripts/rc-di-browser-fixture.php`; `scripts/rc-mariadb-lab.sh`;
`scripts/rc-mariadb-migration-lab.sh`; `scripts/rc-network-build.sh`;
`scripts/rc-network-guard.c`; `scripts/rc-safe-run`;
`scripts/tests/agent-release-chain-check.sh`;
`scripts/tests/android-release-provenance-check-test.sh`;
`scripts/tests/di-fiscal-mariadb-check.php`;
`scripts/tests/di-fiscal-mariadb-check.sh`;
`scripts/tests/rc-ci-gates-check.sh`;
`scripts/tests/rc-network-guard-check.py`;
`scripts/tests/release-candidate-operations-check.sh`;
`scripts/verify-repository-artifacts.sh`.

### Tests

`pra-agent/test/callback-retry-policy.test.js`;
`pra-agent/test/heartbeat-diagnostics.test.js`;
`pra-agent/test/release-manifest.test.js`;
`tests/Feature/AgentHeartbeatUpdateTelemetryClearTest.php`;
`tests/Feature/AgentReleaseAvailabilityTest.php`;
`tests/Feature/DiFiscalSubmissionStateTest.php`;
`tests/Feature/FbrPosDayCloseUndispatchedDeliveryTest.php`;
`tests/Feature/FbrPosKotReprintPermissionTest.php`;
`tests/Feature/HealthAccountsSettlementsTest.php`;
`tests/Feature/HealthIpdOperationsTest.php`;
`tests/Feature/HealthOpdCoreTest.php`;
`tests/Feature/HealthPharmacyBranchAccessTest.php`;
`tests/Feature/HealthSubscriptionLockTest.php`;
`tests/Feature/HealthcareFoundationTest.php`;
`tests/Feature/HotelCategoryNativeUiTest.php`;
`tests/Feature/InvoiceBulkSubmitTest.php`;
`tests/Feature/PosDayCloseAutoFinalizeTest.php`;
`tests/Feature/PosServiceWorkOrderInvoiceTest.php`;
`tests/Feature/PosServiceWorkOrderNativeUiTest.php`;
`tests/Feature/PosServiceWorkOrderTest.php`;
`tests/Feature/PraSubmitIdempotencyTest.php`; `tests/TestCase.php`;
`tests/Unit/AgentReleaseManifestTest.php`;
`tests/Unit/DiFiscalQueueTimeoutContractTest.php`;
`tests/Unit/PosCategoryProfilesTest.php`;
`tests/Unit/RepositoryArtifactGuardTest.php`;
`tests/native/rc_mariadb_concurrency.php`; `tests/native/rc_mariadb_schema.php`.

### Deleted filenames (names only; contents are not delivered)

`caller-app/TaxNest-PRA-Agent-Windows.zip`; `cookies.txt`;
`database/deploy/2026-04-24-production-sync/00-PREFLIGHT.sql`;
`database/deploy/2026-04-24-production-sync/01a-punjab-plus-company.sql`;
`database/deploy/2026-04-24-production-sync/01b-punjab-plus-user.sql`;
`database/deploy/2026-04-24-production-sync/01c-punjab-plus-branch.sql`;
`database/deploy/2026-04-24-production-sync/01d-punjab-plus-subscription.sql`;
`database/deploy/2026-04-24-production-sync/02-SCHEMA-ALIGN.sql`;
`database/deploy/2026-04-24-production-sync/03-zia-invoices.sql`;
`database/deploy/2026-04-24-production-sync/04-zia-invoice-items.sql`;
`database/deploy/2026-04-24-production-sync/99-SCHEMA-FIXES.sql`;
`database/deploy/2026-04-24-production-sync/999-VERIFY.sql`;
`database/deploy/2026-04-24-production-sync/MASTER-ALL.sql`;
`database/deploy/2026-04-24-production-sync/README.md`;
`database/deploy/2026-04-24-production-sync/RUN-ALL.sql`;
`database/deploy/2026-04-24-production-sync/run-migration.sh`;
`database/production_data_export.sql`; `fbr_retry.php`; `fbr_retry_log.txt`;
`fbr_retry_loop.sh`; `fbr_single_try.php`; `generate-annex-invoices.php`;
`generate-full-demo-invoice.php`; `generate-nisar-invoices.php`;
`generate-sample-modern-di.php`; `public/build/assets/app-CcoWHOiK.css`;
`public/downloads/TaxNest-PRA-Agent-Windows.zip`; `routes-update.zip`.

## Unrecovered, held or explicitly not delivered

- Raw full-suite/JUnit logs are unavailable; committed
  [`evidence-observed.json`](evidence-observed.json) is the observation record.
- Fresh browser-seed execution is **BLOCKED** by missing original harness
  segments. Native DI, category-native and skipped-case verification modes are
  also **BLOCKED** after loss; source paths above do not imply executable
  evidence.
- Later DI bulk-result race output was not executed and is not delivered.
- Actual `.github/workflows/build-agent.yml` is recovered but held local-only
  by scope; actual `.github/workflows/pr-checks.yml` remains baseline.
- `.env.testing` and production/customer-data paths are excluded; temporary QA
  JSON, generated binaries, raw logs and secret values are not delivered.

## Verification and delivery boundary

The historical final suite was observed as exit `0`, but the recreated
worktree/harness is not recertified. Final acceptance depends on recovery
commit review, source-lint if available, fresh authorized native/browser
evidence, workflow review/authorization and final SHA.

All production queries, migrations, restores, permission changes, secret
operations, fiscal submissions, deployments, merges, releases, remote cleanup
and any further push beyond retained `93de2ccf` remain:

**PROPOSED — NOT EXECUTED — OWNER APPROVAL REQUIRED**