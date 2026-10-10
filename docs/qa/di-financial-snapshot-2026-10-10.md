# DI: unify invoice, payload and PDF totals

One calculation keeps actual sales value distinct from the retail/MRP tax base. New drafts use the same rounded totals across panel, API and import; mismatched historical snapshots are blocked before submission rather than rewritten.

## Compatibility

Existing stored invoices remain unchanged; no schema migration. Decimal tax rates and standard-rate invoices remain supported.

## Evidence

- Base: ed806ca8e78e039e129a5cf983acdd5ad9c11a49.
- New regression tests run against unchanged base application code reproduced the original failure. The same tests passed after restoring this branch's correction.
- Targeted isolated suite: OK (15 tests, 44 assertions).
- Tests: DiFinancialSnapshotTest, FbrPayloadPrecisionTest, DiInvoiceBranchIdentityTest.
- Execution: scripts/rc-safe-run, disposable SQLite, synthetic fixtures; external fiscal network traffic blocked.
- An earlier combined snapshot passed 5,357 tests / 42,468 assertions (28 PHPUnit deprecations, 7 skips); final combined and individual-PR CI results must be reviewed separately.
- No live customer records, production credentials, regulator acceptance, merge or deployment verification.
- Local Chrome could not launch because the host denied AF_UNIX sockets. A dedicated GitHub Actions loopback browser regression exercises the real form and persisted draft; its result is required before calling the UI verified.
