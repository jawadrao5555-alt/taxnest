# School workspace completion follow-up

## Baseline and boundary

Starts from main `70f002cc4d32d0fb71b42256230d41cba8dd4e6a` (#392). Owner-controlled deployment run 43682177750 succeeded, including the post-deploy exact-SHA verification. This implementation uses synthetic local fixtures only; no live company settings, bills, payments or closings are changed. It does not merge or deploy.

## Bounded collection design

This is an administrative school collection workspace, not Full School ERP (#314). Minimal student lookup consists only of tenant/campus-scoped reference and display name attached to the local collection record. It does not add admissions, classes, attendance, parent accounts, academic records, recurring fee scheduling or automatic arrears migration.

A new local fee account groups multiple configured fee-head lines for a selected period. Its payable amount and line labels are snapshotted. A payment records the amount actually received and the cashier-selected cash/card/bank method; it does not acquire a card payment or initiate a bank transfer. The outstanding balance is local payable minus recorded payments. Each partial payment has its own immutable receipt; same-request retries recover that exact receipt, and changed payloads are rejected. Campus/company authorization applies to lookup, accounts, receipts and balances.

Existing single-head receipts and closed cash snapshots remain unchanged and accessible. No conversion of historical records into new accounts is performed. New closing cash totals include only cash payments; noncash receipts remain reportable separately. Closed snapshots remain immutable under retries and later campus configuration.

## Workspace acceptance

- School navigation, shared settings and impersonation exit; profile, team/campus, printing, language, billing/help access appropriate to role.
- No retail/KOT/delivery/wholesale actions in School; historic retail invoices/reports are explicitly read-only archives.
- Fiscal OFF/blocked wording on School surfaces, with server guards unchanged across automatic/manual/queued/fallback dispatch.
- Accurate School receipt preview, saved logo/contact/paper/printer preferences, receipt date/time/period/campus/cashier and close variance/handover.
- Campus onboarding, mobile and translation coverage, and stale-screen recovery without repeating financial actions.

## Verification

Pending implementation and final synthetic checks. Do not infer runtime acceptance from added test code. Required local/hosted PHPUnit, dependency audit, native MariaDB concurrency, desktop/mobile browser acceptance and PRA/retail regressions must pass on the final published head. Screenshots must contain synthetic data only. Keep PR draft while required checks are pending or failing.

## Expanded owner-authorized Hospital and platform scope

The owner subsequently included Hospital and signup/admin/PRA findings in this same follow-up. Hospital remains administrative POS: optional patient reference/name lookup; a tenant service catalogue with editable default rates; multiple service snapshots; recorded cash/card/bank payment references; paid/partial/settled history, HI-number search, balances and immutable receipts/closings. No clinical chart, insurance workflow, HMIS, card acquiring or bank transfer is introduced. Existing single-service invoices and saved payment/closing fields are preserved by additive migration tests. Production Hospital verification is pending because no live Hospital account was identified; this PR does not create one.

Both local pilots have category-specific shared navigation/profile/printing/preferences and server-side restrictions against retail/fiscal mutations. Printing device names are reminders only: actual device selection remains in the browser/system print dialog. Preview uses the real receipt template with a clearly synthetic sample and never inserts a payment. New service-worker tests enforce no caching of private/no-store collection documents and refuse poisoned cached finance screens offline; retail sale-specific offline behavior remains separately tested.

Admin product switching requires a SaaS super-admin, explicit target category/package and a pristine source account. Existing tenant history/configuration/access blocks in-place switching with a clear separate-account alternative. Cloning validates the target product/category/package, copies the owner profile only, leaves original history/access untouched and creates a distinct trial. A selected paid package is a request, never a grant of paid entitlement. Fiscal reporting is not automatically enabled. Public FBR and admin creation offer explicit package/trial selection. PRA labels distinguish actual reporting ON/OFF and queued delivery from an accepted acknowledgement.

### Current validation checkpoint

- School ledger, printing, workspace/boundary and migration preservation focused tests passed.
- Hospital ledger, printing, workspace/boundary, settings and migration preservation focused tests passed.
- Signup/admin package focused checks passed; product-transition compatibility has dedicated server-side checks.
- Composer locked audit: no advisories or abandoned packages at this checkpoint.
- JavaScript/PHP syntax and whitespace checks pass for changed acceptance probes.
- Full local PHPUnit was started before the Hospital scope expansion; it is diagnostic only, not final-head acceptance. Hosted full PHPUnit must run against the published complete tree.
- Local browser/native execution was refused by the committed guard: installed MariaDB 11.8.6 does not match required 10.6. No guard was weakened. Hosted pinned MariaDB 10.6 jobs must execute new and existing native race probes and desktop/mobile browser scripts; screenshots are synthetic sanitized CI evidence.

Final pre-publication focused checks: combined School/Hospital ledger, receipts and migration compatibility **69 tests / 601 assertions passed**; shared workspace/boundary/settings **71 / 360 passed**; product transition compatibility **4 / 26 passed**; signup/admin package **33 / 204 passed**. Earlier legacy admin type smoke was adjusted to require an explicit valid target category and trial choice; no existing populated account is silently relabelled. These focused results do not substitute for full final-head hosted acceptance.

### Credential-safe shared surfaces

Pilot team access uses a dedicated controller selecting only safe user/profile/role/campus columns and a separate view. Stored passwords/encrypted credentials and retail identity-counterpart controls are never loaded or displayed. Existing retail team rendering remains unchanged. Product-switch/clone quick actions first redirect to a dedicated credential-free target-review page when selections are missing; source company credentials are not rendered on that review. The existing SaaS company-detail template is unchanged. Added tests cover safe pilot team fields, role/tenant access, legacy pairing routes and review-before-mutation behavior.

Focused creation/transition handlers are separate controllers; the monolithic SaaS admin controller is unchanged. Combined transition/privacy/pairing/admin smoke: **49 tests / 369 assertions passed** after isolation. Signup/creation: **56 / 421 passed**. The diagnostic pre-freeze full run found pairing context and outdated signup-render expectation failures; both affected suites passed after correction. Final hosted full-suite acceptance remains mandatory and is not replaced by these focused results.
