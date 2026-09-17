# Fresh full PHPUnit recertification

Fresh committed-source execution at the requested SHA. The eight balanced class-filter partitions collectively cover every test in PHPUnit `--list-tests`; the aggregate JUnit is sanitized of system output while retaining testcase outcomes.

- Generated UTC: `2026-09-16T12:57:15.609324Z`
- Source SHA: `903bf6d2ec215f3b983479a39c99ebe44d4e8fd4`
- Source mode: `independent-local-clone`
- Node engine guard: **PASS** (`v22.15.1`)
- PHP / Composer: `8.4.16` / `2.9.2`
- Aggregate JUnit: `docs/release-candidate/recertification/full-phpunit-903bf6d2ec215f3b983479a39c99ebe44d4e8fd.junit.xml`
- Aggregate JUnit SHA-256: `1fa5a1a3ba7e4ec1cb0675afe816545d716dc41c1973b663006ac2de61c16470`

## Exact counts

`tests=5018` `assertions=39251` `failures=9` `errors=3` `skipped=7` `phpunit_deprecations=28` (28 repeated per partition)

| Partition | Exit | Tests | Assertions | Failures | Errors | Skipped | Log SHA-256 |
|---|---:|---:|---:|---:|---:|---:|---|
| `full-batch-01.junit.xml` | 2 | 628 | 3695 | 0 | 3 | 0 | `c4b5fa4b60099f6d8b42e213ab7a44dea93bfe660e926c488570d095a55c37c5` |
| `full-batch-02.junit.xml` | 0 | 628 | 3504 | 0 | 0 | 0 | `03b710d8b122ad3f42037b38466db2e7c5ab718f41503d239253a027be4699db` |
| `full-batch-03.junit.xml` | 0 | 627 | 3220 | 0 | 0 | 1 | `bf8e428a16a9a6c0729d78c672dba979c1f84245ba3106ab3c1e3c8011a94434` |
| `full-batch-04.junit.xml` | 1 | 627 | 5414 | 9 | 0 | 6 | `fb378091647a2f28b491924a4887c3042bf7f34af26aa67953469bea347c3e16` |
| `full-batch-05.junit.xml` | 0 | 627 | 3631 | 0 | 0 | 0 | `a0fed8608585a9678ed5390a79cf358d1292798174db7a99b961c8c2a97062b0` |
| `full-batch-06.junit.xml` | 0 | 627 | 4808 | 0 | 0 | 0 | `02f6232a9712d31204035f741c8c1e8ea720f360ed6a5d3a286f69b705b5f0ac` |
| `full-batch-07.junit.xml` | 0 | 627 | 3063 | 0 | 0 | 0 | `773bb66bef771e58999a27e6d30f5aa66fdac83a79813e206216277f821a7cd4` |
| `full-batch-08.junit.xml` | 0 | 627 | 11916 | 0 | 0 | 0 | `74c76f1c01a1be469eaa0c967b75d4e2ba3700ac6fb7617b1e3017af49b178fa` |

## Failing tests

- **failure** `Tests\Feature\PosPayrollHazriPrintTest::test_hazri_page_renders_payroll_summary_section`
- **failure** `Tests\Feature\PosPayrollHazriPrintTest::test_print_css_uses_visibility_strategy_not_display_none_on_ancestors`
- **failure** `Tests\Feature\PosPayrollHazriPrintTest::test_range_query_renders_staff_totals_with_open_span_asterisk`
- **failure** `Tests\Feature\PosPayrollHazriPrintTest::test_range_query_empty_range_shows_no_data_gracefully`
- **failure** `Tests\Feature\PosPayrollHazriPrintTest::test_range_exceeding_62_days_shows_error_not_500`
- **failure** `Tests\Feature\PosPayrollHazriPrintTest::test_inverted_range_shows_error_not_500`
- **failure** `Tests\Feature\PosPayrollHazriPrintTest::test_cashier_cannot_access_hazri_report`
- **failure** `Tests\Feature\PosPayrollHazriPrintTest::test_payroll_pdf_route_returns_pdf`
- **failure** `Tests\Feature\PosPayrollHazriPrintTest::test_cashier_cannot_access_payroll_pdf`
- **error** `Tests\Feature\PosPendingBillsTileTest::test_pra_admin_sees_tile_with_triple_filtered_count_and_local_portal_link`
- **error** `Tests\Feature\PosPendingBillsTileTest::test_pra_cashier_never_sees_tile_even_with_pending_bills`
- **error** `Tests\Feature\PosPendingBillsTileTest::test_non_restaurant_admin_with_zero_count_sees_no_tile`

## Locked source identity

- `agent-realtime-gateway/package-lock.json`: `3c96680d9ce8a9d0c6085fafc250a3b3de4a8df3f099d40b441d4e76f54b461a`
- `artifacts/mockup-sandbox/package-lock.json`: `aecf45ae4de09b7b7bdbe65fe0c21e88504a1ae01704ea6fdb42c2fd9cff861a`
- `composer.lock`: `f77aae4ea1f5d8b5427132fb76e2ca017b7917ebdece26036715055a5fc141e4`
- `package-lock.json`: `ee6faa95cd57839884299d07e871ae9593029be42bd363f523d1b13caac8ff10`
- `pra-agent/package-lock.json`: `288f23ac9c63ec72a2318895ebb582f0cb85b31f07aeefcffc60800d22a74472`
- `tools/video-pipeline/package-lock.json`: `1ea36c806550b001e4b9cf14d35160432094bf0a2e6facd73f8c93a087f7acf8`

## Boundary

- Each partition ran through the committed clone's `scripts/rc-safe-run` with a fresh HOME and loopback-only egress guard.
- No browser, native MariaDB, production, fiscal, or external application endpoint was launched.
- Dependency/bootstrap proof is retained in the ignored runtime; this report stores sanitized names, counts, and hashes only.
