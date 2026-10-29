# TaxNest improvement and future ERP queue

Owner-authorized living planning tracker — 29 October 2026 (Asia/Karachi).

**Planning/tracking only. This document does not implement a feature, authorize a release, or establish deployment readiness.** Keep this queue updated in the same tracking PR when the owner supplies another company, software reference, customer need or approved idea. Do not create duplicate trackers for the same requirement.

## Purpose and ownership

Maintain a durable queue of the six agreed platform improvements and later industry ERPs. Reuse working TaxNest modules and existing issues; investigate gaps against running code rather than assuming a feature is missing or complete. Keep implementation in focused PRs linked here. This queue complements the original six-workstream/47-item programme in #301; it does not replace, duplicate or mark that programme complete.

Owner priorities: existing PRA POS reliability and accurate money/stock first; other product improvements as expressly requested. Preserve existing company settings, permissions, saved designs, issued invoice snapshots and financial history. #237 stays HOLD. No automatic expansion or customer migration.

## State and completion rules

Use these states: Queued → Investigating → Implementing → Verification pending → Ready for owner → Deployed / acceptance pending → Complete. Also allow Blocked and HOLD, with an exact reason. A mixed row stays partial until all agreed acceptance is verified.

- A checkbox means the complete named outcome, not the existence of a model, screen, specification or green documentation CI.
- For Ready for owner: record the exact implementation head, latest own eight mandatory CI checks including validate, applicable browser/MariaDB/device acceptance, review findings and clean mergeability. New commits invalidate old-head readiness evidence.
- For Complete: retain implementation PR/head, owner-approved squash/release SHA, Actions deployment/live-verification/relay callback evidence where applicable and the promised functional acceptance. Installed Agent, physical printer and connected read-tool acceptance require their own actual evidence; successful dispatch is not proof of paper.
- Partial foundations and shipped slices belong in the evidence column without checking the whole workstream. Requirements-only #304/#313 and tracking #301/#310 must never be presented as implemented software.
- Record date, source and limitations for every update. Recheck linked issues because old descriptions may lag later bulk releases. No automatic issue/PR closure from this planning document.
- No approval, merge, deployment, production workflow dispatch, production credentials/data/settings access, printing or recovery action is authorized by this tracker.
- No recurring watch or external-company monitoring is requested. Review new references when supplied by the owner or during explicitly requested queue work.

## A. Six agreed platform outcomes

| ID | Outcome | Current foundation / scope record | Remaining acceptance | Queue state |
|---|---|---|---|---|
| Q1 | Every financial amount traceable and reconciled | Customer/supplier ledgers; Healthcare journals and financial statements in code. #295, #297; related #296 timeline. | Tile/report → original invoice, payment, return, adjustment and ledger entries; totals reconcile with rounding and period semantics. Historical amounts do not change after recipe, rate, package or company edits. Financial corrections are audited reversals rather than silent rewrites. Separate customer shop sales from TaxNest subscription collections. | Queued; partial foundations |
| Q2 | Complete stock quantity and value chain | Purchase/receiving, branch stock, recipes and pharmacy batch foundations. #295, #299, #45; DI packing scope #265/#266 carried by #313. | Purchase order → physical receiving → supplier bill → consumption/sale → waste/return → closing quantity/value. Explicit valuation basis, no double receiving, compatible unit conversions, sale-time recipe/cost snapshots and reconciled counts/servings/returns. | Queued; partial foundations |
| Q3 | Direct permitted reporting and at least seven-day diagnostics | Existing read-only connector, fiscal/audit logs and Agent heartbeat foundations. #310 is an issue/tracker; related #290 and #300. | Correlate company/product/branch/user/device, bill/order/item/KOT and attempts/results. Verify minimum seven-day retention per implemented stream, event/receipt time, offline buffering/gaps, deduplication, health/capacity and redaction. Typed read-only reports after authorized connection; no repeated Live Ops approval for normal permitted reads. All SaaS products and future ERP adapters declared and separately accepted. | Queued; not a completed diagnostic tool |
| Q4 | Reliable drill-down, PDF and spreadsheet reports | Existing reports; partial implementation in #312 for product-specific controls and billing disposition/filtering. #278/#273 retained as source references; #295/#297/#310 overlap. | Same scope/calculation for rows, count, totals, pagination and exports; company/branch/user/business date/calendar period/payment/rate/exempt/Local/Reported filters as applicable. Correct return/refund effect and stored rate snapshots. Explicit as-of/source/completeness. Pending or failed fiscal submissions never masquerade as Reported. | Implementing in #312; whole outcome incomplete |
| Q5 | Consistent approval, permissions and business controls | Roles/audits and product-specific controls; #296 two-step/timeline, #297 atomic payment and subscription lifecycle; #273/#312 optional archive consent. | Preparer/approver separation, two-step enrollment/recovery, audited reasons/reversals, tenant/branch denial, atomic/idempotent receipt-payment-approval-subscription changes. Optional deletion archive owner-only, default OFF, prospective, bounded/encrypted/scoped with expiry/revocation and honest backup/restore handling. | Implementing financial guards in #312; remaining controls queued |
| Q6 | Simple business-specific onboarding | Existing company setup, Healthcare module registry/selection and product imports. Related #299 import/receiving and #295 package compatibility. | Guided business/module selection → package → staff/branches → validated catalogue/opening balance/stock preview → explicit confirmation → setup verification. Row errors, deduplication, retry/rollback and second-tenant denial. Importing a catalogue never silently posts stock. No overwrite of existing preferences or historical balances. | Queued; foundations only |

Completion checklist — leave unchecked until the entire row is accepted:

- [ ] Q1 — Financial traceability and reconciliation complete.
- [ ] Q2 — Stock quantity/value chain complete.
- [ ] Q3 — Direct reports and seven-day diagnostic coverage complete for all agreed products/sources.
- [ ] Q4 — Report/filter/export consistency complete.
- [ ] Q5 — Business controls and approval/consent safeguards complete.
- [ ] Q6 — Guided onboarding and safe import/opening setup complete.

### Practical examples that acceptance must answer

- “Why does this invoice total differ from menu total?” → stored calculations, discount, inclusive/exclusive tax, return/payment evidence and exact references; never guess.
- “ZFC's KOT was missed yesterday evening” → scoped timeline of creation, routing, claim, attempt/result and coverage gaps; do not assert physical printing from an acknowledgement alone.
- “Who billed how much today/monthly?” → actual actor, company/branch, business-date/timezone, returns and matching downloadable detail.
- “Give exempt or this tax-rate report” → actual stored rates and line snapshots, not hard-coded 8%/16% or today's settings.
- “Why did stock/value change?” → movement evidence and the historical costing basis, with adjustments/waste/returns separated.

## B. Delivery order and dependencies

1. Finish the currently authorized #312 bulk, including its remaining decision/consent work and current-head acceptance; do not silently remove scope to call it ready.
2. Prioritize PRA financial/tax/deal/stock discrepancies through #295/#299 and relevant focused fixes. Reproduce each actual defect before implementation.
3. Deliver a bounded Q3 PRA evidence slice early, reusing #310/connector and existing logs, while Q1/Q2 are completed. Verify seven-day coverage before promising full history. Extend separately to FBR POS, DI, Healthcare/shared admin and future adapters.
4. Complete accounts/reconciliation and approval/security gaps through #296/#297 with existing finance/history invariants.
5. Finish consistent reporting and guided onboarding on the verified foundations.
6. Start new industry ERPs only after business demand, scope, dependencies and acceptance are agreed. This order is a planning priority, not permission to advance HOLD or unrelated PRs.

Owner confirmed implementation of this priority order on 29 October 2026; the queue is active. This does not authorize merging/deploying or change the completion gates.

Some slices can be coordinated within this sequence; urgent real regressions take precedence. A contributor must record any agreed priority change.

## C. Future ERP / industry expansion queue

Use the existing Nest ERPS registry and shared platform services. Current registry has Healthcare as the registered vertical; new entries still need actual screens/routes/permissions/billing/reporting/diagnostic acceptance. A registry entry is not a completed ERP. Reuse services safely while preserving product/tenant data isolation; no new product discriminator for every industry.

| ID | Proposed expansion | Initial scope to investigate, not implemented promise | Dependencies / decision |
|---|---|---|---|
| E1 | Distributor / wholesale | Dealer/customer balances, credit limits, sales orders, dispatch/delivery, purchase/bonus/returns, recoveries, route/staff performance, product packs and optional returnable-container ledger. | Q1/Q2/Q4/Q5; validate against real distributor requirements. |
| E2 | Manufacturing / textile / spinning | Versioned BOM, production orders, material issue, work-in-progress, finished goods, wastage/yield and production costing. Recipe code alone is not manufacturing acceptance. | Q1/Q2; selected pilot and production workflow discovery. |
| E3 | Full hotel ERP extension | Reservations, room availability/rates, housekeeping, deposits, room/food/service billing and guest ledger. Audit existing hotel POS coverage before adding another system. | Reuse working hotel features; determine actual missing flows. |
| E4 | Fertilizer / pesticide / agricultural trading | Dealer/customer credit, batch/expiry where applicable, purchase/sales/returns, seasonal recoveries and appropriate stock/unit reports. | Q1/Q2/Q4; confirm sector-specific compliance separately. |
| E5 | Farm management | Field/crop/season records, input/labour/machinery costs, harvest yield, sales and per-field/season profitability. | Q1/Q2; farm pilot requirements and unit/cost rules. |
| E6 | Installment sales | Cash versus financed price, down payment, due schedule, recoveries/aging, reschedule/reversal and asset/customer history. | Explicit product/accounting policy; no guessed financing terms or charges. |
| E7 | Assets / workshop / maintenance | Asset register, depreciation policy, service/job cards, parts usage, warranty and preventive maintenance. | Q1/Q2; approve accounting policy and pilot scope. |
| E8 | Transport / goods logistics | Booking/consignment, dispatch/delivery proof, vehicle/trip expenses, customer/carrier balances; passenger seat reservation only if separately requested. | Customer demand, workflow discovery and financial reconciliation. |
| E9 | HR / biometric expansion | Optional approved-device attendance integration, rosters/leaves and full salary/advance/deduction/approval/payment workflow where needed. Existing POS session attendance and Healthcare payroll handoff are partial foundations. | Q1/Q5, hardware/privacy/security and actual device acceptance. |

- [ ] E1 — Distributor / wholesale ERP accepted.
- [ ] E2 — Manufacturing / textile ERP accepted.
- [ ] E3 — Full hotel extension accepted.
- [ ] E4 — Fertilizer / pesticide trading ERP accepted.
- [ ] E5 — Farm ERP accepted.
- [ ] E6 — Installment sales accepted.
- [ ] E7 — Assets / workshop accepted.
- [ ] E8 — Transport / logistics accepted.
- [ ] E9 — HR / biometric expansion accepted.

Army recruitment and police vehicle systems are not current TaxNest queue commitments. Communication-card inventory is not assumed related to TaxNest's unrelated Coca-Cola/CCI import work merely because the abbreviation matches. Generic retail/mobile/computer accounts can first be assessed as configurations of existing stock/POS rather than duplicate ERPs.

## D. Reference and incoming-company register

| Reference ID | Date / source | Observation | Action / limits |
|---|---|---|---|
| R1 | 29 Oct 2026 — https://www.genieclique.com/products.htm ; supporting https://www.genieclique.com/services.htm | Catalogue lists industry accounting/inventory/ERP products, attendance, hotel, manufacturing/textile, farm, transport and installment systems. | Seed for this queue only. Marketing descriptions, not demo, quality or security proof. No copying of proprietary code/assets. |
| R2 | 29 Oct 2026 — current TaxNest main `2f625267486ee0b18cd9972fcd00057321420099` | Product/ERP registry, Healthcare module/accounting services, supplier ledger and architecture map inspected; existing requirements linked. | Code foundations are not full end-to-end acceptance. Recheck current source and release evidence before changing status. |

For every later owner-provided reference, append an R-number in THIS file and update this PR's checklist/status:

1. Record URL/name, review date, exact visible requirement and uncertainty. No confidential company data or credentials in this public repo.
2. Check existing code, issues and this queue; link an existing Q/E ID where it overlaps instead of duplicating it.
3. Separate verified existing capability, missing scope and new proposal. A competitor claim is not proof.
4. Add a new stable Q/E ID only for genuinely distinct agreed scope; capture dependencies, priority, affected products and preservation constraints.
5. Add relevant acceptance and an implementation issue/PR link once work starts. Keep unrelated original commitments visible.
6. Update status and evidence as implementation/verification/release happens. Check completed only under the completion rules above.
7. When a queue item changes scope, record the owner decision and date; never erase historical scope or quietly mark it done.

## E. Evidence ledger template

Append one entry per material status change:

| Date | Q/E ID | Implementing issue/PR | Exact tested head | Local + current-head CI acceptance | Owner release squash / deploy / live verification / callback | Known gaps | State |
|---|---|---|---|---|---|---|---|
| 29 Oct 2026 | All | This planning tracker; #301 and linked requirements | N/A — planning only | Document/link/checklist review only; application tests N/A | None authorized by this PR | All six complete-outcome checkboxes remain open | Queued / partial foundations as recorded |
| 29 Oct 2026 | Q4/Q5 | #312 | `e0993a4ff2702fbcf8946b3231958822df1fc3c5` | Reports/notifications group107 tests/689 assertions and financial guard/stock/day-close group121 tests/726 assertions pass locally; exact-head CI40840527646 pending. Parent91fdd016 all eight in40835527970 are historical. | None for this head | Owner-consent/archive and complete decision/concurrency acceptance pending; no whole-outcome completion | Implementing / verification pending |

Do not fill unknown cells with invented evidence. Keep observed facts, inference, readiness and live verification distinct.
