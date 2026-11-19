# Restaurant POS customer follow-up

Recorded on 19 November 2026 after review of supplied customer evidence and the owner's corrections.

Status is Draft implementation in progress. Requirement-level acceptance remains open until the corresponding application, database and browser verification is recorded below.

## Relationship to existing work

| Existing PR | Current relationship |
| --- | --- |
| #304 | Closed B03 requirements container. Its phone, customer, checkout and printing requirements provide historical context. |
| #324 | Merged B03 implementation. Reuse normalized phone search, server-side Reprint filters, table reservation and draft safeguards. |
| #317 | Merged financial-history and reporting implementation. Reuse retained business-day totals and grouped product reconciliation. |
| #329 | Merged customer bulk. Reuse report summaries, checkout handoff, receipt settings, exports and scoped dashboard detail. |
| #371 | Merged order-specific delivery contact and receipt snapshot implementation. Verify the reported address gap through that path. |
| #384 and #385 | Merged dashboard/report layout and business-date/cashier drilldowns. Verify the remaining stream context and lower dashboard report. |
| #311 | Merged optional Local/Fiscal receipt controls. Existing tax visibility remains the starting point for item 13. |
| #321 | Open planning overlap for useful New indicators and targeted announcements. Item 8 owns the specific reported Customize gap in this follow-up. |
| #381 | Open Android Rider push/reconnect refresh work. Item 6 concerns the counter/browser delivery list and needs its own reproduction. |
| #412 | Separate Admin Company Usage and Agent Health release. These Restaurant POS issues have their own acceptance below. |
| #237 | Owner HOLD concerning the online-confirmation design. Item 10 repairs the currently supported completion flow and carries no change to that parked design. |

## Thirteen reported outcomes

Each unchecked item requires reproduction or verification on the current build, implementation where a gap exists, and a recorded retest.

| Item | Reported problem and expected outcome | Reuse and remaining work |
| --- | --- | --- |
| 1 | Select Table can display a connection error alongside retained table amounts. The board should identify freshness and actionable failure accurately. | Reuse table-status and reservation paths. Check concurrent refresh ordering, 304, authentication expiry, HTTP failure, timeout and recovery without losing the cart. |
| 2 | A new delivery address is difficult to read and does not visibly save. A successful save should select the persisted address for the order. | Reproduce the narrow optional-label/full-address layout. Add usable fields and explicit validation/save feedback, preserve company-scoped identity and duplicate handling. |
| 3 | Cash Received/Change is enabled in receipt customization but absent from table finalization. It should work on the supported table/payment paths. | Standard checkout already uses pos_cash_received_enabled. Extend the relevant table flow with one confirmed payment and the existing order/payment identity. Verify setting ON/OFF, different table sources, print choice and retry. |
| 4 | Customer/order address is missing from the printed takeaway or delivery receipt. The intended saved order contact should survive checkout and reprint. | Trace profile selection, order-only override, hold/recall and transaction snapshot through the existing #371 path and both thermal widths. Header and QR absence were not established as the reported defect. |
| 5 | Dashboard Local sales and lower Reports appear inconsistent, with the report showing zero. Totals should reconcile within the same visible scope. | Reuse #317/#329/#384/#385. Verify sales stream, business date, branch and cashier. The current dashboard report link omits the stream tab and the report can default to PRA. Include readable labels in saved themes. |
| 6 | Clearing deliveries with Select All leaves the browser list stale until reload. The list should reflect the confirmed result promptly. | Reproduce the exact counter action. Refresh the relevant list and totals after confirmed mutation and supported external changes. Preserve cash that still requires settlement. Android #381 is adjacent work. |
| 7 | A saved address needs an Edit action for corrections to an existing address. | Reuse the customer-address record and permissions. Distinguish editing the customer profile from an order-only override. Historical bill snapshots remain the reprint source. |
| 8 | New options added to Customize have no useful New indication or announcement. | Reuse the existing release-notice/read-state system and #321 requirements. Verify direct destination, product/role targeting, first view and read persistence. |
| 9 | The Reprint search appears to omit phone-number lookup. Phone lookup should be discoverable and find permitted matching bills. | #324 already implements normalized phone filtering. Verify UI text, supported phone formats, date/branch/payment filters and saved customer contact before adding any new search path. |
| 10 | A table remains pending or occupied after its online payment has been confirmed and cleared. | Reproduce current card/bank/online confirmation and final table refresh. Verify durable payment, order status, retries and concurrent terminals. Reuse current payment guards and idempotency. |
| 11 | Top/Regular Customer cards show only a few customers. View All should open the corresponding complete customer list and useful customer filters. | Map the actual Top/Regular/Gone Quiet card to its correct source. Reuse the existing customer directory, permissions and pagination. This item is separate from report exports. |
| 12 | Reports need category, item/product and date filtering with a PDF matching the selected filters and totals. | Reuse existing Sales/Tax report routes, tabs, filtering and exports. Verify which controls already exist, add missing filters, and maintain one consistent query for HTML/PDF and permitted sales scope. |
| 13 | The receipt tax line needs an accessible Show/Hide option analogous to Subtotal visibility. | Current Receipt Settings already have separate PRA and Local tax visibility. Verify navigation, saved receipt type, preview and both renderers. Repair the actual gap using the existing option and verify monetary display. |

## Acceptance checklist

- [ ] 1. Accurate and ordered table-board refresh with recoverable failures.
- [ ] 2. Readable new-address form and persisted selected address.
- [ ] 3. Table Cash Received/Change follows the enabled setting.
- [ ] 4. Address survives the intended order and receipt path.
- [ ] 5. Dashboard and lower Reports use matching visible filters and totals.
- [ ] 6. Confirmed delivery clearing refreshes browser rows and totals.
- [ ] 7. Existing address editing works with correct scope.
- [ ] 8. Customize additions use useful targeted New/read-state behavior.
- [ ] 9. Existing Reprint phone search is discoverable and verified.
- [ ] 10. Confirmed online payment produces correct bill and table state.
- [ ] 11. Customer card View All opens the correct complete list.
- [ ] 12. Selected report filters match on-screen and PDF results.
- [ ] 13. Existing per-receipt tax visibility is accessible and verified.

## Verification record required before release review

For every item, record the failing case or verified existing outcome, the source cause, the resulting change and a retest. Use fictional local fixtures and the established company/branch/role checks.

Relevant regression coverage includes table refresh ordering and concurrent reservations, delivery refresh with unsettled cash, address profile versus order snapshots, table cash and online retries, receipt settings for both streams and thermal widths, report scope across the business-day boundary, customer pagination, and filtered PDF parity.

Verify desktop and 390px layouts with actual controls. Run focused tests, the required native database and browser lanes, the full suite, and the final validate check on the release head. Record physical receipt acceptance separately when printer output matters.

Customer media, recordings, identifying details, operational credentials and private audit material stay in their original private evidence locations. Public fixtures and acceptance examples use fictional data.


## Owner consolidation decision

On 19 November 2026 the owner requested the complete pending work of the referenced older PRs to be covered by this bulk, with source PR closure after implementation, production deployment and verification.

The existing merged implementations in #311, #317, #324, #329, #371, #384 and #385 are part of the main baseline. Their relevant behavior receives regression verification through the thirteen outcomes above.

Open source PRs #321 and #381 are closure candidates under the complete scope below. Each requirement retains its source identity and verification evidence.

### Complete #321 carry-forward

Authoritative source contract is the file at commit 007070b434b1931f773558d8016185ba99cdf466
https://github.com/jawadrao5555-alt/taxnest/blob/007070b434b1931f773558d8016185ba99cdf466/docs/planning/bulk-onboarding-support-experience-2026-10-30.md

That complete source contract, its B08-R01 through B08-R11 requirements, B08-A01 through B08-A05 acceptance cases, dependencies, evidence ledger and audit mappings remain the completion standard.

- [ ] B08-R01 Product, package, trial, pricing, approval and policy acknowledgement across public and admin onboarding.
- [ ] B08-R02 Business-specific setup and validated catalogue/opening-stock workflows with consistent tenant configuration.
- [ ] B08-R03 Public product capabilities and readiness claims supported by evidence.
- [ ] B08-R04 Accessible mobile, keyboard, labels, error feedback and saved-theme workflows.
- [ ] B08-R05 Company-linked support ownership, timestamps, verified resolution and reopen.
- [ ] B08-R06 Scoped assistant answers, sources, unavailable data and issue escalation.
- [ ] B08-R07 AI usage, cost, latency, budgets, quotas, errors and escalation history.
- [ ] B08-R08 Targeted New indicators and persistent per-user/version read state, including item 8 above.
- [ ] B08-R09 Tutorial discovery, audience, captions, version ownership and notification/survey relevance.
- [ ] B08-R10 Validated profile fields and owner-configured greeting preferences with synthetic delivery/retry/dedup verification.
- [ ] B08-R11 Established Admin, customer-screen and printed-ticket language requirements.
- [ ] B08-A01 Product/package/price and stock-import consistency acceptance.
- [ ] B08-A02 Desktop/mobile, keyboard, focus, validation and theme acceptance.
- [ ] B08-A03 Audience and read/unread persistence acceptance.
- [ ] B08-A04 Verified support closure and reopen acceptance.
- [ ] B08-A05 Assistant authorization, hostile-source, quota, revocation and greeting-calendar/dedup acceptance.

### Complete #381 carry-forward

Source head is 0aaeb0099eafe2b7383feb746704a05981a8001c.

- [ ] Refresh the already-open Android Rider list after delivery push, assignment, reassignment and final-bill unassignment.
- [ ] Refresh after reconnect reconciliation and outbox drains, coalesce bursts, and reject responses from a changed login session.
- [ ] Verify permission-denied/no-push behavior, notification tap, existing poll fallback and account switching.
- [ ] Reconcile version/build metadata with current main and verify install-over on a signed test phone.
- [ ] Record hosted Android verification and actual phone acceptance for the complete source contract.

### Coverage and closure ledger

| Source | Coverage target | Verification needed before closure | Closure state |
| --- | --- | --- | --- |
| #321 | Complete B08 contract and acceptance above | Requirement-level code/test mapping, exact implementation head, green required CI, owner-approved production release and relevant live outcomes | Open |
| #381 | Complete Rider refresh contract above | Code/test mapping, Android build/tests, signed install-over and actual phone acceptance, applicable release evidence | Open |
| #311, #317, #324, #329, #371, #384, #385 | Existing merged baseline plus relevant follow-up regressions | Regression and post-release results for the affected outcomes | Already merged/closed |

For a closure candidate, record the implementation commit and release containing every remaining source requirement, attach its actual verification results, then close the superseded open PR. Keep partial completion and remaining source requirements visible in this ledger until the whole candidate is verified. Planning checkmarks and a merged branch alone do not establish the deployment and behavior outcomes.

#412 continues through its own Admin release. Its deployed result will become part of this bulk's main baseline.

## Implementation checkpoint: table refresh

Baseline now includes #412 merge `2c3345306c51166bf170ddd35ad1b64033ae715d`. The [production run](https://github.com/jawadrao5555-alt/taxnest/actions/runs/44588503997) applied that exact commit and passed the SHA and health checks, but failed the Day Close `dc-auto-close-chk` assertion. That release is **not live verified**. The logs establish the missing assertion; they do not yet establish why the rendered control is absent.

Item 1 reproduction used the actual baseline `loadTableStatus()` JavaScript with two deferred requests: accept the newer table response, then reject the earlier request. The baseline retained the new table and ETag but set `tablesError=true`. The correction ignores obsolete responses, body parsing and failures; only the latest request controls loading, the accepted board and its ETag. HTTP access errors, server errors and transport errors have distinct messages. Both table surfaces retain the previous board and identify stale data, with the time of the last successful refresh.

| Compatibility case | Checkpoint evidence |
| --- | --- |
| Reported overlapping refresh failure | Extracted application method: newer successful board survives older timeout and older body completion |
| Previously working ETag refresh | 304 retains the accepted body and clears a recoverable error |
| Empty, malformed or inaccessible response | Real empty array remains valid; malformed data does not erase the board or update its validator; 401/403/419 and server errors remain distinct |
| Cart, reservation and KOT behavior | Cart identity is unchanged by failed refresh; existing concurrent table-selection and auto-KOT tests pass |
| Multiple tenants and terminals | Endpoint and authorization contracts are unchanged; each page owns its request sequence; existing server-side tenant/ETag tests still require the PHP lane |

Executed locally: `node --test scripts/tests/restaurant-table-refresh.test.mjs scripts/tests/b03-table-commit.test.mjs scripts/tests/b03-phone-search.test.mjs scripts/tests/b03-delivery-navigation.test.mjs scripts/tests/customer-bulk-print-flows.test.mjs` — **34 passed**. The new refresh tests are included in the existing PR checks customer-flow lane. `git diff --check` passed. `npm run build`, the source-only Tailwind checks and the landing asset contract passed. The local CSS rebuild omitted vendor pagination rules because Composer dependencies are absent, so the existing committed CSS/manifest are retained; comparison showed no new CSS rules required by this change. A complete dependency build remains required in CI.

Limitations: this environment has no PHP executable, Composer or MariaDB, and no sudo installer. Full Laravel rendering, native database verification, desktop/390px application browser acceptance, body-stream timeout behavior and remaining requirements are pending. Node method tests are not application browser acceptance. No acceptance checkbox or source closure is earned by this checkpoint. #381 still requires actual signed test-phone acceptance.
