# Release receipt compatibility recovery after PR #322

PR #322's production deployment run 41191056492 stopped at the read-only
announcement receipt gate with `ELAAN_DB_ERROR`; live verification did not run.
The Agent production build succeeded separately. The caught production exception
had no SQLSTATE or stage, so its precise underlying cause is not established.

## Reproduced compatibility failures

Using actual MySQL 8.0.46 with a fresh disposable datadir, the generated receipt
query fails with error 1267: the existing `utf8mb4_unicode_ci` audience column
conflicts with `CONVERT(hex USING utf8mb4)`'s implicit `utf8mb4_0900_ai_ci`.
`SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci` does not prevent that failure.
The regression reproduces it before exercising the correction.

A second native MySQL difference rejects an eligible empty JSON array when
comparing the JSON column directly with SQL text `'[]'`. MariaDB's JSON-as-text
coverage did not expose these MySQL-specific differences.

## Change and compatibility matrix

The generated literals now specify the shared `utf8mb4_unicode_ci` collation.
Only legacy broadcast category representations are compared through a text cast;
category-specific scopes still require valid JSON, exact cardinality and JSON
containment. No schema, saved customer setting, announcement content, read state,
Agent version or fiscal behavior changes.

The actual MySQL regression covers all four audiences, broadcast and selected
categories, JSON and legacy TEXT columns, and unicode/general/0900 column
collations: 48 combinations and 708 assertions. Each checks fresh and old times,
wrong audience, SHA, publication state, archive state, category mismatch,
restoration and deletion. NULL, empty text and empty arrays retain their
existing broadcast meaning. It runs SQL during initialization, without opening
a listener, reading host configuration or using an existing database.

The existing mandatory full-PHP CI lane now runs this native MySQL test;
MariaDB and browser lanes remain required. Active and proposed workflow contracts
require the new regression. No skip_elaan bypass or weakened deployment gate.

Receipt failures now expose only an allowlisted stage, SQLSTATE and numeric
driver code. Tests prove exception messages, SQL/bindings, arbitrary diagnostic
strings and credentials cannot enter this protocol. A subprocess test boots the
real Laravel receipt CLI, verifies its success protocol, then forces a query
failure and verifies the sanitized failure protocol.

## Validation

- Actual MySQL 8.0.46: original 1267 reproduced; corrected 48 cases / 708 assertions pass.
- Receipt bootstrap/real CLI/sanitization: 5 tests / 23 assertions pass.
- Existing audience targeting plus initial receipt tests: 34 tests / 155 assertions pass.
- Strict announcement scope and same-SHA freshness regression scripts pass.
- Mandatory CI DAG contract and git whitespace checks pass.
- Full suite and required GitHub CI results are recorded in the pull request.

These are local/CI proofs. Production recovery remains unverified until the owner
runs the approved release and Actions live verification succeeds. Safe staged
diagnostics will identify any distinct live-only cause rather than hiding it.
