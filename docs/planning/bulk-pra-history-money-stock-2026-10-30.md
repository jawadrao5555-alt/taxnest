# B02 — PRA financial history, day close, cash, stock and deals

**Primary source records:** #295, #298, #299 (inventory/import), #45; reuse merged #312 consent/deletion safeguards.

**Dependencies:** Check #312 implemented and deployed scope first. Coordinate shared commercial fields with B01, item/packing semantics with B04 and diagnostics with B06.

## Delivery contract

This is an owner-requested bulk scope/acceptance record, dated 30 October 2026 (Asia/Karachi), under master #301. **Documentation/planning only: no runtime fix, test execution, readiness, deployment or completion is claimed by this update.** The owner explicitly requested related bulk PRs; preserve that grouped delivery while keeping separable internal checkpoints and dependencies.

- Reconcile current code, merged sources and deployed-version evidence before implementing; a missing control in a read-only role is not automatically a missing feature.
- Reported media/UI evidence is an investigation input. Reproduce with fictional local tenants before claiming root cause. Preserve earlier evidence classification and unresolved ambiguity.
- Universal fix for compatible tenants; preserve saved settings, permissions, paid terms, immutable financial/fiscal history and supported legacy Agents. Missing values receive safe defaults only; existing values are not silently reset.
- Reuse canonical services/policies. Optional changes require the established explicit selection/consent; ordinary bug fixes do not need a new switch for every symptom.
- Validate the original failure, a previously working configuration, a second tenant and relevant one/many branch/device/printer topology. Use real local Chrome/MariaDB when affected, plus meaningful negative/concurrency/rollback tests.
- Ready means reviewed exact implementation head, relevant acceptance, clean mergeability and latest required CI including validate. Documentation CI is not feature acceptance.
- Deployed and Live verified are separate states. Owner release approval remains required; physical Windows/printer, central fiscal and connected-client outcomes require actual evidence beyond generic CI.
- No production credentials/customer datasets, raw media or private amounts in this public repository. No customer communications, settings changes, merge, deployment, fiscal retry, reprint or grant change is authorized by this document.
- #237 remains HOLD. Source PRs remain traceable; this plan does not automatically close or merge them. Unrelated earlier holds remain intact.

## Evidence ledger

| Checkpoint | Implementation PR / exact head | Reproduction and retest | Required CI / UI / DB / device | Owner release / deployed SHA / live proof | Remaining gaps / state |
|---|---|---|---|---|---|
| Scope captured 30 Oct 2026 | This planning record | Not executed here | Document coverage review only | None | Queued; original whole-outcome checks remain open |

For each implementation stage, add actual evidence and its date. Do not overwrite historical rows or mark the whole bulk complete after one partial slice.

## Required scope

- [ ] B02-R01 — Distinguish cancelled open orders, archived local bills, optional support copies, deleted detail, immutable posted records and returns/refunds. Show business date, stable IDs, actor/time/reason and actual recovery availability.
- [ ] B02-R02 — Preserve existing Save/Delete preferences and #312 owner-only default-OFF prospective support-copy consent. No automatic opt-in, old-record recovery promise, forced archive or duplicate consent engine. Verify actual owner and read-only impersonation behavior separately.
- [ ] B02-R03 — Reconcile retained day-close totals with historical local/fiscal/combined reports, cashier/payment/category summaries and exports. Unavailable/deleted detail is not zero activity; declare rounding, missing breakdowns and scope.
- [ ] B02-R04 — Cancelled-order reason and prepared-quantity evidence; unknown preparation is not zero waste. Link cancellation, inventory movement, return and cash effect without inventing a finalized invoice.
- [ ] B02-R05 — Business-day cutoff and daily token reset consistent across search, reports, returns and diagnostics; keep permanent serial identity and distinguish gross, returns, net and refund settlement.
- [ ] B02-R06 — Permissioned selected-order shop-received cash settlement for eligible unassigned deliveries, with amount/count preview, actor/time and immutable audit; delivery complete does not mean cash collected; repeat settlement safe.
- [ ] B02-R07 — Fix mixed taxable/exempt fixed/choice/Special Deals using immutable allocation/rate/discount/cost snapshots across preview, posting, payload, stock, returns and reports. Preserve each product's established rounding convention.
- [ ] B02-R08 — Complete #298 scheduled all/selected-product percentage promotions: title, timezone, start/end, deterministic overlap/non-stacking, server revalidation, offline/stale-cart behavior and historical snapshots.
- [ ] B02-R09 — Complete #299 stock chain: explicit receiving, compatible purchase/base/recipe unit and cost conversions, waste/servings, frozen recipe cost, valuation basis and movement counts.
- [ ] B02-R10 — Single workbook preview with real sheet/column guidance, row errors, duplicate policy and cross-tenant references; catalogue import never silently posts stock. Repeated receipt/import cannot double stock.
- [ ] B02-R11 — Group drinks by category/size/product with standalone/deal component separation; subtotals and exports reconcile without double revenue/counting.

## Bulk acceptance

- [ ] B02-A01 — Current and prior business-day cancellation/archive/return lookups work with clear stage identity; repeat close cannot duplicate financial/stock effects.
- [ ] B02-A02 — Save/Delete/consent OFF/ON/expired/stopped/purged behavior and late fiscal callback/return locks preserve #312 invariants; unavailable history labelled honestly.
- [ ] B02-A03 — Local/fiscal/combined totals, cash/card/other, returns, rounding adjustments, cashier/category and downloads reconcile on synthetic closed and open cross-midnight days.
- [ ] B02-A04 — Shop cash selection rejects card/unpaid/already-settled rows and settles exactly once under concurrency.
- [ ] B02-A05 — Deal/exemption/promotion/recipe purchase-to-use/return flows preserve original snapshots after subsequent master/rate changes, with a second tenant unchanged.

## Audit coverage — primary owner

| Audit ID | Original classification | Required investigation/outcome |
|---|---|---|
| P06 | P1 | PRA mixed taxable/exempt deal allocation across full lifecycle |
| P07 | P2 | PRA stock counts/movement/cost basis and invalid date handling |
| P08 | P2 | PRA deal empty states, optional fields and import guidance |
| M01 | P1 · LIVE | Final/provisional local-bill retention and recovery expectation |
| M02 | P1 · LIVE | Retained closed-day totals versus missing/zero historical detail |
| M03 | P2 · LIVE + V11 | Cancelled-order accountability and unknown preparation versus zero waste |
| M10 | P1 · REPORTED / partial LIVE | Unassigned delivery shop-cash settlement distinct from delivery state |
| M11 | P2 · REPORTED + LIVE context | Size/category drink grouping with separate deal-component accounting |
| M16 | P2 · VIDEO + LIVE context | Business-day cutoff, returns wording and stable identifier lineage |

## Named acceptance scenarios — planned, not passed

| Test | Scenario | Required pass evidence |
|---|---|---|
| T28 | Taxable + exempt combo/discount/return | Preview, saved totals, tax payload, stock and accounting reconcile |
| T29 | Return vs fiscal note vs cash refund | Correct parent, status/sign and financial settlement; no double refund |
| T33 | Date/report consistency | Same tenant/branch/date/status/local/return scope produces matching totals |
| T34 | Missing cost/unit conversion | No unknown-as-zero profit; purchase/base/sale units and historical cost reconcile |
| MT01 | Retention: Test company's Save vs Delete policies, final/provisional records, auto/manual close; repeat close | Policy effects explicit; archive accessible where promised; deletion never masquerades as zero sales; audit and aggregates reconcile; repeated close no duplicate effect |
| MT02 | Working-day boundary: Bill at 23:59, 00:01, 05:59 and 06:00; daily L token reset | Consistent business date across reprint, returns, reports, delivered list, close and diagnostics; permanent ID stable |
| MT06 | Cancel audit: Cancel unsent order, sent KOT, partly prepared food and paid sale reversal | Correct reason/actor/time/items; unknown prepared quantity not zero waste; money, stock and kitchen effects distinct |
| MT09 | Shop cash: Selected unassigned cash deliveries, mixed cash/card/unpaid/already settled, retry | Exact selected amount settles once; unsupported rows excluded with reason; actor and settlement report reconcile |
| MT10 | Product report: Multiple drink sizes, standalone/deal items, returns, exemptions, archived day | Subtotals reconcile to full report without counting both deal revenue and components twice |

Cross-bulk cases remain coordinated: MT12 spans receipt preservation (B03) and unread updates (B08); T29/T33/T34 also inform B04/B05; B07's denial/revocation controls apply to every product. These references do not create duplicate implementation ownership.
