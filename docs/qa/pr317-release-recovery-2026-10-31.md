# PR #317 release recovery — 31 October 2026

## Observed failures

PR #317 merged as `a7f93b62407e6c8094ebde0fe454b22f12cd547f`.
Deploy run 41169423426 inserted announcement row 461, then stopped at the freshness gate with `ELAAN_DB_ERROR`; live verification was skipped. Agent run 41169432662 refused to replace existing tag `v1.13.24`, which belongs to a different source commit. Neither failure is counted as a live release.

The generic database error does not identify the production server's exact connection/schema failure. No production credentials or database were accessed here. Inspection identified and tests cover two concrete failure paths: insertion uses Laravel's configured connection while the former gate independently parsed `.env` and invoked a MySQL client (ignoring configured socket/port/cache); a legacy schema can publish a row without a deployment key and immediately fail the exact-key gate before normal migrations can run.

## Changes

- Prepare only the two nullable announcement scope/receipt columns before publication in the approved deployment runner. Existing rows/timestamps/read state are preserved; normal committed migrations remain idempotent. Production workflow serialization is retained.
- Count receipts through the same bootstrapped Laravel connection as insertion, with parameterized SHA/time, exact committed audience/categories and archive/publication predicates. Convert the deployment marker timestamp to the application's writing timezone. Database/bootstrap/protocol errors fail closed without displaying credentials.
- Keep new-SHA freshness and same-SHA receipt rules; preserve strict relay/SHA/owner gates. Do not use emergency skips.
- Bump Agent package and both lock identities to `1.13.25`; tag absence was checked before proposing it. Existing tags remain intact.
- Rewrite the pending PRA announcement in plain Roman Urdu, preserve its `pos` audience, and link reusable customer wording guidance from AGENTS.md.

## Local evidence

All application tests use the unchanged `scripts/rc-safe-run` loopback-only guard on disposable fixtures.

- PHP 8.4.27: receipt legacy-schema/current-connection tests: 3 tests / 13 assertions passed.
- Receipt + existing featured/targeted notification regression: 50 tests / 261 assertions passed.
- Existing announcement parser, scope transport negatives, same-SHA/new-SHA freshness, title/provenance and deployment safety checks passed.
- Generated insertion PHP and the native probe passed syntax checks.
- Agent platform-independent suite (same selection as CI): 132 tests, 127 passed / five conditional skips / zero failures. Real Electron and Windows packaging remain separate required CI evidence.
- The first broad Agent invocation lacked installed dependencies and is not counted as passing; locked dependencies were installed and the CI selection rerun successfully. Full PHP and required CI results remain pending at this checkpoint.

Native MariaDB scope probes now also execute the actual configured-connection receipt counter for all eight audience/category combinations, including wrong SHA/audience, unpublished/archived/deleted records and freshness boundaries. Native execution is required in CI.

## Release boundary

A green fix PR still needs owner approval for its new commit and the normal merge/deploy/live-verification chain. Do not retry the old approved SHA or mark the release live from unit tests alone.

## Follow-up verification checkpoint

The first CI safety/build lanes correctly rejected the changed root Agent version because its reviewed lock graph fingerprint still described v1.13.24. The fingerprint now records only the reviewed root-identity change; all 307 resolved dependencies, integrity values and dependency edges remain unchanged. The portability and tampered-source negative probes passed locally.

The full suite also reproduced two pre-existing month-end fixture errors on 31 October: subtracting a month from 31 October overflowed to 1 October. Their intended prior-month dates now subtract from the first day of the month. Assertions and production billing/period logic remain intact; the isolated regression passed 59 tests / 587 assertions. Earlier failed runs are not counted as passes.
