# DI: separate withholding flag from monetary amount

Store and submit the actual sales-tax withholding amount rather than turning a checked flag into PKR 1. Panel/API validation requires an explicit amount for checked items.

## Compatibility

Additive nullable st_withheld_amount migration. Old flags are preserved; missing historical amounts require staff correction before fiscal submission. No invented backfill.

## Evidence

- Base: ed806ca8e78e039e129a5cf983acdd5ad9c11a49.
- New regression tests run against unchanged base application code reproduced the original failure. The same tests passed after restoring this branch's correction.
- Targeted isolated suite: OK (5 tests, 19 assertions).
- Tests: DiWithholdingAmountTest, DiWithholdingApiTest.
- Execution: scripts/rc-safe-run, disposable SQLite, synthetic fixtures; external fiscal network traffic blocked.
- An earlier combined snapshot passed 5,357 tests / 42,468 assertions (28 PHPUnit deprecations, 7 skips); final combined and individual-PR CI results must be reviewed separately.
- No live customer records, production credentials, regulator acceptance, merge or deployment verification.
- Local Chrome could not launch because the host denied AF_UNIX sockets. A dedicated GitHub Actions loopback browser regression exercises the real form and persisted draft; its result is required before calling the UI verified.
