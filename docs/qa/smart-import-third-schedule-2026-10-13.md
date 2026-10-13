# Smart import release: third-schedule MRP validation

Release PR #191 originally failed its pre-suite Smart DI regression on HEAD
2a624bfa393047d29393c0b3258a81248ba5bc6f. The source test expected a sales-base
mismatch from the old DI payload. Deployed DI correctly retains transaction
sales value and computes third-schedule tax from quantity x MRP.

Reproduction on disposable SQLite / PHP 8.4.26 through scripts/rc-safe-run
matched CI's failure. Extending the regression to a consistent third-schedule
source then reproduced a real importer validation gap: 12 units at sale price
100, MRP 150 and VAT 324 were rejected as expecting VAT 216.

InvoiceImportService now derives and checks third-schedule tax rates using the
MRP tax base. Sale price/value stay intact; ordinary schedules retain the
transaction tax base. The pre-existing legacy missing-MRP fallback to unit
price remains. Smart review still requires explicit verified MRP and exact
local payload reconciliation; no source VAT or identity is guessed or rewritten.

Regressions verify:

- Source VAT 216 is blocked against MRP-based expected VAT 324.
- Matching VAT 324 / net 1524 passes exact reviewed payload checks while the
  transaction sales value remains 1200.
- Explicit 18% and a rate derived from supplied VAT both yield 18% from MRP,
  retaining unit price 100 and MRP 150.
- Standard-source VAT paisa mismatch stays blocked; legacy MRP fallback and
  existing draft/import, tenancy and parser tests continue to pass.

Final local targeted result: 63 tests / 756 assertions, zero failures/errors.
PHP syntax and git whitespace checks passed. All application tests ran under
the loopback-only egress guard; no regulator submission occurred. Fresh full
PHPUnit, browser and native MariaDB CI must pass on the corrected release SHA
before owner approval. No deployment or live acceptance is claimed.
