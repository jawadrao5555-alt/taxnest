# Changed-view proof — source `58d0c164f5920a567d3a817e1c210c3b56e24c00`

This is a focused follow-up record for the two Blade views changed by commit
`58d0c164`. It is intentionally not a replacement for the parent-source
full-suite result. The parent committed-runner proof remains:

| Source | Tests | Assertions | Failures | Errors | Skipped | Exact IDs | JUnit SHA-256 |
|---|---:|---:|---:|---:|---:|---|---|
| `2b500f6a67320a1138cc555df342b5f29bdb2377` | `5018` | `39281` | `0` | `0` | `7` | `5018`, missing/extra/duplicate `0` | `dd2e9e216b80fe45ce01d8dd2b10400818c02297129e35d58154e284c9b5f197` |

## Final-source follow-up

The final source subsequently advanced to
`982826bbcc0e97ea5a96c308c76224082ff035c4`. Its diff from the exact tested
`58d0c164` view source is bounded and does not alter the tested normal-flow
or semantic invoice-header logic:

- `resources/views/invoice/show.blade.php` adds exactly seven existing
  `dark:text-white` classes to buyer/invoice detail values so they remain
  readable on the dark surface. The final file SHA-256 is
  `48eacdcd02b64bee7c8245f21e57aa9b6a852397b19f30eec8f7d3ecc10cfdc1`.
- `scripts/rc-di-browser-fixture.php` adds one specific
  `form[action$="/confirm-fbr"] button[type="submit"]` usable selector. The
  final file SHA-256 is
  `092167829994836262e86ca57b759898c893e4254766295f07c110d503478a02`.

The exact `58d0c164` focused three-method result therefore remains valid for
the unchanged normal-flow and semantic-header logic. No full-suite repeat is
claimed. The final DI two-role browser run is separately recorded as running
on `982826bb`; the fiscal `58` both-trial click result is recorded as PASS by
the handoff, not substituted for the browser run.

## Changed inputs

| File | SHA-256 | Proof-relevant change |
|---|---|---|
| `resources/views/invoice/show.blade.php` | `9e45fa9bd8d0a3734a49215a6d0cd45039496c1b0feb0a38c7c4d898eddf01d8` | Responsive wrapping; semantic `invoice-heading`; `Invoice actions` label |
| `resources/views/layouts/app.blade.php` | `f0bbab2adeec9a5099b918d14239f9b768abe39814c0b051406e2e0fa5efceec` | Route-specific compact app-bar label; single invoice toolbar rendered in normal page flow above the document |

## Existing PHPUnit coverage touching the changed views

Repository search identified these existing classes as the focused view
coverage:

- `Tests\Feature\FbrLogTenantIsolationTest`
  - `test_company_b_user_cannot_read_company_a_fbr_log_through_the_invoice_page`
  - `test_company_a_user_sees_its_own_fbr_log_on_the_invoice_page`
- `Tests\Feature\Security\SuperAdminDualPathTest`
  - `test_company_admin_cannot_read_another_companys_invoice`

Only those three methods were run. The remaining methods in those classes and
the full `5018`-test suite were not rerun.

## Focused result

The run used the isolated checkout
`.local/recertification/view-proof-58d0c164/source` at the exact changed-view
commit, with the existing locked Composer dependency tree available through
the ignored `vendor/` directory:

```text
3 tests, 7 assertions, 0 failures, 0 errors, 0 skipped, exit 0
```

The PHPUnit output and JUnit companion are retained outside the tracked source:

| Artifact | SHA-256 |
|---|---|
| `.local/recertification/view-proof-58d0c164/focused.log` | `3ca0b4e7dfc75d4fc00b48ac36bd7e22a2d8bc4a2966ef7ae3154be71e2ca193` |
| `.local/recertification/view-proof-58d0c164/focused.junit.xml` | `31b5abd4fcd30aed8191a39a2da3d8e0b64ad7dda93c6982f6b90787dc1173d8` |

## Blade compile and artifact guard

Because the changed inputs are Blade templates, the isolated checkout also
ran the cheap compile check:

```text
php artisan view:cache — exit 0
```

The compiled outputs containing the changed markup are:

| Source | Compiled output | SHA-256 |
|---|---|---|
| `resources/views/invoice/show.blade.php` | `storage/framework/views/cc21074574fefa97eea155bee4faff06.php` | `ddba73839460202660799155b08efe4a1d53ea92ea9107c897d240fa21f5f1d4` |
| `resources/views/layouts/app.blade.php` | `storage/framework/views/3d67571234b45fc80f873f472f1824b7.php` | `9ff5b63c9d4bd5eb642037523d2f6a64a2462847522f0cefe50dcd33e89c0569` |

The repository artifact guard was run because the changed Blade inputs made
the compiled-view/artifact check meaningful. It passed with exit `0`; its
retained log SHA-256 is
`c994f7555c388641086d1b6ba4175d86446b429dd43187197213bb7576f42663`.

This focused record supplements the parent `5018`-test PASS and does not
claim browser acceptance or a replacement full-suite run.