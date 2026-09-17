# Release-candidate database evidence — recovery record

**Verdict: NOT READY.** This file was reconstructed after the authorized
temporary worktree and its disposable raw logs were removed between
2026-09-16T11:04Z and 11:06Z. Commit `93de2ccf` restored committed source, but
the recovered harness below has **not** been recertified. The execution
results in this document are historical observations, not newly available log
artifacts.

## Safety boundary

All historical database work used only
`/tmp/taxnest-rc-mariadb-1000`, socket
`/tmp/taxnest-rc-mariadb-1000/run/mariadb.sock`, TCP loopback port `33116`,
and MariaDB `10.6.22`. It did not access production, staging port `9000`, the
main browser database on `33117`, `.env`, external fiscal endpoints, deploys,
or shared workflow configuration. The server is disposable and owned only by
`scripts/rc-mariadb-lab.sh`; the control script rejects roots outside
`/tmp/taxnest-rc-mariadb-*`, port `9000`, and port `33117`.

MariaDB 10.6.22 is a compatibility limitation: production's expected 10.11
must still receive owner-approved review. The historical representative upgrade
was schema-only because no sanitized pre-Hotel/pre-category/pre-DI data fixture
exists; `database/production_data_export.sql` was deliberately not imported.

## Recovered commands

```bash
# Do not run until a reviewer authorizes recertification.
bash scripts/rc-mariadb-migration-lab.sh --full
bash scripts/rc-mariadb-migration-lab.sh --upgrade
bash scripts/rc-mariadb-migration-lab.sh --concurrency

# Historical isolated targeted test invocation (explicit values are passed
# inside rc-safe-run; no inherited application credentials).
env -i PATH="$PATH" HOME=/tmp bash scripts/rc-safe-run -- env \
  TAXNEST_MARIADB_TEST_HOST=127.0.0.1 \
  TAXNEST_MARIADB_TEST_PORT=33116 \
  TAXNEST_MARIADB_TEST_DATABASE=taxnest_odar_test \
  TAXNEST_MARIADB_TEST_USERNAME=taxnest_dev \
  TAXNEST_MARIADB_TEST_PASSWORD=taxnest_local_dev_only \
  TAXNEST_REQUIRE_MARIADB_SCHEMA=1 \
  php vendor/bin/phpunit tests/Feature/OwnerDeploymentApprovalMariaDbSchemaTest.php
```

The recovery harness records all migration filenames and timestamp ties before
running. Historically it saw **541** unique migrations, **53** timestamp ties,
and **49** future-dated filenames after 2026-09-16, through
`2026_12_03_000000_add_internal_deployment_key_to_app_updates.php`. Laravel
uses the full lexical migration basename and ledger, not wall-clock selection.

The checked migration
`2026_12_01_000000_create_owner_deployment_approval_requests.php` required a
strict-mode repair: required application-managed `expires_at` is `DATETIME` in
both fresh creation and missing-column recovery. A required `TIMESTAMP` caused
`Invalid default value for 'expires_at'` under MariaDB 10.6
`NO_ZERO_DATE`.

## Historical observed ledger (raw logs lost)

| UTC interval | command | observed exit | observed result |
|---|---|---:|---|
| 10:29:36–10:32:49 | `--full` | 0 | 541 migrations; 291 tables; 111 manifest tables; second migrate said nothing to migrate. |
| 10:33:08–10:37:49 | `--upgrade` | 0 | 482-migration schema prefix upgraded through all 541 and reran idempotently. |
| 10:38:09–10:38:33 | `--concurrency` | 0 | Independent InnoDB lock gave `10 → 8`; fiscal identity outcomes were `claimed,duplicate`. |
| 10:47:39–10:48:04 | targeted MariaDB PHPUnit | 0 | Owner deployment: 6 tests/23 assertions; FBR KOT timestamp: 1 test/4 assertions. |
| 10:51:27–10:56:41 | DI application lab | outer timeout | Its final transcript reported 12 assertions, 0 failures and two claimers with one winner; outer timeout prevents certifying an exit. |
| 10:56:41–11:00:25 | category native lab | 0 | Schema, tenant isolation, and independent work-order series race passed. |
| 11:00:44–11:03:49 | `EXPLAIN` validation | 0 | Proposed SELECTs parsed on `taxnest_rc_migration`; raw EXPLAIN output was lost. |

At 11:01:33 the native schema assertion observed 111 manifest tables, 293
tables (including temporary native-lab tables), 541 ledger rows, and seven
identity/integrity indexes. These results are historical only.

## Production incident SQL

**PROPOSED — NOT EXECUTED — OWNER APPROVAL REQUIRED.** These are SELECT-only
queries, use current schema names, and were historically parsed with `EXPLAIN`
on the disposable migrated schema. Run only one query at a time with an
approved read-only production account.

```sql
SELECT company_id, invoice_number, COUNT(*) AS duplicate_count
FROM invoices WHERE invoice_number IS NOT NULL AND invoice_number <> ''
GROUP BY company_id, invoice_number HAVING COUNT(*) > 1;

SELECT company_id, invoice_number, COUNT(*) AS duplicate_count
FROM pos_transactions WHERE invoice_number IS NOT NULL AND invoice_number <> ''
GROUP BY company_id, invoice_number HAVING COUNT(*) > 1;

SELECT t.id, t.company_id, t.branch_id, b.company_id AS branch_company_id
FROM pos_transactions t JOIN branches b ON b.id=t.branch_id
WHERE t.branch_id IS NOT NULL AND t.company_id<>b.company_id LIMIT 500;

SELECT company_id, report_date, COUNT(*) AS duplicate_count
FROM fbr_day_close_reports GROUP BY company_id, report_date HAVING COUNT(*)>1;

SELECT p.id, p.company_id, p.branch_id, p.stock_quantity, s.quantity
FROM pos_products p JOIN inventory_stocks s ON s.product_id=p.id
WHERE p.company_id<>s.company_id OR NOT(p.branch_id <=> s.branch_id)
   OR COALESCE(p.stock_quantity,0)<>COALESCE(s.quantity,0) LIMIT 500;

SELECT m.id,m.company_id,m.product_id,m.branch_id,m.balance_after,s.quantity
FROM inventory_movements m
JOIN (SELECT company_id,product_id,branch_id,MAX(id) id
      FROM inventory_movements GROUP BY company_id,product_id,branch_id) x ON x.id=m.id
LEFT JOIN inventory_stocks s ON s.company_id=m.company_id AND s.product_id=m.product_id
 AND s.branch_id <=> m.branch_id
WHERE s.id IS NULL OR COALESCE(m.balance_after,0)<>COALESCE(s.quantity,0) LIMIT 500;

SELECT id,company_id,status,created_at,updated_at,pruned_at
FROM invoice_import_batches WHERE status NOT IN ('completed','pruned') LIMIT 500;

SELECT id,company_id,state,total,done,success,failed,skipped,pending
FROM invoice_bulk_submissions
WHERE done<>success+failed+skipped OR total<>done+pending LIMIT 500;

SELECT id,name,pending_jobs,failed_jobs,cancelled_at,created_at,finished_at
FROM job_batches WHERE failed_jobs>0 OR (finished_at IS NULL AND cancelled_at IS NULL) LIMIT 500;

SELECT id,queue,attempts,reserved_at,available_at,created_at FROM jobs
WHERE reserved_at IS NOT NULL
  AND reserved_at<UNIX_TIMESTAMP(UTC_TIMESTAMP()-INTERVAL 1 HOUR) LIMIT 500;

SELECT company_id,device_uid,hostname,name,agent_version,last_seen_at,printers_reported_at
FROM pos_agent_devices ORDER BY last_seen_at DESC LIMIT 500;
```

## Recovered file inventory

* `scripts/rc-mariadb-lab.sh` — isolated server control.
* `scripts/rc-mariadb-migration-lab.sh` — migration/order/native lab runner.
* `tests/native/rc_mariadb_schema.php` and
  `tests/native/rc_mariadb_concurrency.php` — disposable-only schema and
  independent PDO checks.
* `scripts/tests/di-fiscal-mariadb-check.php` — recovered DI fixture note:
  canonical product type is `di`, not overflowing `digital_invoice`.
* `scripts/tests/di-fiscal-mariadb-check.sh` — recovery guard which fails
  closed instead of pretending the lost DI application-race harness passed.
* `database/migrations/2026_12_01_000000_create_owner_deployment_approval_requests.php`
  — strict MariaDB `DATETIME` repair.
* `tests/Feature/FbrPosKotReprintPermissionTest.php` — restored explicit
  disposable-loopback MariaDB probe configuration; it no longer reads `.env`.
* `database/migrations/2026_12_02_000000_reconcile_owner_deployment_approval_indexes.php`
  — retained committed short-name, additive approval-index reconciliation.

Blockers: temporary raw logs and noncommitted harness source were lost; the
DI bulk-result native race was not executed; recovered code is not
recertified. Re-run only after explicit authorization. **NOT READY.**