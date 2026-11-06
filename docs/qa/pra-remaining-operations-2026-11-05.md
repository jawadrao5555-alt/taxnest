# Remaining PRA operations — consolidated implementation evidence

Owner requested all remaining PRA operations in one implementation PR on 5 November 2026. Base: `bc09c9f72843a6ea068f423edf9dfd152564be52` (merged #349). Source planning PRs #336/#304/#261 remain intact. #328 and #237 remain parked/HOLD. This is not the whole-SaaS #319 programme or Admin/billing #337.

## Scope reconciliation

The previously listed items were planning requirements, not nine proven current defects. Source comparison found the phone/contact/navigation/table/design/routing implementations already on main. Reuse and retest those implementations; do not introduce a second settings or printing system.

| Requirement | Implementation / regression evidence |
| --- | --- |
| B03-R01 phone/history filters | Existing `PosCustomerPhoneIdentity`, `PosPhoneSearchContinuityTest`, `b03-phone-search.test.mjs`; normalized local/international identity, combined filters and bounded results |
| B03-R02 contact correction | Existing explicit contact API, `PosSaleCustomerNameEditTest`, separate address APIs; current-order values stay request snapshots and historical bills remain immutable |
| B03-R03 Deliveries → New Sale | Existing `tnDeliveryNewSale`, `b03-delivery-navigation.test.mjs`, actual `pra-b03-browser.mjs` navigation journey; draft cancellation/focus/frame recovery |
| B03-R04 table/dispatch | Existing table reservation and explicit auto-KOT choice, `b03-table-commit.test.mjs`, `PosDineInTableRequiredInvoicePathTest`, hosted table-order journey |
| B03-R05 per-attempt print trace | Merged #349 scoped read-only chains and source reconciliation; existing `PrintJobEvidence`, no blind replay or physical-paper assertion |
| B03-R06 routing/preferences | Existing `PosKotDeviceRoutingTest`, `PosCounterKotGuardTest`, `PosDeliveryKotOnFinalTest`, Agent/native/Windows required lanes; original receipt/counter/KOT choices preserved |
| B03-R07/R08 nine styles and explicit save | Existing `PosPrintDesigns`, `PosReceiptOptions`, actual renderer/preview tests and hosted all-design desktop/390 journey; Local/Fiscal independent, legacy fallback, amounts/QR unchanged |
| B03-R09 staff/family approval | New isolated requests/events, owner revision/reject/cancel, existing checkout claim/commit, optional OFF default, actual cashier/owner/resume browser journey and native concurrent workers |
| B03-R10 Agent recovery | Reuse existing launch/fallback/update recovery; mandatory Electron/Windows disposable acceptance. Physical installed device acceptance remains separate |
| B03-R11 effective print summary | Existing `pos.partials.saved-print-summary` and receipt settings; tested preservation rather than replacing settings |
| Prioritized Day Close / offline diagnostics | Merged #349; not reimplemented here |

## Staff approval architecture and compatibility

Before this change, the #261 source was design-only and the normal checkout finalized directly. A proposal is now stored separately from transactions/payments/stock/fiscal queues. Canonical resolved items establish its eligible amount after line discounts. The original proposal and every review revision are retained. The owner sees the full order and can revise percentage/amount, reject or cancel; the proposer may cancel and explicitly resume the original cart after reload.

Review does not finalize under an outer transaction. The original cashier finishes through the normal invoice endpoint. The request/cart/tax-method/branch/reporting-policy and held-order evidence are bound to approval. An atomic claim and consumed reference live inside the existing sale transaction; fiscal work remains in its existing post-commit path. Ordinary discounts are replaced by the approved bill-level discount, not stacked. Cashier percentage caps remain unchanged for ordinary bills. A staff key cannot be reused as an ordinary sale to bypass approval.

| Case | Required behavior / coverage |
| --- | --- |
| Existing company / feature OFF | Additive false default; no preference, price, routing, printing, pairing or ordinary offline policy reset |
| Same/foreign tenant, cashier/owner | Own requester and active company employee; owner/admin review only; foreign IDs inaccessible |
| Proposal / lost response | No final serial/payment/stock/PRA write; UUID retry returns the same proposal |
| Review race / stale revision | Company + request locks; one revision wins; all revisions retained |
| Same approval final race | Native separate processes exercise the real controller; one invoice/payment; existing UUID retry recovers the original |
| Held/waiter order | Open request prevents alternate payment; changed held rows/cart invalidate approval; cancellation releases the approval reservation |
| PRA ON/OFF | Acting cashier policy retained; ON uses normal fiscal path after commit, OFF uses `pra` + NULL fiscal status |
| Inclusive/card-save/line discounts | Existing `PosTaxMath` and resolved eligible subtotal; approved actual discount once |
| Manual/deal/catalog, old Agents | Normal resolver/quota/inventory services; staff flow online-only; ordinary clients/offline replay unchanged |
| Final staff invoice | Immutable approved financial history; ordinary return process remains available |

## Tests and honest release boundary

- Local PHP 8.3.6, unchanged project contract PHP ^8.4.1: initial combined 85 tests /888 assertions passed, including six initial staff cases. Expanded staff suite subsequently passed 15 tests /131 assertions, including cashier PRA policy, inclusive/card-save, held-order reservation/invalidation, owner controls, private resume, policy changes during payment, kitchen-progress compatibility and waiter assignee authorization. These are local focused results; full supported-runtime acceptance remains hosted.
- First published commit `dc725b9c9c1b4c23f624648e66275f49dd87f0ae`: hosted MariaDB lane passed; job `127429570321` logged real concurrent owner reviews=1 and final invoice/payment=1, preserving approved money/cashier/NULL status, with original-invoice recovery after a lost response. Subsequent policy/audit hardening still requires fresh native results at its own final commit.
- Local Node: 14 B03 phone/navigation/table tests passed. PHP/Node syntax and diff checks pass.
- Mandatory hosted PHP 8.4 full suite, MariaDB migration and real concurrent staff workers, actual authenticated desktop1366/mobile390 cashier→owner→resume→payment journey, existing all-design/routing/Agent lanes must verify the final published commit. No local native MariaDB binary is available here; hosted native results cannot be inferred from SQLite.
- All existing tests and gates retained. Native workers use only the exact disposable `taxnest_rc_*_full` database and loopback egress guard. Browser fixtures are fictional and local only. Reporting is OFF; no actual fiscal invoice or physical print is submitted.
- A green PR establishes approval readiness. Deployment/live SHA verification, installed Windows Open POS/Save As/physical printing and seven-day field evidence require their own results. Do not mark the whole bulk physically accepted from queue/spool or synthetic results.
