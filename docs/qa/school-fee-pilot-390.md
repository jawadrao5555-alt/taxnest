# School / Education cash-collection pilot (#390)

## Scope and data separation

- Adds the existing `education` category to standalone FBR POS registration, with an FBR-specific School / Education label in English, Urdu and Roman Urdu. Existing retail and PRA education defaults are preserved; school signup does not enable store-slip printing.
- `/fbr-pos/create` takes school companies to `/fbr-pos/school-fees`. Tuition, admission and exam cash collections have a student reference, amount, immutable local receipt and history/reprint.
- Uses a separate `school_fee_receipts` ledger, **not retail transactions**. Collections do not generate tax invoices, inventory movements, kitchen tickets, fiscal IDs, fiscal QR codes, retail tax or an FBR service fee. School companies now have native Sales History at `/fbr-pos/transactions` and a campus/calendar-date cash close at `/fbr-pos/day-close`, counting each immutable receipt once. They remain excluded from retail goods/tax totals. Canteen and merchandise billing remain separate, not mixed with tuition.
- Receipt identity is `SF-<id>`. Reprints read the same row. Tenant and active branch scope apply to list and receipt reads. Cashiers/managers/admins only; read-only impersonation cannot collect. A tenant-row lock and unique `(company_id, request_key)` prevent duplicate collection. A retry with different details is rejected.
- Collections count toward the FBR product's bill/trial quotas; quota recheck runs inside the tenant lock. A completed request can be retrieved after a limit is reached.
- Synthetic student codes only in tests. No student names, government IDs, family data or real customer records are needed.
- Full School ERP (students/admissions, recurring challans, arrears, concessions, bank reconciliation, attendance, exams and parent portal) remains future backlog #314.

## Fiscal blocker (do not enable)

Official [SRO 428(I)/2024](https://download1.fbr.gov.pk/SROs/2024322153582988SRO-428.pdf) lists private schools in the Rule 33A schedule, subject to its stated exception. This is historical legal-scope evidence, **not an API contract or independent confirmation of current applicability**.

No official school-specific transport/API schema, fee-head tax mapping, sandbox enrollment/credentials or accepted school response has been verified. The [FBR technical assistance material](https://www.fbr.gov.pk/pos-technical-assistance/163085/163087) is retail-oriented. The [18 February 2026 SRO 288 document](https://download1.fbr.gov.pk/SROs/2026218112270512SRO288dated18.02.2026.pdf) is explicitly a draft and is not evidence of a final technical route.

Fiscal reporting is therefore disabled in the school company's runtime flag, integration readiness, fiscal-device routing and central FBR submission service (manual and queue retry paths included). DI submission is also blocked for the school company. No sandbox request was sent: substituting retail IMS or DI SN018/SN019 would not verify the school route. Local collection receipts explicitly say they were not submitted to FBR and contain no fiscal QR/number. No tax liability is inferred from the collection amount.

Next step: request written clarification through the [official FBR POS control room](https://www.fbr.gov.pk/pos-control-room/163085/173428) and the licensed integrator. Confirm the school reporting event, transport, tax treatment and sandbox enrollment. No outreach has been sent. Before removing these guards, obtain the licensed integrator's official school onboarding and reporting-event contract, approved endpoint/schema/tax mapping, synthetic sandbox enrollment and accepted response evidence. Add a school-specific adapter and acknowledgement/duplicate-safety tests. Owner approval is required before merge/deploy; this draft does not enable production reporting.

## Verification

Targeted regressions:

```sh
vendor/bin/phpunit tests/Feature/FbrPosSchoolFeePilotTest.php
vendor/bin/phpunit tests/Feature/RequestedPackageSignupTest.php
vendor/bin/phpunit tests/Feature/FbrPosStoreReplayGuardTest.php
vendor/bin/phpunit tests/Feature/FbrPosFiscalReceiptIdentityTest.php
```

Run the full PHPUnit suite and local Chrome/MariaDB collection/receipt/reprint smoke as well. At initial publication the cloud workspace had no PHP or Composer; package installation was unavailable without system privileges. Local PHP/Blade execution and Chrome/MariaDB verification were **not run**. Hosted PR checks are the test execution source; consult the PR's current-head CI evidence. Do not interpret static review or test code as a passing test run, or mocked HTTP assertions as real FBR sandbox acceptance.

## Follow-up verification and dependency patch

- The full-suite Urdu-font sweep found the standalone school layout missing the shared font include. The layout now includes `partials.urdu-font`; no exemption or test suppression was added.
- At owner request, Composer's targeted minimal update raised the Laravel constraint to `^12.71.0` and resolved `laravel/framework` from `v12.69.3` to `v12.72.0`. This is the only package version change. Composer 2.11 also refreshed package metadata. Local `composer validate` succeeds (existing unrelated unbounded php-imap warning), and `composer audit --locked` reports no advisories.
- The explicit `school-fees` real-browser CI journey uses a disposable MariaDB fixture at desktop 1366×900 and mobile 390×844. It checks public category selection, authenticated cash collection, persisted receipt/history, duplicate-safe replay, print-media layout and saved receipt reprint action. Screenshots remain local to the disposable runner. The existing artifact sanitizer supports text evidence only, so no screenshot artifact is published; sanitized browser text evidence uses the existing `rc-evidence-browserdesktopmobile-<run id>` artifact.
- Browser registration checks the category binding, not a newly submitted/approved signup. Submitted signup is covered by PHPUnit. Print actions are intercepted to assert browser invocation; physical paper output is not claimed. CI results must be read for the latest exact commit before treating this new journey as verified.

## Cash-report and immutable-close acceptance

- Native School Sales History and day-close cash report read the same company/campus-scoped receipt ledger. Duplicate response retries and changed-payload retries cannot add a second history row or change the receipt/cash total.
- School close totals use the selected calendar date in application time, not the retail overnight business-day cutoff. Campus scope is the active branch context; another company's or campus's direct receipt URL is denied.
- Confirmed School closing now stores a permanent snapshot in `school_fee_day_closes`, unique per company, normalized campus and calendar date. It freezes receipt count, integer-paisa cash total, closing actor and timestamp. Subsequent reads/reprints use that snapshot, not recomputed live totals.
- The displayed count/total must still match under the company-row lock before a first close succeeds. A stale preview returns a clear error and requires refresh/confirmation. Repeated close returns the original snapshot. The same lock serializes collection against closing; the unique scope/date key is a durable backstop.
- **Post-close policy:** a fresh payment into the already closed current campus/date is rejected without recording cash. The desk shows this state and disables new collection. Identical saved-payment retries are checked first and return the same receipt even after close; changed payload is rejected without changing receipt or snapshot. Do not accept further cash until the next open calendar date. No reopening or automatic/bulk school close is supported in this pilot.
- Closing requires the existing day-close permission and explicit confirmation, and is denied in read-only impersonation. Each campus closes separately. Retail closing/overnight cutoff and fiscal Z-reports are not reused; other retail categories are unchanged. School fiscal submission remains disabled.

- Native MariaDB CI probe `tests/native/rc_school_fee_concurrency.php` launches independent synchronized PHP processes for duplicate collection, duplicate close and collection-versus-close races. It asserts one receipt/snapshot, rejects stale close or late collection according to lock order, and verifies post-close retries preserve both. Consult latest-head native CI before claiming this probe passed.
