# Restaurant POS customer follow-up

Recorded on 19 November 2026 after review of supplied customer evidence and the owner's corrections.

Status is a Draft scope and acceptance register. Application implementation and current-build reproduction remain pending. This commit contains this register only.

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
