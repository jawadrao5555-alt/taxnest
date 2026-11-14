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

## School-only collection UX

- Authenticated School/Education accounts land directly on Collect Fee. The existing `/fbr-pos/create` entry also redirects there. Retail login/signup remains on its existing sale screen.
- School Dashboard renders only today's campus fee collections, receipt count and cash day-close status. Collect Fee, Receipts / Reprint and authorized Cash Day Close use the isolated school shell, without retail inventory, deals, loyalty, tax or fiscal actions.
- The cash flow is student reference → fee head → amount → explicit confirmation that cash was received → immutable receipt. New payment cannot be stored without confirmation; an already-saved identical retry remains retrievable without reconfirming cash.
- Campus selection uses only the existing FBR guard's authorized active campuses, is shown only when more than one is available, and rejects foreign/inactive/ALL targets. Branchless schools retain their null campus; schools with campuses use one concrete authorized campus rather than an ALL scope. No authorized campus means no collection access.
- These changes apply only when the stored company category satisfies `isSchoolFeePilot()`. No existing retail tenant (including the unverified live Ali Garh account) is reclassified or migrated by this PR. No live settings, merge or deployment were changed. Full School ERP stays in #314.

## Review scope and existing retail history preservation

Ready-for-review scope is the **School local cash pilot only**, not fiscal integration, Full School ERP, or a live retail-to-school migration. Merge/deployment and any later account/category change remain separate owner decisions.

An in-place category flip is **not itself a complete migration**: `isSchoolFeePilot()` activates when either stored category field is `education`. Existing retail rows are not deleted, but normal dashboard, transactions and day-close entrypoints switch to school views. Row preservation alone does not preserve discoverability or operational access to old retail history. The explicit School legacy-invoice archive below addresses invoice discovery/reprint only, not migration of retail operations or old closing reports.

Before any proposed transition:

1. Verify the intended account, both saved category fields, branches/roles, subscription, reporting/agent/printer settings, pending fiscal/offline work and outstanding retail balances through an owner-approved operations process. Do not infer its category from a screenshot or rewrite settings here.
2. Prefer retaining the original retail company and all its receipt IDs, fiscal acknowledgements/QRs, audit records, day-close snapshots, users and configuration unchanged. Provision a separate explicitly approved School account for future local cash only, subject to the real identity/NTN/email uniqueness rules. Do not fabricate identifiers or bypass signup constraints; if lawful separate provisioning is unsupported, stop for a design decision.
3. If an in-place transition is instead required, first deliver a separately reviewed legacy retail archive with authorized history/search/reprint/export and immutable old day-close access that does not depend on the current category. Add synthetic mixed-history tenant/campus tests and a reversible cutover procedure. This PR provides a limited read-only invoice archive, not old retail day-close/tax export migration or a production cutover.
4. Before an approved cutover, an owner-operated production workflow must create and verify a restorable backup, record counts and monetary control totals by company/campus/date and pending work, then reconcile the same receipt identities, fiscal evidence and closing snapshots after staging rehearsal. Never convert retail rows into school fee receipts or recalculate historical fiscal amounts.
5. Obtain owner approval for the exact cutover and rollback plan. No live category change, production backup, data extraction, migration, merge or deployment is performed by this PR. Fiscal School submission stays disabled regardless of the provisioning choice.

## Lost-response refresh recovery

The prior backend replay test retained a known request key manually; it did not demonstrate recovery after a real page refresh. The fee form now saves only a pending request UUID in sessionStorage, scoped by signed-in user, company and campus, before an explicit POST. A failed/timeout response retains the same key. Refresh offers explicit recovery using the original details and warns **do not collect cash again**. There is no automatic retry and no student/payment detail persistence. Storage failure blocks submission rather than sending an unrecoverable request. Only opening the matching saved receipt clears that key; another receipt does not.

This recovery is limited to the same browser tab/account/campus. If the tab/session is lost, use Receipts / Reprint and reconcile the saved payment before recording any new cash; do not infer that an unknown response means failure. Closed-day controls allow recovery of an already pending saved request but the server still rejects any new closed-date collection.

The updated desktop/mobile browser test sends an actual POST via route.fetch, lets the server persist it, discards its response, refreshes the page, verifies the UUID survived with only a scoped UUID in application sessionStorage, explicitly retries and asserts the original receipt ID, one history row and one cash increment. Existing changed-detail422 and immutable closing assertions remain. Treat this as verified only after the latest exact-head browser CI passes.

The form also submits its expected campus key. The server rejects a stale campus after another tab changes the shared active campus, before either new collection or replay. Refresh/reselect the intended authorized campus; never silently retarget cash.

## Read-only legacy retail invoice access

Receipts / Reprint now links to **Legacy Retail History**, a separate read-only archive. It searches original invoice numbers/stored fiscal references and displays original stored line amounts, invoice totals, mode, date, status and fiscal number. It never turns a retail invoice into a school receipt, recalculates historical tax, adds school cash, sends/retries fiscal data, creates a print job or modifies the underlying rows. Browser print produces an archive copy, not a new fiscal invoice or a pixel-identical original thermal receipt. No invented QR or raw FBR response/log payload is displayed.

Access is scoped to the authenticated company and current authorized campus. An explicit owner-only unassigned view exposes only that company's legacy null-campus rows, not all campuses. Confidential local invoices stay hidden/denied until the existing rate-limited PIN is verified; the archive proof is bound to company, signed-in user, current PIN hash and a 30-minute expiry, without widening retail-panel PIN access. Read-only responses are private/no-store.

This enables historical invoice discovery after an eventual approved category change. It is not evidence that any particular live invoice was inspected, and does not authorize or perform a live category switch. Old retail day-close/tax exports, pending fiscal/agent work and operational cutover still need the separate preservation/reconciliation plan above. The owner must approve and run deployment first; confirm the deployed SHA and specific original invoice access before declaring live migration verified.
