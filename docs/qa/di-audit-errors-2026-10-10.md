# DI: persist redacted actionable rejection diagnostics

Persist safe item/error codes and correction instructions so later API status reads retain useful rejection details. Raw regulator messages and sensitive response headers are not saved.

## Compatibility

Successful fiscal references remain available; no database rewrite. Generic errors remain generic when their code is unknown.

## Evidence

- Base: ed806ca8e78e039e129a5cf983acdd5ad9c11a49.
- New regression tests run against unchanged base application code reproduced the original failure. The same tests passed after restoring this branch's correction.
- Targeted isolated suite: OK (2 tests, 7 assertions).
- Tests: DiRedactedErrorPersistenceTest.
- Execution: scripts/rc-safe-run, disposable SQLite, synthetic fixtures; external fiscal network traffic blocked.
- An earlier combined snapshot passed 5,357 tests / 42,468 assertions (28 PHPUnit deprecations, 7 skips); final combined and individual-PR CI results must be reviewed separately.
- No live customer records, production credentials, regulator acceptance, merge or deployment verification.
