# Admin and PRA POS improvement programme

Owner scope captured on 26 October 2026 (Asia/Karachi). This is the master implementation checklist for the six requested workstreams. Every requirement below is retained so later development does not lose the original scope.

## How to use this record

- Keep the issues below as the detailed work records; link each focused implementation PR back to its workstream.
- An existing draft PR is code available for review, not completed release acceptance. Keep the relevant checkbox open until its required evidence is recorded.
- Record implementation PR, tested head SHA, reproduction/retest evidence, regression/tenant/configuration checks, owner approval and release/live-verification evidence when applicable.
- This planning PR records scope only. It does not change runtime behavior, company data, subscriptions, prices or live configuration.
- Production release remains the owner's/admin's explicit approval workflow. The agent must not merge or deploy. Preserve existing customers and financial history throughout the programme.

## Workstream index

| Order | Work record | Scope |
|---|---|---|
| 1/6 | [#295](https://github.com/jawadrao5555-alt/taxnest/issues/295) | correct records, deal tax, stock valuation and financial history |
| 2/6 | [#296](https://github.com/jawadrao5555-alt/taxnest/issues/296) | two-step login, consistent themes and company approval timeline |
| 3/6 | [#297](https://github.com/jawadrao5555-alt/taxnest/issues/297) | accounts ledger, full-payment approval, 3-day trial and 7-day grace |
| 4/6 | [#298](https://github.com/jawadrao5555-alt/taxnest/issues/298) | reliable combo/choice/Special Deals and scheduled percentage promotions |
| 5/6 | [#299](https://github.com/jawadrao5555-alt/taxnest/issues/299) | simple ingredient conversions, recipe costing, single Excel and profile greetings |
| 6/6 | [#300](https://github.com/jawadrao5555-alt/taxnest/issues/300) | permission-scoped AI answers and issue-to-tested-PR workflow |

## Existing implementation drafts

Snapshot when this tracker was created; follow each PR for current checks/release state.

| PR | Implemented scope in draft | Workstream | Release acceptance |
|---|---|---|---|
| [#291](https://github.com/jawadrao5555-alt/taxnest/pull/291) | Company/product package compatibility | [#295](https://github.com/jawadrao5555-alt/taxnest/issues/295) | Open |
| [#292](https://github.com/jawadrao5555-alt/taxnest/pull/292) | Read-only usage and real Last Staff Activity | [#295](https://github.com/jawadrao5555-alt/taxnest/issues/295) | Open |
| [#293](https://github.com/jawadrao5555-alt/taxnest/pull/293) | Failed admin login attempt limits | [#296](https://github.com/jawadrao5555-alt/taxnest/issues/296) | Open; two-step verification remains separate |
| [#294](https://github.com/jawadrao5555-alt/taxnest/pull/294) | Shared admin themes and readable notices | [#296](https://github.com/jawadrao5555-alt/taxnest/issues/296) | Open; remaining screen coverage still required |

## 1/6: correct records, deal tax, stock valuation and financial history

Work record: [#295](https://github.com/jawadrao5555-alt/taxnest/issues/295).

- [ ] Last Staff Activity must come from real login/session activity; opening admin screens must not alter it. Existing implementation draft: #292.
- [ ] Reject packages belonging to another product/business type before changing company subscriptions; retain supported legacy aliases and existing history. Existing implementation draft: #291.
- [ ] Correct exempt-product tax in fixed/choice deals. Handle mixed taxable/exempt components proportionally and consistently in cart, final payment, printed bill, fiscal payload, returns and reports; preserve the sale-time tax/allocation snapshot.
- [ ] Reconcile stock totals, in-stock/out-of-stock counts and orphaned product rows without resetting valid inventory or the existing negative-stock policy.
- [ ] Make stock-value basis explicit and consistent (cost versus selling value); reconcile counts and values between dashboard, list and reports.
- [ ] Freeze recipe consumption/cost evidence at the transaction date so later ingredient rate/recipe edits do not rewrite old profitability or returns.
- [ ] Preserve posted payment, receipt, subscription and financial history through company archival/deletion; define audited correction/reversal and retention handling rather than silently deleting financial records.

Acceptance: reproduce each discrepancy, reconcile line and report totals including rounding, verify returns/history after rate edits, and test an untouched second tenant and previously working configuration.

## 2/6: two-step login, consistent themes and company approval timeline

Work record: [#296](https://github.com/jawadrao5555-alt/taxnest/issues/296).

- [ ] Limit failed admin login attempts without bypass through email casing/identity rotation; preserve intended redirects, session regeneration and existing roles. Existing implementation draft: #293 (attempt limiting only).
- [ ] Implement two-step verification, enrollment, recovery codes/recovery procedure, challenge expiry/replay protection, auditing and a safe owner onboarding path; do not unexpectedly lock out existing admins.
- [ ] Apply chosen theme consistently across admin pages, inner tabs, forms and modals; keep saved theme choices and readable functional text, inputs and selected/inactive states. Existing implementation draft: #294; remaining screen coverage must still be checked.
- [ ] Make top flash/validation/operational errors readable across all themes and mobile/desktop; retain accessible status/alert roles. Existing implementation draft: #294.
- [ ] Add a consolidated company timeline: selected package, trial, paid start/end, grace/lock status, payments/receipts, approval/payment-confirmation actor/time and changes/overrides. Show when approval starts and ends.

Related prior readability tracker: #209. Coordinate rather than duplicating its historical evidence.

Acceptance: verify login challenge/recovery and role boundaries; test all six themes and inner-page interactions, actual contrast and error states; timeline must reconcile to immutable payment/subscription records, with no cross-company information.

## 3/6: accounts ledger, full-payment approval, 3-day trial and 7-day grace

Work record: [#297](https://github.com/jawadrao5555-alt/taxnest/issues/297).

- [ ] Admin Accounts: income by source/system/company, expenses with category/reference, receipts, company ledger, filters/totals and complete audit trail. Distinguish shop sales volume from TaxNest collections.
- [ ] Capture payment date, amount, method/reference, company, selected package, receiving/confirming admin and notes; retain corrections/refunds/reversals with traceable history.
- [ ] New company selects a package and receives 3 full days (72 hours) of that package's trial with unlimited bills. Clearly show trial start/end and entitlements.
- [ ] Activate a paid term only after full required payment is confirmed. Receipt, received payment entry, company approval and subscription period must commit together or roll back together; protect duplicate clicks, concurrent confirmations and retries.
- [ ] Start the paid package period after the trial ends, without consuming trial days even when payment arrives early. Specify and test late-payment/renewal boundaries; never backdate or grant an unintended free paid period.
- [ ] On paid expiry, show repeated renewal reminders and allow work for a full 7-day grace period; lock normal work after grace. Keep a renewal/payment route available and clearly show dates/status.
- [ ] Preserve existing paid periods, chosen package/feature settings, manual/temporary/free/grace overrides and historical financial evidence; document any explicit migration for new rules.
- [ ] Company details and Accounts must show the same receipt, approval actor/time, approved-from/to and trial/paid/grace dates.

Dependencies: record/history safeguards in programme 1 and company timeline in programme 2. Coordinate with existing DI billing-cycle request #263; this programme does not silently cancel or implement that separate pricing/cycle request.

Acceptance: test selected-package entitlements/unlimited trial bills, exact 72-hour and 7-day boundaries/timezone, early/late/full/partial payment, duplicate/concurrent confirmation, rollback, renewals, reminders, lock/recovery, tenant isolation and preservation of existing customers. All totals must reconcile to receipts and ledger entries.

## 4/6: reliable combo/choice/Special Deals and scheduled percentage promotions

Work record: [#298](https://github.com/jawadrao5555-alt/taxnest/issues/298).

- [ ] Retest fixed combos, choice-only/mixed deals and Special Deals through create/edit/activate/deactivate, weekday/date/time availability, quantity quotas, checkout, stock consumption, cancellation and returns.
- [ ] Correct misleading choice-only deal summaries, inconsistent required/optional fields and error feedback; preserve existing working deals and multiple counter/device configurations.
- [ ] Add a promotion for all products or a selected product set, with a percentage, title (Opening/Summer/14 August/Anniversary), start/end dates and an explicit company-timezone schedule. Start and stop eligibility automatically.
- [ ] Define a visible, deterministic eligibility/priority policy for overlapping deals, promotions, item and invoice discounts; prevent the same discount being applied twice or silently stacked.
- [ ] Revalidate promotion eligibility and totals on the server; do not trust a stale cart or client-posted price/percentage. Specify offline and queued-bill behavior without rewriting historical bills.
- [ ] Freeze promotion identity/title/percentage, allocation and discount amount in the bill snapshot; display the discount clearly and retain it for reports/refunds/returns.
- [ ] Preserve taxable/exempt component treatment and paisa-level reconciliation (coordinate programme 1).

Acceptance: test before/start/end/expired dates, all/selected products, overlap/double-discount attempts, quantity edits, zero/invalid values, choice variants, mixed exemption, bill totals, stock/returns and an independent tenant. No regulator submissions in local tests.

## 5/6: simple ingredient conversions, recipe costing, single Excel and profile greetings

Work record: [#299](https://github.com/jawadrao5555-alt/taxnest/issues/299).

- [ ] Automatically convert purchase to usage units (for example buy kg, consume grams), including quantity and per-unit cost, throughout receiving, recipes, consumption, wastage and returns. Reject incompatible units instead of guessing.
- [ ] Show recipe cost, wastage and available servings clearly; make displayed cost agree with actual consumption and preserve historical cost snapshots (programme 1).
- [ ] Simplify the ingredient/recipe workflow and clarify which inputs are required; retain saved units, conversion factors, recipes and supported older records.
- [ ] Correct the single Excel workbook instructions to match its actual sheets/columns. Provide a validated preview with row-level errors, unit/recipe references, tenant checks and duplicate handling before confirmation.
- [ ] Make stock receiving a separate explicit step with quantity, unit, cost, supplier/reference and posting status. Catalogue/recipe import must not silently create duplicate opening stock or receipts.
- [ ] Profiles: capture mobile number, email, CNIC and DOB with appropriate validation and scoped access; retain existing identity/contact information.
- [ ] Record welcome, birthday and completed-year/anniversary greetings sent on behalf of the company. Define channel/templates/preferences and company-local dates; keep delivery/failure/retry history and prevent duplicates. Development tests use synthetic recipients and mocked delivery.

Related unit-edit tracker: #45. Coordinate the existing issue and preserve its working configurations.

Acceptance: reconcile kg/g quantity and rate examples end to end; test waste, stock-limited servings, historical cost, incompatible units, repeated import/receiving, rollback, second-tenant references, missing DOB, leap-day birthdays, yearly greeting deduplication and delivery retries.

## 6/6: permission-scoped AI answers and issue-to-tested-PR workflow

Work record: [#300](https://github.com/jawadrao5555-alt/taxnest/issues/300).

- [ ] Review existing assistant integration/settings and choose an appropriate integration path; do not assume the current ChatGPT session can simply replace the application model or silently change saved provider credentials/settings.
- [ ] Answer from the signed-in company's permitted data through audited, role/tenant-scoped read tools, with clear source/date context. Say when data is unavailable; do not invent figures or treat company content as instructions.
- [ ] Support practical billing, tax/exempt breakdown, payment, inventory/recipe and operational questions using authorized tools. Never grant a company user administrator or cross-company access.
- [ ] Record issues with company/module, time, relevant diagnostic references, reproduction details, status and duplicate linkage; redact credentials and unnecessary personal information.
- [ ] Development workflow: retrieve permitted diagnostics, reproduce in disposable local fixtures, propose/fix code, run regression/tenant/configuration checks and attach a reviewable focused PR with evidence.
- [ ] Keep read-only answers separate from privileged development actions. Development automation must not have production secrets, automatic merge/deploy or a route for prompt-driven permission escalation.
- [ ] Production release remains the owner's/admin's explicit approval workflow; keep a traceable issue -> PR -> checks -> approval -> release record.

Related operational reporting/incident-trace tracker: #290. Reuse its permitted diagnostics/reporting path and retention work rather than requiring repeated production access or recreating Live Ops.

Acceptance: test allowed/denied roles, two tenants, source grounding, unsupported questions, prompt injection, issue deduplication, audit/redaction and the reproducible issue-to-PR handoff. A code fix or green CI alone is not evidence of a live release.

## Completion and dependency record

- [ ] Workstream 1: records and calculations accepted.
- [ ] Workstream 2: login, themes and company timeline accepted.
- [ ] Workstream 3: accounts, payment and subscription lifecycle accepted.
- [ ] Workstream 4: deals and promotions accepted.
- [ ] Workstream 5: inventory, recipes, workbook and profile greetings accepted.
- [ ] Workstream 6: permission-scoped assistant and issue-to-PR handoff accepted.

Use the numbered issues for evolving evidence and remaining work. Closing or merging this planning PR must not automatically close implementation issues. Refer to them with “Tracks” rather than automatic issue-closing keywords.

Suggested implementation sequence follows the owner's list: establish record/calculation/history correctness, complete login/theme/timeline coverage, then build payment/ledger/lifecycle on that foundation; follow with deals/promotions and inventory/recipes/profile greetings, and integrate permission-scoped AI tools with the accepted records. Independent focused corrections can proceed without changing that dependency order.

Shared dependencies include the company timeline and financial history, sale-time tax/discount/cost snapshots, tenant/role authorization and idempotent payment/import/message processing. Refer to existing #209, #45, #263 and #290 in their linked workstreams; do not replace their prior evidence or silently change their scope.
