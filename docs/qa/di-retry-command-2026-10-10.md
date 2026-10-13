# DI: route retry command through canonical fiscal submission

Use the same claim, immutable snapshot, acknowledgement and reconciliation path as normal fiscal submission. Accepted invoices and ambiguous outcomes stop retrying; only explicit rate limiting permits automatic retry.

## Compatibility

CLI option names retained. Attempts and delay are bounded; no separate direct-send path. Lost callbacks remain pending verification.

## Evidence

- Base: ed806ca8e78e039e129a5cf983acdd5ad9c11a49.
- New regression tests run against unchanged base application code reproduced the original failure. The same tests passed after restoring this branch's correction.
- Targeted isolated suite: OK (10 tests, 33 assertions).
- Tests: DiCanonicalRetryCommandTest.
- Execution: scripts/rc-safe-run, disposable SQLite, synthetic fixtures; external fiscal network traffic blocked.
- An earlier combined snapshot passed 5,357 tests / 42,468 assertions (28 PHPUnit deprecations, 7 skips); final combined and individual-PR CI results must be reviewed separately.
- No live customer records, production credentials, regulator acceptance, merge or deployment verification.
