# DI: reject conflicting API reference replays

The same client_reference and equivalent request returns the original invoice; changed amounts or mode return HTTP 409 instead of silently returning an unrelated earlier draft. Legacy references compare persisted fields without rewriting them.

## Compatibility

Additive nullable request-hash migration; existing client references remain tenant-scoped. Draft-to-submit replay is a conflict, not a new fiscal action.

## Evidence

- Base: ed806ca8e78e039e129a5cf983acdd5ad9c11a49.
- New regression tests run against unchanged base application code reproduced the original failure. The same tests passed after restoring this branch's correction.
- Targeted isolated suite: OK (12 tests, 49 assertions).
- Tests: DiApiReferenceConflictTest, DiApiSubscriptionAccessTest, DiInvoiceApiInvoiceDateTest.
- Execution: scripts/rc-safe-run, disposable SQLite, synthetic fixtures; external fiscal network traffic blocked.
- An earlier combined snapshot passed 5,357 tests / 42,468 assertions (28 PHPUnit deprecations, 7 skips); final combined and individual-PR CI results must be reviewed separately.
- No live customer records, production credentials, regulator acceptance, merge or deployment verification.
