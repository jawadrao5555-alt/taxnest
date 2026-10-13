# DI: invoke fiscal submission and hold incomplete acknowledgements

Invoke the previously returned-but-unexecuted submission callback. A valid-looking response without a fiscal reference remains pending verification with its reservation intact; only an explicit reference-free rejection permits correction/retry.

## Compatibility

Submit tests use a disposable loopback PHP server and fictitious token. No regulator invoice was sent. Merge the financial, unit, withholding and retry safeguards before deploying this transport-enabling correction.

## Evidence

- Base: ed806ca8e78e039e129a5cf983acdd5ad9c11a49.
- New regression tests run against unchanged base application code reproduced the original failure. The same tests passed after restoring this branch's correction.
- Targeted isolated suite: OK (12 tests, 39 assertions).
- Tests: DiAcknowledgementClassificationTest, DiAmbiguousAcknowledgementTransportTest, DiFiscalSubmissionStateTest.
- Execution: scripts/rc-safe-run, disposable SQLite, synthetic fixtures; external fiscal network traffic blocked.
- An earlier combined snapshot passed 5,357 tests / 42,468 assertions (28 PHPUnit deprecations, 7 skips); final combined and individual-PR CI results must be reviewed separately.
- No live customer records, production credentials, regulator acceptance, merge or deployment verification.
