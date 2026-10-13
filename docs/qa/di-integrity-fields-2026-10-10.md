# DI: cover all fiscal fields in new integrity proofs

Include additional levy, withholding, schedule, identity and destination fields in new v3 integrity proofs. Verification still accepts the original v2 algorithm for genuine historical records.

## Compatibility

Existing proof hashes are never rewritten. Legacy v2 coverage is intentionally not retroactively expanded.

## Evidence

- Base: ed806ca8e78e039e129a5cf983acdd5ad9c11a49.
- New regression tests run against unchanged base application code reproduced the original failure. The same tests passed after restoring this branch's correction.
- Targeted isolated suite: OK (9 tests, 40 assertions).
- Tests: DiIntegrityCoverageTest, DiFiscalSubmissionStateTest.
- Execution: scripts/rc-safe-run, disposable SQLite, synthetic fixtures; external fiscal network traffic blocked.
- An earlier combined snapshot passed 5,357 tests / 42,468 assertions (28 PHPUnit deprecations, 7 skips); final combined and individual-PR CI results must be reviewed separately.
- No live customer records, production credentials, regulator acceptance, merge or deployment verification.
