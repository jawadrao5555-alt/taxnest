# DI: preserve quantity meaning when validating units

Normalize equivalent unit labels only. An incompatible regulator unit now requires explicit correction instead of silently relabeling the same quantity as KG, litres or thousand units.

## Compatibility

Previously working case-insensitive aliases remain supported. No stored units or quantities are rewritten.

## Evidence

- Base: ed806ca8e78e039e129a5cf983acdd5ad9c11a49.
- New regression tests run against unchanged base application code reproduced the original failure. The same tests passed after restoring this branch's correction.
- Targeted isolated suite: OK (5 tests, 14 assertions).
- Tests: FbrUomRegressionTest.
- Execution: scripts/rc-safe-run, disposable SQLite, synthetic fixtures; external fiscal network traffic blocked.
- An earlier combined snapshot passed 5,357 tests / 42,468 assertions (28 PHPUnit deprecations, 7 skips); final combined and individual-PR CI results must be reviewed separately.
- No live customer records, production credentials, regulator acceptance, merge or deployment verification.
