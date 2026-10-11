# DI: retain provided buyer CNIC when NTN is absent

Choose a nonblank buyer NTN first, otherwise preserve the supplied CNIC in the fiscal payload.

## Compatibility

Existing NTN precedence and optional walk-in identity remain unchanged; tested with two synthetic tenants.

## Evidence

- Base: ed806ca8e78e039e129a5cf983acdd5ad9c11a49.
- New regression tests run against unchanged base application code reproduced the original failure. The same tests passed after restoring this branch's correction.
- Targeted isolated suite: OK (2 tests, 4 assertions).
- Tests: DiBuyerIdentityPayloadTest.
- Execution: scripts/rc-safe-run, disposable SQLite, synthetic fixtures; external fiscal network traffic blocked.
- An earlier combined snapshot passed 5,357 tests / 42,468 assertions (28 PHPUnit deprecations, 7 skips); final combined and individual-PR CI results must be reviewed separately.
- No live customer records, production credentials, regulator acceptance, merge or deployment verification.
