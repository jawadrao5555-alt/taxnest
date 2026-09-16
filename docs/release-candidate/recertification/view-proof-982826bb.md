# Final-source changed-view proof — `982826bbcc0e97ea5a96c308c76224082ff035c4`

This is the frozen final-source follow-up for the invoice views. It uses the
same isolated view clone previously created for the `58d0c164` proof, checked
out at exact source `982826bbcc0e97ea5a96c308c76224082ff035c4`.

No full PHPUnit suite, build, audit, or unrelated guard was repeated.

## Parent full proof

The parent committed-runner result remains the separately retained PASS:

| Source | Tests | Assertions | Failures | Errors | Skipped | Exact IDs | JUnit SHA-256 |
|---|---:|---:|---:|---:|---:|---|---|
| `2b500f6a67320a1138cc555df342b5f29bdb2377` | `5018` | `39281` | `0` | `0` | `7` | `5018`, missing/extra/duplicate `0` | `dd2e9e216b80fe45ce01d8dd2b10400818c02297129e35d58154e284c9b5f197` |

## Final-source delta

Compared with the tested `58d0c164` source, final source `982826bb` contains
only:

- exactly seven existing `dark:text-white` classes in
  `resources/views/invoice/show.blade.php`, keeping buyer/invoice detail
  values readable on the dark surface. Final file SHA-256:
  `48eacdcd02b64bee7c8245f21e57aa9b6a852397b19f30eec8f7d3ecc10cfdc1`.
- one specific
  `form[action$="/confirm-fbr"] button[type="submit"]` usable selector in
  `scripts/rc-di-browser-fixture.php`. Final file SHA-256:
  `092167829994836262e86ca57b759898c893e4254766295f07c110d503478a02`.

The `58d0c164` normal-flow toolbar logic and semantic invoice-heading logic
are unchanged. The earlier focused logic proof therefore remains applicable.

## Focused PHPUnit result

Only these three direct view methods were run:

- `Tests\Feature\FbrLogTenantIsolationTest::test_company_b_user_cannot_read_company_a_fbr_log_through_the_invoice_page`
- `Tests\Feature\FbrLogTenantIsolationTest::test_company_a_user_sees_its_own_fbr_log_on_the_invoice_page`
- `Tests\Feature\Security\SuperAdminDualPathTest::test_company_admin_cannot_read_another_companys_invoice`

The exact command ran from
`.local/recertification/view-proof-58d0c164/source`, now detached at final
source `982826bb`:

```text
3 tests, 7 assertions, 0 failures, 0 errors, 0 skipped, exit 0
```

| Artifact | SHA-256 |
|---|---|
| `.local/recertification/view-proof-982826bb/focused.log` | `440d5414b8fa6fade61f0705734f994487764ecdd0fed5526d2b2d929b6d0049` |
| `.local/recertification/view-proof-982826bb/focused.junit.xml` | `fa2c629700ddce0726923febc0f54688c7985f77e9c5093ffb6d4d124feee12a` |

## Blade compile

The final source passed the cheap isolated Blade compile:

```text
php artisan view:cache — exit 0
```

| Source | Compiled output | SHA-256 |
|---|---|---|
| `resources/views/invoice/show.blade.php` | `storage/framework/views/cc21074574fefa97eea155bee4faff06.php` | `345203463e1bdcad67e26c0aa7b4bbea7f727dfcf37ee6fd64bfbc3382945948` |
| `resources/views/layouts/app.blade.php` | `storage/framework/views/3d67571234b45fc80f873f472f1824b7.php` | `9ff5b63c9d4bd5eb642037523d2f6a64a2462847522f0cefe50dcd33e89c0569` |

The final isolated view clone is frozen at
`982826bbcc0e97ea5a96c308c76224082ff035c4` and is clean. This record
supplements rather than replaces the parent `5018`-test PASS.