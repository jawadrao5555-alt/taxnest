# TaxNest complete SaaS delivery master — 30 October 2026

**Owner-requested consolidation.** This is the current master under PR #301 for all earlier programme requirements, the complete SaaS audit and customer-media findings, whole-SaaS diagnostics, existing implementation slices, and future ERP queue.

**Scope/planning only.** This update creates six new bulk scope PRs and expands three existing ones. It does not implement those requirements, execute acceptance tests, approve a release or establish a live fix. New bulk PRs are draft. Existing planning PR state is not a readiness signal.

## Coverage and deduplication

- **85 audit records:** S01–S55, P01–P14 and M01–M16, each with exactly one primary bulk owner below. These include verified observations, reported issues, design requirements and unexecuted tests; they are not 85 proven current code defects.
- **57 named acceptance scenarios:** T01–T44 and MT01–MT13. Planned, not passed in this consolidation.
- **47 original unchecked checklist items** in the 26 October master are preserved verbatim in the historical source section, including all six #295–#300 workstreams and completion conditions.
- **All Q1–Q6 shared outcomes and E1–E9 ERP proposals** from #314 remain included and unchecked. #310's FULL evolving checklist is incorporated, not just #315's first slice.
- Public documents use anonymized requirements; original customer videos/voices, contacts, amounts, invoice references and private diagnostic results stay outside this repository.
- The scope table and primary ownership control duplication. A cross-bulk reference is a dependency, not another implementation of the same feature. Update the master and owning contract together when agreed scope changes.

## Nine bulk PRs

| Bulk | Review/implementation container | Scope | Priority and dependency |
|---|---|---|---|
| B01 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Company lifecycle, subscriptions, payments and packages | Wave 2; company context may start early |
| B02 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | PRA financial history, day close, cash, stock and deals | Wave 1; financial/history correctness |
| B03 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | PRA checkout, KOT, receipts and Agent experience | Wave 1; checkout/KOT before optional styles |
| B04 | [B04 / #313](https://github.com/jawadrao5555-alt/taxnest/pull/313) | Digital Invoice integrity, reporting, billing cycles and Smart UOM | Critical triage Wave 1; full Wave 3 |
| B05 | [B05 / #318](https://github.com/jawadrao5555-alt/taxnest/pull/318) | FBR POS fiscal delivery, returns, reporting and pharmacy integrity | Critical triage Wave 1; full Wave 3 |
| B06 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | Whole-SaaS diagnostics, direct reports, Agent evidence and release reliability | R0 first; #315 slice early, then phased coverage |
| B07 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | Security, permissions, privacy, retention and recovery | Safety gates throughout; remaining work Wave 2–4 |
| B08 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | Onboarding, support, scoped assistant, updates and accessibility | Wave 4 after source correctness |
| B09 | [B09 / #314](https://github.com/jawadrao5555-alt/taxnest/pull/314) | Future ERP queue and shared-platform readiness | Wave 5; agreed pilot after shared foundations |

PR numbers are review containers; bulk IDs are durable scope identifiers. Do not infer delivery order from PR number.

## Existing work: reuse before writing another fix

| Source | Observed GitHub state on 30 Oct | Meaning for this programme |
|---|---|---|
| #301 | Existing planning master, expanded here | Original six workstreams retained; nine-bulk index is the current delivery organization |
| #304 | Existing planning PR, now B03 | Retains #280/#281/#261 style and discount contracts; adds remaining checkout/printing/Agent scope |
| #313 | Existing planning PR, now B04 | Retains #263/#264/#265/#266 cycles/packing contracts and #262 coordination; adds DI audit requirements |
| #314 | Existing living queue, now B09 | Retains Q1–Q6, all E1–E9, references and history; platform delivery routed to B01–B08 |
| #310 | OPEN issue/tracker | Canonical whole-SaaS direct-report/diagnostic programme, primary B06; not itself a PR or complete tool |
| #315 | OPEN, non-draft; head 232ca96bcfbe99dc3c81db4741a28c7396aa9d54 | Existing first PRA server-print timeline implementation; recorded own 8/8 CI evidence, not universal coverage, installed-client acceptance, root-cause print repair or production readiness |
| #312 | MERGED; squash b8ca7fb92cb868573b38b4d7fc3e7bc667697dba, observed main baseline | Implemented reports/targeting/decision locks and owner-consented prospective support copies. Reconcile deployment and owner-role UI before calling absent or rebuilding. Last failure is recorded in #315; refresh actual release evidence, do not assume an old failure is a current diagnosis |
| #305 | MERGED; squash 2f625267486ee0b18cd9972fcd00057321420099 | Reuses #234/#250/#251/#256 Agent health/launch/download/update work. Native CI evidence and installed-fleet acceptance are different |
| #306 | MERGED; squash e7ba27ec270af4170456dd58db3cbbbd900bfeca | Includes #291/#292/#293/#294 and #229–#232 admin compatibility/activity/login/theme/screen work. Those four original PRs are now closed unmerged individually; do not read the historical master draft table as current missing implementation. Full MFA/Accounts/trial/grace/timeline is not established by #306 |
| #302 | MERGED | Reuse existing admin GitHub-read/cooldown/stale-claim safeguards; current release gates still need actual verification |
| #309 / #311 / #303 | Existing merged-source lineage referenced by later bulks | Reuse invoice calculation diagnostics and receipt display/typography; do not reset existing settings or assume untested preservation |
| #237 | HOLD, excluded from execution | Owner has not resumed the mandatory online-transfer rule. This consolidation does not lift any HOLD |

The metadata/state checks above are fresh repository reads; historical test/run statements remain attributed to their source PR descriptions. This task did not rerun application CI or production workflows. Source descriptions may contain older pending claims; later verified metadata and evidence take precedence.

## Delivery order and release gates

1. **R0 — establish exact baseline (B06):** reconcile main, actual deployed SHA, #312's recorded Elaan failure and #315's dependency. Diagnose cause through the approved secret-free path and preserve all gates. If a real fix is needed, reproduce and submit it; do not manufacture a deployment pass.
2. **Wave 1 — protect sales and customer operations (B02/B03, early B06):** retained history/day-close reconciliation, cash settlement, unintended table/KOT actions and exact print tracing. Use #312/#305/#315 already implemented foundations. Critical DI/FBR integrity, money or authorization findings are triaged here rather than waiting behind cosmetics.
3. **Wave 2 — company/commercial controls (B01 with B07):** consolidated dates/payment/suspension context, effective access, atomic money and consistent package rules. Read-only company display can be delivered before invasive billing-rule migrations.
4. **Wave 3 — product completeness (B04/B05 and remaining B02):** DI cycles/packing/integrity, FBR delivery/returns, recipe/stock/deal/promotion reconciliation after shared rules and relevant safeguards are demonstrated.
5. **Wave 4 — source-backed experience (remaining B06/B07/B08):** complete adapters, monitoring/recovery/security, support/assistant/onboarding, accessible inner screens, updates/tutorials and optional design/profile features. Security/privacy requirements remain gates on every earlier wave.
6. **Wave 5 — selected ERP pilots (B09):** only after required Q1–Q6 foundations and a concrete pilot scope. A registry entry or competitor example is not a completed ERP.

Keep related changes in the owner's bulk containers. Each bulk may use separately verifiable internal stages; introduce a separate fixing PR only where a distinct root cause, dependency or rollback boundary makes it necessary, and link it to the same bulk. Do not combine old runtime trees merely to reduce PR count, or fabricate a calendar estimate from UI review alone.

## Settings and migration contract

- Existing company choices, branches, roles, receipt styles, printers, tax/price/cost snapshots, paid periods, grants and historical identities remain intact unless an explicit scoped migration is agreed and tested.
- Defaults apply to missing/unset fields only. New opt-in functions remain explicit; no mass consent, copying, repricing, account lock or redesigned receipt rollout from this plan.
- A bug fix should correct the affected behavior for all compatible tenants, with no company/device hard-coding and no unnecessary switch per symptom.
- Owner-consented support copies from #312 are prospective, scoped and separate from the financial ledger and ordinary Save/archive policy; they cannot recover old deleted detail or inflate reports.
- Physical print, central fiscal acceptance and connected diagnostic coverage require their own actual evidence; green generic jobs are not enough.
- Admin UI and printed tickets use the established English rules; customer-facing UI follows the appropriate product language. Preserve core Local/reporting-OFF/NULL fiscal identity, product guards, invoice locks and series semantics.

## Completion states and developer handoff

Use **Queued → Investigating → Implementing → Verification pending → Ready for owner review → Deployed / acceptance pending → Live accepted**, with Blocked/HOLD and a concrete reason. No checkbox below is checked by this planning update.

Every actual fix must show: original failure/reproduction; confirmed cause or honest uncertainty; intentional files/schema changes; settings/history compatibility; exact meaningful tests and original retest; relevant browser/DB/device/negative/concurrency results; reviewed head and current required CI; migration/rollback; owner approval; exact release/squash and Actions live verification; remaining functional gaps. A later code commit requires current-head verification again.

Development uses fictional local data. No raw customer material enters public PRs, and no user communication, fiscal submission, reprint, payment/grant change or release is authorized by planning text. Follow AGENTS.md and the issue-resolution/owner-release workflows; this owner instruction authorizes related bulk scope organization, not bypassing implementation acceptance.

## Full work-package checklist

### B01 — Company lifecycle, subscriptions, payments and packages

Container: [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316). Sources: #295, #296 (timeline), #297, #291/#292 history, #312 reporting foundation. Dependencies: Shared authorization/financial-history invariants; coordinate DI cycles with B04. Display-only company context can land before payment/access rule changes.

- [ ] B01-R01 — One company overview showing owner/product/branches, selected and historical plan, trial/paid/grace dates, original price, payments, next renewal, usage and effective access with reason.
- [ ] B01-R02 — Separate approval, subscription term, explicit suspension, temporary/lifetime grant, payment and effective access states. Capture suspension/reactivation actor, reason, scope, timestamps and restore condition; unknown old reasons remain unknown.
- [ ] B01-R03 — Consolidate company timeline; distinguish real staff activity from admin viewing/impersonation. Add renewal/overdue/grant-expiring saved views and direct company links.
- [ ] B01-R04 — Versioned plan/entitlement source shared by pricing, signup, admin and server enforcement; explain counted/exempt roles, branches, add-ons, overrides, limits and period reset. Preserve retired plans and historical agreed prices.
- [ ] B01-R05 — Complete #297: selected-package 72-hour trial with unlimited trial bills, full-payment activation, paid period after trial, explicit early/late renewal rules and 7-day expiry grace/reminders/lock/recovery. Preserve grandfathered paid periods and grants; resolve rule precedence before migrations.
- [ ] B01-R06 — Atomic and idempotent payment -> receipt -> allocation -> subscription/approval -> commission; record payer/reference/method/paid-at/verified-by; handle partial payments, credits, refund/reversal and concurrent reviewers.
- [ ] B01-R07 — Separate TaxNest income/expense/receivables/partner payable from tenant shop sales. Trace each aggregate to immutable source entries; define MRR/ARR exclusions if added.
- [ ] B01-R08 — Version discounts, quotes, add-on terms/proration and referral attribution; test commission clawback and prevent double extension/payment/beneficiary.
- [ ] B01-R09 — Controlled product migration and customer groups/cloning; preserve tenant isolation, trial policy and historical access; unify viewer scope and revocation presentation.

Acceptance:

- [ ] B01-A01 — An expired paid term with explicit suspension and a temporary grant has one explainable effective decision, identical across UI/server actions; payment does not silently remove suspension.
- [ ] B01-A02 — Double-click, retry and concurrent payment confirmation create one financial allocation/receipt/extension/commission, or roll back together.
- [ ] B01-A03 — 72-hour, 7-day, month-end/leap-year and PKT boundaries; early/late renewal; existing grandfathered company and second tenant all pass.
- [ ] B01-A04 — Advertised role/branch limits equal enforced limits under concurrent creation; no raw -1 or ambiguous zero/unlimited.
- [ ] B01-A05 — Company overview, subscription history, payment proof, ledger and dashboard reconcile to the same original price, term and source records.

### B02 — PRA financial history, day close, cash, stock and deals

Container: [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317). Sources: #295, #298, #299 (inventory/import), #45; reuse merged #312 consent/deletion safeguards. Dependencies: Check #312 implemented and deployed scope first. Coordinate shared commercial fields with B01, item/packing semantics with B04 and diagnostics with B06.

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

Acceptance:

- [ ] B02-A01 — Current and prior business-day cancellation/archive/return lookups work with clear stage identity; repeat close cannot duplicate financial/stock effects.
- [ ] B02-A02 — Save/Delete/consent OFF/ON/expired/stopped/purged behavior and late fiscal callback/return locks preserve #312 invariants; unavailable history labelled honestly.
- [ ] B02-A03 — Local/fiscal/combined totals, cash/card/other, returns, rounding adjustments, cashier/category and downloads reconcile on synthetic closed and open cross-midnight days.
- [ ] B02-A04 — Shop cash selection rejects card/unpaid/already-settled rows and settles exactly once under concurrency.
- [ ] B02-A05 — Deal/exemption/promotion/recipe purchase-to-use/return flows preserve original snapshots after subsequent master/rate changes, with a second tenant unchanged.

### B03 — PRA checkout, KOT, receipts and Agent experience

Container: [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304). Sources: #304 retaining #280/#281/#261; reuse merged #303/#311 receipt controls and #305 Agent work; #315 is diagnostic evidence, not the print fix. Dependencies: B02 supplies money/discount invariants; B06 provides correlation. Reproduce operational failures before optional new styles. Upgrade/rollout verification precedes duplicate Agent implementation.

- [ ] B03-R01 — Reproduce phone lookup across Held/Delivered/Reprint/history, normalize compatible phone formats and combine date/order/payment filters; distinguish absent data from retention-deleted history.
- [ ] B03-R02 — Inline customer name/phone/address correction with this-order-only vs profile scope, permission checks, duplicate identity handling and immutable historical bill snapshots.
- [ ] B03-R03 — Direct and embedded Deliveries -> New Sale navigation: dismiss overlay, restore focus, preserve or explicitly handle existing draft; handle frame failures without declaring a global outage.
- [ ] B03-R04 — Takeaway/Dine-in/table selection must have an explicit commit/transfer/kitchen-dispatch contract. Preserve draft/cart and prevent unintended or duplicate KOT under retries/double clicks/table conflicts.
- [ ] B03-R05 — Trace missing receipt/KOT from saved sale/order through job creation, route, device, attempts and spool acknowledgement; investigate actual failure cause without substituting aggregate green jobs for paper evidence.
- [ ] B03-R06 — Keep compatible one/many counter/device/printer routing, old Agents, silent mode, Ask before print and delivery-receipt preferences; no guessed reroute or automatic reprint of ambiguous accepted jobs.
- [ ] B03-R07 — Retain full #304 original scope: current plus eight optional KOT styles and current plus eight optional receipt styles, Local/Fiscal independently selectable, logo/QR/footer/paper width and saved typography preserved.
- [ ] B03-R08 — Preview/cancel never saves or prints; only explicit authorized Save changes design. Printed tickets remain English and truthfully distinguish local/fiscal identity; preserve quantities, notes, mixed fulfillment and add-on/reprint semantics.
- [ ] B03-R09 — Retain #261 staff/family discount: cashier percentage 1-99 or cash proposal, owner/admin revise/approve/reject before finalization, stale/duplicate protection and no double discount. Reporting-OFF finals retain established NULL fiscal status.
- [ ] B03-R10 — Reuse #305 health/launch/native Save As/update recovery; verify delivered installed version, native Open POS and explicit browser recovery, reconnect, preserved pairing/routing/offline data and recovery under interrupted update.
- [ ] B03-R11 — Simplify effective print-setting summary and guidance without resetting choices; retain existing font/size/width until Save; coordinate update badge wording with B08.

Acceptance:

- [ ] B03-A01 — Reproduce/retest each reported search/edit/navigation/table flow in real local Chrome at desktop and 390px, including nonempty cart and second tenant.
- [ ] B03-A02 — Actual device/printer acceptance separately proves receipt and KOT output; queue/spool success alone remains paper-unverified.
- [ ] B03-A03 — One/many printer/counter, legacy Agent, offline/reconnect, double-click, failed-route and reprint cases do not duplicate bills or kitchen dispatch.
- [ ] B03-A04 — Existing custom Local/Fiscal layouts survive upgrade, preview and cancel; all proposed styles preserve truthful fiscal/financial content.
- [ ] B03-A05 — Discount approval concurrency, unauthorized role, stale proposal, returns and snapshot tests pass; HOLD #237 is not included.
- [ ] B03-A06 — Installed Windows launch/update/Save As acceptance recorded separately from packaging CI and browser fallback.

### B04 — Digital Invoice integrity, reporting, billing cycles and Smart UOM

Container: [B04 / #313](https://github.com/jawadrao5555-alt/taxnest/pull/313). Sources: #313 retaining #264/#263 and #266/#265; coordinate #262 rounding; preserve CCI/HOLD source boundaries. Dependencies: Shared payment/entitlement engine B01, inventory conversion semantics B02, scoped diagnostics B06 and security B07.

- [ ] B04-R01 — Retain every original #313 billing-cycle and optional Smart UOM/packing requirement and original source documents; this expansion does not replace their exact rules.
- [ ] B04-R02 — Resolve protected/locked vs failing integrity verification using original stored values and versioned canonical hashing; distinguish genuine mutation from legacy compatibility and never silently rehash away evidence.
- [ ] B04-R03 — Explain the actual taxable base/rate/scenario and reconcile quantity, MRP/selling price, discounts, tax, totals, saved invoice/PDF/payload; verify statutory mappings separately from UI arithmetic.
- [ ] B04-R04 — Separate buyer identity from registration status and product exemption from scenario/reportability; preserve drafts, invoice locking, submission idempotency and fiscal data isolation.
- [ ] B04-R05 — Reconcile invoice outcomes vs API attempts, periods, withholding/completed counts and ledger/MIS filters; label output tax and effective billing grants accurately.
- [ ] B04-R06 — Implement monthly/quarterly/semiannual/annual DI cycles using configured/versioned prices and discounts, compatible plans, renewal receipts and shared paid/grace rules; do not invent commercial amounts.
- [ ] B04-R07 — Optional Sell By packings: exact purchase/base/sales/FBR UOM conversions for compatible units, bags/cartons/pack sizes and fractional sizes; reject incompatible conversions and preserve legacy pack_size meaning.
- [ ] B04-R08 — Freeze conversion, tax, rate, price, cost and packing at sale time for invoices/PDF/API/returns/inventory. Re-upload/import grouping idempotent with row-level errors and no source invoice mixing.
- [ ] B04-R09 — Record source evidence required for any CCI import and leave #237 and other explicit HOLD decisions unchanged; no guessed buyer/SKU/tax evidence or regulator submission.

Acceptance:

- [ ] B04-A01 — Current and legacy integrity fixtures detect genuine tampering and preserve original immutable evidence; UI protection status matches actual verification.
- [ ] B04-A02 — Billing cycles pass month/leap/PKT boundaries, retry/concurrency, refunds and existing-plan preservation.
- [ ] B04-A03 — Exact compatible UOM examples reconcile bill/API/PDF/stock/return; changing later pack definitions never rewrites issued invoices.
- [ ] B04-A04 — Invoice vs attempt reports, rows/counts/totals/export align and unknown data remain explicit; second tenant denied.
- [ ] B04-A05 — Full original #263/#265 contracts and #262 coordination are verified before complete status; existing CCI evidence/HOLD conditions remain.

### B05 — FBR POS fiscal delivery, returns, reporting and pharmacy integrity

Container: [B05 / #318](https://github.com/jawadrao5555-alt/taxnest/pull/318). Sources: Audit P09-P14/S55; reuse existing FBR reconciliation, IMS/Agent, supplier ledger and medicine-catalogue services. Dependencies: B06 evidence/correlation, B07 credential/access isolation; shared money/stock snapshots coordinate B02 without copying PRA semantics blindly.

- [ ] B05-R01 — Separate configured environment/credentials, Agent heartbeat, local IMS acceptance, central upload and central verification. Record last evidence time, pending queue and exact missing proof.
- [ ] B05-R02 — Investigate credential/environment/local-central discrepancies using permitted redacted evidence; preserve original accepted identifiers and prevent blind resubmission/duplicate fiscal sales.
- [ ] B05-R03 — Unify effective reporting/settings status; remove unsupported readiness/certification claims and explain Agent fiscal-vs-printing capabilities.
- [ ] B05-R04 — Reconcile price/tax-rate label, order discounts, taxable base, saved transaction and fiscal payload; establish and test intended pre-/post-tax discount rules using applicable source evidence.
- [ ] B05-R05 — Link sale, return/credit note and cash refund; preserve parent/sign/status and gross/return/net reconciliation, idempotent settlement and historical snapshots.
- [ ] B05-R06 — Profit/cost coverage uses real cost evidence and the same selected period; unknown cost is not zero or 100% coverage.
- [ ] B05-R07 — Business-type screens expose only applicable pharmacy/retail/restaurant controls and honest unsupported-workflow boundaries.
- [ ] B05-R08 — Medicine source sync quarantines invalid rows, handles out-of-order effective dates/ties deterministically and preserves provenance/current applicable MRP; no silent shop repricing.
- [ ] B05-R09 — Verify supplier purchase/bonus/discount/receiving/returns/payments and batch/FEFO/expiry/loose-unit history where touched; cross-branch/tenant scope and existing financial invariants retained.

Acceptance:

- [ ] B05-A01 — Local accepted / central unknown-or-failed fixture cannot produce a centrally verified badge; successful production delivery requires separate matching actual evidence.
- [ ] B05-A02 — Settings and runtime environment agree; invalid credentials are represented honestly without exposing secrets.
- [ ] B05-A03 — Tax/discount/return fixtures reconcile stored invoice, payload, reports and refund ledger; no duplicate fiscal event on retry.
- [ ] B05-A04 — Profit denominator/date basis and unknown costs are explicit; old source updates cannot replace newer applicable medicine data.
- [ ] B05-A05 — Retail/pharmacy role, tenant, branch, stock/batch and historical snapshot regressions pass.

### B06 — Whole-SaaS diagnostics, direct reports, Agent evidence and release reliability

Container: [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319). Sources: FULL #310 checklist; #315 first PRA implementation; #300/#290 scoped tools; merged #309/#305/#312 foundations. Dependencies: Reconcile deployed SHA and #312 recorded release failure before any new production readiness claim. Product adapters coordinate B02/B04/B05/B09; security contract B07.

- [ ] B06-R01 — Incorporate the entire #310 owner requirement, including later full-SaaS/ERP/business-day/proactive-detection clarifications. Keep #315 as the existing first implementation slice, without duplicating or broadening its current reviewed diff.
- [ ] B06-R02 — Inventory per-source cloud/PRA/FBR/DI/Healthcare/shared-admin/Agent/offline evidence, earliest/latest event, retention, event and receipt time, clock skew, gaps, failures, capacity and last successful upload.
- [ ] B06-R03 — At least seven days for every implemented diagnostic stream from working collection; #315's 30-day stored-row policy is partial foundation, not seven-day universal/device coverage. Durable bounded offline buffering, deduplication, sequence gaps, backoff and exhaustion visibility need acceptance.
- [ ] B06-R04 — Versioned product/ERP adapter registration declares identifiers, schemas, tenant/branch permissions, sources, retention and typed report operations. Missing/unregistered/incompatible streams remain Unknown/Partial; test synthetic new adapter and future schema.
- [ ] B06-R05 — Correlate company/product/branch/cashier/device with sale/items/order/KOT/print/fiscal/stock/payment and attempts using real identifiers; preserve pre-bill links and immutable originals after allowed cleanup.
- [ ] B06-R06 — Existing owner-authorized revocable OAuth/MCP connection serves normal permitted reads without repeated Live Ops approval; typed allow-listed paginated/exportable reports, source/time/scope/completeness, no arbitrary SQL/shell or unrestricted writes.
- [ ] B06-R07 — Whole business-day/open shift review across midnight, explicit calendar vs business date and as-of, cross-stream anomalies with valid retry/return discrimination; facts, inference and unavailable evidence separate.
- [ ] B06-R08 — Combine owner-supplied video/audio context with stored timeline, mark ambiguous timing/identity and absent events; downloadable incident report. No automatic customer message, reprint, fiscal retry, settings change or deploy.
- [ ] B06-R09 — Healthy requires fresh positive applicable evidence. Distinguish disabled/no activity/stale/unknown/failed; count complete fleet with bounded rows; propagate same incident to aggregate and company cards; label physical print unverified.
- [ ] B06-R10 — Resolve/report failed guard checks, old failed exports/jobs, probe applicability and scope mismatches; give saved diagnostic detail links, owner/age/resolution and safe retry eligibility.
- [ ] B06-R11 — Release state machine binds exact head/approval/squash/run/live check; expired/superseded/failed leases cannot promise later execution. Planning, implemented, CI-passed, deployed and live-accepted remain distinct.
- [ ] B06-R12 — Root-cause review of recorded #312 Elaan failure, safe exact-release observation and new focused remedy only if reproduced; never bypass approval/publication/credential gates or infer a current outage from old records.
- [ ] B06-R13 — Resource budgets, pagination, export recovery, scheduler/storage/backup coverage and peak-load isolation; diagnostics failure must not alter checkout/printing/financial behavior.

Acceptance:

- [ ] B06-A01 — Preserve #315 exact implementation and existing evidence; current-head acceptance must be refreshed if implementation changes. Whole #310 remains incomplete until every agreed adapter/source is accepted.
- [ ] B06-A02 — Seven-day and exact retention boundaries, missing/offline/late/duplicate/out-of-order events, failed storage and restored data expose truthful coverage without changing business records.
- [ ] B06-A03 — Real authorized connected-client tests prove reads, redaction, pagination/export, immediate revocation and two-tenant/branch denial.
- [ ] B06-A04 — Cross-midnight synthetic incident and video ambiguity tests produce grounded timelines with no physical-paper or universal-cause claim.
- [ ] B06-A05 — Fresh fleet/source health agrees between detailed and owner views; zero data cannot pass as healthy.
- [ ] B06-A06 — Expired deployment authorization cannot release; exact fixing SHA requires Actions live verification plus promised functional/device acceptance.

### B07 — Security, permissions, privacy, retention and recovery

Container: [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320). Sources: #296 authentication, #300 boundaries, #312 consent/restore safeguards; audit S32-S40. Dependencies: Shared foundation for all bulks. Coordinate UI/enrollment B08, access decisions B01 and diagnostic tools B06.

- [ ] B07-R01 — Secure single-use expiring owner password reset, consistent policy, notification and session invalidation; avoid support sharing reusable owner credentials.
- [ ] B07-R02 — Complete failed-login limits plus two-step enrollment/challenge/recovery/anti-replay with safe existing-owner onboarding; inspect already-shipped #293 rather than duplicating it.
- [ ] B07-R03 — Role/capability separation for owner, billing, support, operations and release; server authorization across UI/API/files/exports/jobs/websockets and company/branch boundaries.
- [ ] B07-R04 — Read-only vs full-access impersonation with actor/target/reason/session boundaries, before/after write audit and telemetry classification; no hidden writes under read-only access.
- [ ] B07-R05 — OAuth/viewer/share/device/consultant expiry and revocation across sessions, refresh/background jobs and cached access; no secret exposure or enumeration leaks.
- [ ] B07-R06 — Safe supported app distribution and release provenance; investigate detection issues instead of normal instructions to disable device protection; no unsupported malware/safety claim.
- [ ] B07-R07 — Data-flow/consent/retention inventory for AI, support, Caller ID, messaging and Healthcare data; minimal collection, scoped access and versioned disclosures.
- [ ] B07-R08 — Company deletion request lifecycle with verified requester, export/hold/purge/retention evidence and accurate deadlines; distinguish optional copies from required fiscal/accounting evidence.
- [ ] B07-R09 — Backup freshness and actual restore drill with RPO/RTO, tenant restore and revocation/deletion replay; preserve #312 optional-copy restore quarantine/keys policy and disclose physical backup limitations.
- [ ] B07-R10 — Separate billing destinations, SMTP/secrets, app release and AI config surfaces with masked secrets, audited sensitive changes and structured safe controls.

Acceptance:

- [ ] B07-A01 — Local real-app cross-tenant/branch/role denial covers every changed endpoint including exports/files/jobs; read-only impersonation cannot mutate.
- [ ] B07-A02 — Reset expiry/reuse, login throttling, MFA replay/recovery and existing-owner migration cannot create bypass or accidental permanent lockout.
- [ ] B07-A03 — Revoked connections/devices/viewers cannot refresh or continue background reads.
- [ ] B07-A04 — Restore drill proves scoped recovery and reapplied revocations/deletions; optional copies cannot reappear contrary to the documented quarantine.
- [ ] B07-A05 — No blanket security-protection disable advice or private customer/secrets in repo/logs; consent and key/purge failure semantics verified.

### B08 — Onboarding, support, scoped assistant, updates and accessibility

Container: [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321). Sources: #296 themes, #299 profile/greetings, #300 assistant/issue workflow; #314 Q6 onboarding; reuse #279/#312 targeting. Dependencies: Authoritative plans/access B01, data/report truth B02/B04/B05, typed tools B06 and security/privacy B07.

- [ ] B08-R01 — Consistent product/plan/price/trial/approval journey across public pages, signup/admin-create/clone; show accurate terms/privacy/refund context and policy version before agreement.
- [ ] B08-R02 — Business-specific guided setup: modules/package/staff/branches, validated catalogue/opening balance/stock preview, explicit posting and completion checks; preserve existing configuration and avoid duplicate stock from imports.
- [ ] B08-R03 — Public product comparisons and readiness claims match actual supported environment/edition/workflow; distinguish examples, queued/local/accepted/unknown states and avoid guaranteed tax/security outcomes.
- [ ] B08-R04 — Mobile/keyboard/screen-reader-friendly company/subscription workflows, labels/errors/focus and remaining six-theme inner-form/modal coverage; retain saved themes and #294 existing work.
- [ ] B08-R05 — Company-linked support cases with correct timestamps, owner/priority/status, evidence/duplicate links, resolution build and reopen; suggestions cannot become completed merely through a status toggle.
- [ ] B08-R06 — Role/tenant-scoped assistant reads with sources, date, unavailable answers and prompt-injection boundaries; issue intake -> redacted diagnostics -> local reproduction -> tested PR -> owner release.
- [ ] B08-R07 — Per-company AI usage/cost/latency/error/budget and escalation history; bot answer does not prove solved; preserve provider credentials/settings and use mocked delivery in tests.
- [ ] B08-R08 — Useful New indicator with per-user/version read persistence, product/role/category targeting, current/superseded releases, direct setting links and short customer-language outcomes; no unwanted repeat announcements.
- [ ] B08-R09 — Tutorial search/categories/role visibility/current-version/captions, archive stale surveys and reduce release noise while preserving legitimate notification/read history.
- [ ] B08-R10 — Validated scoped phone/email/CNIC/DOB profiles and owner-approved welcome/birthday/anniversary preferences, templates, local dates, delivery/retry/dedup history; no automatic real messages from this planning work.
- [ ] B08-R11 — Admin UI English; customer UI follows established language rules; printed tickets English. Technical implementation details stay in developer evidence rather than cashier instructions.

Acceptance:

- [ ] B08-A01 — Chosen product/package and exact trial/price survive each supported onboarding path; import preview errors/retry cannot post duplicate stock or alter another tenant.
- [ ] B08-A02 — Desktop/390px keyboard/focus/error and theme tests cover affected inner screens; labels remain accessible.
- [ ] B08-A03 — Read/unread persists appropriately across refresh/relogin; only intended audience sees updates; correcting text preserves historical seen state.
- [ ] B08-A04 — Support closure points to verified implementation/release and can reopen; no epoch timestamps or false completion.
- [ ] B08-A05 — Assistant denied scopes, unsupported question, hostile source prompt, quotas and revocation tests pass; greetings use synthetic recipients and leap-day/annual/retry dedup cases.

### B09 — Future ERP queue and shared-platform readiness

Container: [B09 / #314](https://github.com/jawadrao5555-alt/taxnest/pull/314). Sources: Existing #314 Q1-Q6 and E1-E9, #301 programme, #310 adapter contract. Dependencies: Shared financial/stock/report/control/onboarding foundations Q1-Q6 must be accepted for each selected pilot. Future expansion does not preempt existing-product defects.

- [ ] B09-R01 — Preserve the full existing Q1-Q6 outcome and E1-E9 industry checklists, scope decisions, incoming-reference process and evidence ledger; route platform implementation to the primary bulk instead of duplicating it.
- [ ] B09-R02 — Retain E1 distributor/wholesale including optional returnable-container ledger; E2 manufacturing/textile/BOM/WIP/cost/yield; E3 hotel reservation/rooms/housekeeping/deposits and guest ledger.
- [ ] B09-R03 — Retain E4 fertilizer/pesticide trade; E5 field/crop/season farm costing; E6 installment price/down-payment/due/recovery/reschedule rules without invented financing terms.
- [ ] B09-R04 — Retain E7 asset/workshop/job cards/parts/warranty/depreciation policy; E8 consignment/trip/carrier/vehicle accounts; E9 approved-device attendance/roster/leave/payroll/advance/deduction/payment.
- [ ] B09-R05 — Audit deployed Healthcare/current hotel/stock/accounts modules and gaps before creating duplicates. Use Nest ERPS registry with separate vertical scope/guards/permissions and no new product discriminator per industry.
- [ ] B09-R06 — Each pilot has agreed workflow/data model/module/role/branch/billing/reporting/diagnostic scope, migration/rollback and acceptance; competitor marketing is only discovery input, not functional evidence or copied code.
- [ ] B09-R07 — Every new module registers versioned B06 diagnostics/retention/permissions and B07 privacy/isolation; no promise future modules automatically have complete coverage.
- [ ] B09-R08 — Append later owner references in the same #314 register with exact requirement/uncertainty, deduplicate against Q/E/bulk IDs, preserve prior scope and mark complete only with functional/release acceptance.

Acceptance:

- [ ] B09-A01 — All original Q1-Q6 and E1-E9 remain tracked and unchecked until their entire promised outcome passes.
- [ ] B09-A02 — Each real ERP requires an approved pilot scope, actual end-to-end acceptance and independent financial/stock/isolation/recovery/device evidence where applicable.
- [ ] B09-A03 — Existing Healthcare vertical and older company links/settings remain compatible.
- [ ] B09-A04 — New adapter/schema test exposes missing/unknown coverage honestly; no unrelated HOLD advanced.

## Complete audit-to-bulk map

Classification below is from the private audit snapshot, not a new runtime reproduction. P-series entries are carried-forward product findings and require current-build verification. TEST/REPORTED/VIDEO items remain unverified until reproduced.

| Audit ID | Primary bulk | Original classification | Required outcome / verification |
|---|---|---|---|
| S01 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE | Company subscription dates, amount, renewal and payment links |
| S02 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE | Suspension reason, actor/time, scope and restoration condition |
| S03 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE | Company-linked audit timeline with explicit unknown legacy reasons |
| S04 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE | Subscription record status versus valid term and effective access |
| S05 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE | Structured grants, dates and contradictory legacy grant notes |
| S06 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · DESIGN/TEST | Suspension, renewal, grant and global restriction precedence |
| S07 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P2 · LIVE | Business owner/account identity and Unknown versus Never activity |
| S08 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P2 · LIVE | Operational filters and renewal/overdue/expiring-grant views |
| S09 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE/TEST | Controlled product-switch migration and historical access |
| S10 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P2 · LIVE/TEST | Cross-product groups, cloning, login/trial semantics and isolation |
| S11 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P2 · LIVE | Plan plus add-on plus override effective limits and usage |
| S12 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P2 · LIVE | Unified viewer management with explicit live/archive permissions |
| S13 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE | Subscription replacement/history reason, actor and financial lineage |
| S14 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE | Assignment/renewal/change preview with dates, amounts and purpose |
| S15 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE | One versioned trial policy across signup, clone and products |
| S16 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE | PRA package limits, roles and branches consistent across surfaces |
| S17 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE | FBR structured limits versus stale plan marketing text |
| S18 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P2 · LIVE | Retired DI plans and semantic unlimited/historical price display |
| S19 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE/TEST | Historical agreed price, proof reference and payment allocation |
| S20 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · TEST | Atomic idempotent approvals, payments, refunds and renewals |
| S21 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE/DESIGN | Separate SaaS collections/receivables from shop sales/MRR definitions |
| S22 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P2 · LIVE/TEST | Discount eligibility, dates, stacking and frozen quotes |
| S23 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 · LIVE/TEST | Paid add-on quantity, period, proration and renewal linkage |
| S24 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P2 · LIVE/TEST | Partner attribution, verified collection, refund and payout lineage |
| S25 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | P2 · LIVE | Signup preserves selected product, package and approval context |
| S26 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | P1 · LIVE/DESIGN | Visible terms/privacy/purchase/refund context and versioned acknowledgement |
| S27 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | P1 · LIVE | Evidence-backed public audit, fiscal and readiness claims |
| S28 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | P2 · LIVE | Consistent public product/edition capability comparison |
| S29 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P2 · LIVE | Shared onboarding/trial policy across customer/admin/clone channels |
| S30 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | P2 · LIVE | Usable mobile company/subscription summaries |
| S31 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | P2 · LIVE/TEST | Labels, radio semantics, validation and keyboard accessibility |
| S32 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | P1 · LIVE | Secure owner password reset and consistent password/session policy |
| S33 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | P1 · LIVE | Safe app distribution without default protection-disable guidance |
| S34 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | P1 · TEST | Granular roles, MFA, reauthentication and tenant isolation |
| S35 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | P1 · LIVE/TEST | Impersonation actor/target, permissions and meaningful audit |
| S36 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | P1 · TEST | Viewer/share/OAuth/consultant/device scope and revocation |
| S37 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | P1 · LIVE/TEST | Deletion lifecycle, required retention and restore replay |
| S38 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | P1 · TEST | Backup freshness and verified restoration/RPO/RTO |
| S39 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | P2 · LIVE/DESIGN | Privacy/data-flow inventory for assistant/support/health/device surfaces |
| S40 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | P2 · LIVE | Separate sensitive settings, masking and audited configuration changes |
| S41 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | P1 · LIVE | Operational guard-check failure visibility and safe diagnosis |
| S42 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | P2 · LIVE | Historical failed-job/export resolution with bounded safe recovery |
| S43 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | P1 · LIVE | Agent update failures propagated into company incident summaries |
| S44 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | P1 · LIVE | Fresh healthy evidence versus disabled/no activity/stale/unknown |
| S45 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | P2 · LIVE | Probe applicability, permissions and inconsistent health sources |
| S46 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | P2 · LIVE | Fleet/heartbeat/adoption scope and internal-activity exclusions |
| S47 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | P2 · LIVE | Calendar/business date and product/local/status report scope |
| S48 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | P2 · LIVE | Saved diagnostics with concise human summary and drill-down |
| S49 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | P1 · LIVE | Expired/superseded deployment state and exact release authorization |
| S50 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | P2 · LIVE/TEST | Planning, code, announcement, deployment and acceptance separation |
| S51 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | P2 · LIVE | Company support ownership, timestamps, resolution evidence and reopen |
| S52 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | P2 · LIVE/TEST | Assistant cost/usage/budget/error and real escalation outcomes |
| S53 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | P2 · LIVE | Relevant update audience, digest and stale survey handling |
| S54 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | P2 · LIVE | Tutorial search, audience, captions and current-version ownership |
| S55 | [B05 / #318](https://github.com/jawadrao5555-alt/taxnest/pull/318) | P1 · LIVE/TEST | Medicine source validation and deterministic effective-date ordering |
| P01 | [B04 / #313](https://github.com/jawadrao5555-alt/taxnest/pull/313) | P1 · earlier audit | DI integrity verification agrees with locked/protected evidence |
| P02 | [B04 / #313](https://github.com/jawadrao5555-alt/taxnest/pull/313) | P1 · earlier audit | DI quantity/base/MRP/rate arithmetic and scenario explanation |
| P03 | [B04 / #313](https://github.com/jawadrao5555-alt/taxnest/pull/313) | P1 · earlier audit | Buyer identity/registration and exemption/reportability semantics |
| P04 | [B04 / #313](https://github.com/jawadrao5555-alt/taxnest/pull/313) | P2 · earlier audit | DI invoice outcome versus API attempts and report counts |
| P05 | [B04 / #313](https://github.com/jawadrao5555-alt/taxnest/pull/313) | P2 · earlier audit | Output-tax wording and effective commercial grant display |
| P06 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | P1 · earlier audit | PRA mixed taxable/exempt deal allocation across full lifecycle |
| P07 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | P2 · earlier audit | PRA stock counts/movement/cost basis and invalid date handling |
| P08 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | P2 · earlier audit | PRA deal empty states, optional fields and import guidance |
| P09 | [B05 / #318](https://github.com/jawadrao5555-alt/taxnest/pull/318) | P1 · earlier audit | FBR local accepted versus centrally verified delivery evidence |
| P10 | [B05 / #318](https://github.com/jawadrao5555-alt/taxnest/pull/318) | P1 · earlier audit | FBR reporting/readiness/environment consistency |
| P11 | [B05 / #318](https://github.com/jawadrao5555-alt/taxnest/pull/318) | P1 · earlier audit | FBR tax/discount label, saved base and payload reconciliation |
| P12 | [B05 / #318](https://github.com/jawadrao5555-alt/taxnest/pull/318) | P1 · earlier audit | FBR sale/return/credit/refund parent, status and signed totals |
| P13 | [B05 / #318](https://github.com/jawadrao5555-alt/taxnest/pull/318) | P2 · earlier audit | FBR cost/profit coverage and selected-date consistency |
| P14 | [B05 / #318](https://github.com/jawadrao5555-alt/taxnest/pull/318) | P2 · earlier audit | FBR business-type form relevance and honest supported workflows |
| M01 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | P1 · LIVE | Final/provisional local-bill retention and recovery expectation |
| M02 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | P1 · LIVE | Retained closed-day totals versus missing/zero historical detail |
| M03 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | P2 · LIVE + V11 | Cancelled-order accountability and unknown preparation versus zero waste |
| M04 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | P1 · VIDEO / retest pending | Consistent normalized phone search across bill/order stages |
| M05 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | P1 · VIDEO / transaction test pending | Table/mode selection without unintended KOT or draft loss |
| M06 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | P1 · REPORTED + partial LIVE | Saved bill with missing receipt/KOT needs exact trace and physical check |
| M07 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | P2 · LIVE | Print evidence labels and device/bill/job correlation |
| M08 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | P2 · LIVE + V03 | Inline customer phone/address edit with correct snapshot scope |
| M09 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | P2 · LIVE + V05 | Deliveries overlay New Sale navigation and focus |
| M10 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | P1 · REPORTED / partial LIVE | Unassigned delivery shop-cash settlement distinct from delivery state |
| M11 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | P2 · REPORTED + LIVE context | Size/category drink grouping with separate deal-component accounting |
| M12 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | P2 · REPORTED + LIVE context | New badge read persistence and current/superseded release guidance |
| M13 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | P1 operational · VIDEO + LIVE | Installed Agent native launch/update/fallback verification |
| M14 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | P1 consistency · I01/A05 + earlier LIVE | Clear team-account exemptions and feature-versus-seat entitlement |
| M15 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | P2 · partial LIVE | Receipt typography/width preservation and effective printing guidance |
| M16 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | P2 · VIDEO + LIVE context | Business-day cutoff, returns wording and stable identifier lineage |

## Complete acceptance-scenario map — not executed here

| Test | Primary bulk | Scenario | Required pass evidence |
|---|---|---|---|
| T01 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Same customer different product signup | Separate tenants; chosen plan preserved; no leaked data/credentials/devices |
| T02 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Selected package trial start | Current policy exact timestamp, correct features/limits, same admin/customer copy |
| T03 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Trial boundary/expired browser session | Server evaluates expiry on actions, clear renewal path, historical data policy respected |
| T04 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Same proof double click/two reviewers | One receipt, one allocation, one entitlement extension, one commission |
| T05 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Payment save partial failure | No money/subscription half-state; retry safe |
| T06 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Early renewal | Next term starts per published policy, remaining paid time preserved |
| T07 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Late renewal | Start/credit/backdating policy explicit, no silent lost days |
| T08 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Leap year/month-end/timezone | Period calculations and grace boundaries correct in PKT |
| T09 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Suspend paid company | Reason/actor/time; existing sessions/API/devices honour chosen access policy |
| T10 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Payment arrives while suspended | Paid ledger updated; unrelated suspension remains |
| T11 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Reactivate expired company | Expiry still enforced; activation does not silently grant free term |
| T12 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Temporary grant expires/lifetime grant removed | Effective state recomputed; baseline subscription retained; audit complete |
| T13 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Suspend vs grant vs global maintenance | Chosen precedence same UI/API/jobs; explainable denial |
| T14 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Upgrade/downgrade mid-term | Quote/proration/limits and effective date predictable; existing data not destroyed |
| T15 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | User/branch/product limit | Included/excluded roles match pricing; simultaneous creation cannot exceed entitlement |
| T16 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Add-on near renewal | Correct remaining-period charge and next-term price; no double billing |
| T17 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Discount dates/stacking | Expired or incompatible discount rejected; historical quote unchanged |
| T18 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Partial payment/credit/refund/reversal | Ledger balances; entitlement and commissions follow documented policy |
| T19 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Partner payout after refund | Hold/clawback and attribution correct; no duplicate beneficiary |
| T20 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | Company soft-delete/restore/purge | Required financial/fiscal lineage retained; restored tokens/sessions safely handled |
| T21 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | Backup restore | Tested recovery time, data-loss window, tenant boundary and deletion replay |
| T22 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | Cross-tenant and branch access | UI/API/export/file/share/websocket/job denies unauthorized IDs |
| T23 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | Read-only impersonation | State-changing endpoints blocked; telemetry differentiated; actor retained |
| T24 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | Password reset/email change | Single-use expiry, notified owner, session policy and audit verified |
| T25 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | MFA/rate limits/manager PIN | Authorized QA checks, recovery and lockout do not create permanent denial loop |
| T26 | [B07 / #320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | OAuth/device/consultant revocation | Access and refresh stop across sessions; no stale background access |
| T27 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | Offline sale/reconnect/retry | One sale/stock movement/fiscal attempt lineage; no duplication or loss |
| T28 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | Taxable + exempt combo/discount/return | Preview, saved totals, tax payload, stock and accounting reconcile |
| T29 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | Return vs fiscal note vs cash refund | Correct parent, status/sign and financial settlement; no double refund |
| T30 | [B04 / #313](https://github.com/jawadrao5555-alt/taxnest/pull/313) | DI integrity legacy/current | Genuine mutations detected; stable original hashes/versioning; every displayed protection claim evidence-backed |
| T31 | [B04 / #313](https://github.com/jawadrao5555-alt/taxnest/pull/313) | DI batch grouping/re-upload | Separate source invoices stay separate; idempotent import and row-level errors |
| T32 | [B05 / #318](https://github.com/jawadrao5555-alt/taxnest/pull/318) | FBR local accepted/central failed | Separate states; safe manual correction; no false central success |
| T33 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | Date/report consistency | Same tenant/branch/date/status/local/return scope produces matching totals |
| T34 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | Missing cost/unit conversion | No unknown-as-zero profit; purchase/base/sale units and historical cost reconcile |
| T35 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | Export timeout/large data | Job progress, failure recovery, tenant-bound download, one complete output |
| T36 | [B05 / #318](https://github.com/jawadrao5555-alt/taxnest/pull/318) | Medicine sync out-of-order history | Newest applicable source wins; anomalous rows quarantined; shop changes controlled |
| T37 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | Agent interrupted update | Settings/pairing/offline data preserved; rollback and explicit failure state |
| T38 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | Stale heartbeat/zero activity | Unknown/Stale/Disabled distinct from healthy; same issue on aggregate/company cards |
| T39 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | Expired deployment approval | Never deployed under elapsed approval; current exact commit and status preserved |
| T40 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | Support escalation/closure | Linked company/ticket, owner/SLA, evidence, customer acknowledgement/reopen |
| T41 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | Notification/tutorial audience | Only intended product/role/feature sees it; private content cannot leak |
| T42 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | Mobile/keyboard/Urdu errors | Core forms usable, focus/error visible, dates/amounts accessible, no accidental destructive action |
| T43 | [B08 / #321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | AI extraction/support | Tenant isolation, redaction, spending quota, low-confidence review, no unauthorized external actions |
| T44 | [B06 / #319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | Peak load and resource fairness | Billing latency measured during exports/imports; noisy tenant cannot starve checkout |
| MT01 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | Retention: Test company's Save vs Delete policies, final/provisional records, auto/manual close; repeat close | Policy effects explicit; archive accessible where promised; deletion never masquerades as zero sales; audit and aggregates reconcile; repeated close no duplicate effect |
| MT02 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | Working-day boundary: Bill at 23:59, 00:01, 05:59 and 06:00; daily L token reset | Consistent business date across reprint, returns, reports, delivered list, close and diagnostics; permanent ID stable |
| MT03 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | Phone lookup: Same customer with 03…, +92…, spaces/hyphens; Held → Delivered → paid bill → archive | All applicable searches return the same permitted records; date/order/payment filters combine correctly; no cross-company leak |
| MT04 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | Mode/table: Takeaway draft → Dine-in free/occupied table; cancel selection, switch back, double-click | No unintended KOT or sale commit; draft preserved; explicit transfer/dispatch creates exactly expected jobs |
| MT05 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | Print: Receipt/KOT separately; prompt Yes/No; Agent offline/reconnect, paper out and retry | Correct intended printer; no lost/duplicate sale; truthful queue/Windows/paper states; original bill linked to each attempt |
| MT06 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | Cancel audit: Cancel unsent order, sent KOT, partly prepared food and paid sale reversal | Correct reason/actor/time/items; unknown prepared quantity not zero waste; money, stock and kitchen effects distinct |
| MT07 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | Customer correction: Edit checkout phone/address/name, this-order-only vs profile; duplicate number | Current draft correct, past invoice immutable, customer history not split accidentally; permission checks |
| MT08 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | New Sale: Direct and inline Deliveries; empty and nonempty cart; embedded load failure | Overlay/navigation resolves consistently; focus returns; unsaved draft prompt where needed |
| MT09 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | Shop cash: Selected unassigned cash deliveries, mixed cash/card/unpaid/already settled, retry | Exact selected amount settles once; unsupported rows excluded with reason; actor and settlement report reconcile |
| MT10 | [B02 / #317](https://github.com/jawadrao5555-alt/taxnest/pull/317) | Product report: Multiple drink sizes, standalone/deal items, returns, exemptions, archived day | Subtotals reconcile to full report without counting both deal revenue and components twice |
| MT11 | [B01 / #316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | Seats: Each plan, one/excess manager per branch, cashier/waiter/delivery/owner, role/branch change | Visible usage and backend enforcement agree with purchased entitlement and advertised counting rules |
| MT12 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | Updates/design: Read update then refresh/relogin/device; upgrade with existing receipt customizations | Correct unread badge; superseded release identified; saved receipt design remains until explicit Save |
| MT13 | [B03 / #304](https://github.com/jawadrao5555-alt/taxnest/pull/304) | Native Agent: Supported Windows upgrade to intended build; native Open POS, fallback, reconnect and print | Installed/target version verified; clear recoverable failure; actual user's device proves launch/print outcome |

MT12 crosses B03 receipt-preservation and B08 unread-state. T29/T33/T34 inform other product adapters; T20–T26 authorization/recovery constraints apply platform-wide. Cross-references do not duplicate primary ownership.

## Earlier programme and queue crosswalk

| Existing scope | Primary delivery location |
|---|---|
| #295 records/package/activity/history/tax/stock | B01 for commercial/activity; B02 for financial/stock/recipe; reuse #306 |
| #296 login/two-step/themes/timeline | B07 authentication, B08 themes/accessibility, B01 company timeline; reuse #306 |
| #297 Accounts/full-payment/trial/paid/grace | B01, with B04 DI cycle compatibility and B07 controls |
| #298 fixed/choice/Special Deals/promotions | B02; staff/family proposal/approval retained in B03 |
| #299 units/recipes/workbook/receiving/profiles/greetings | B02 inventory, B08 profile/greeting workflow; inline checkout correction B03 |
| #300 assistant/scoped reads/issue-to-tested-PR | B08 user/support workflow, B06 typed reports/diagnostics, B07 permissions |
| #310 entire whole-SaaS/ERP/source-retention/business-day/video programme | B06, preserving #315 first implementation; product adapters coordinate B02/B04/B05/B09 |
| #314 Q1 financial traceability | B01/B02/B04/B05 by product |
| #314 Q2 stock chain | B02, B04 packing and B05 pharmacy |
| #314 Q3 direct reports/diagnostics | B06 and B07 permissions |
| #314 Q4 report/export consistency | B02/B04/B05 product sources and B06 scoped exports |
| #314 Q5 controls/approval/consent | B01/B07 and retained #312 |
| #314 Q6 guided onboarding | B08 with validated B01/B02 foundations |
| #314 E1–E9 future industries | B09; all nine full original scopes retained in #314 |
| Original #304/#313 contracts | Fully retained in their expanded B03/B04 documents, not replaced by short audit labels |

## Pending clarification, not invented bugs

Media A03 asks deals progress without having tried it; A07 asks recipe/inventory progress. A06 and A09 do not identify a sufficiently clear exact promised feature; obtain the original requirement before inventing scope. A08 is a third-party trial-extension request, not authority to alter a grant; verify latest effective dates. A10 business-date association needs timestamp/cutoff evidence. I02 receipt-preservation text is a claim requiring regression acceptance. All ten voices and nine unique videos/two images were accounted for in the private review; four duplicate files do not add scope.

## Programme evidence ledger

| Date | Record | Evidence | State / limitation |
|---|---|---|---|
| 30 Oct 2026 | This consolidation | 85 unique audit IDs, 57 named scenarios, preserved original 47 checks, all Q1–Q6/E1–E9 and nine bulk containers | Scope complete; runtime implementation/acceptance not performed by this update |
| 30 Oct 2026 | Existing #312/#305/#306 | Fresh merged metadata and source lineage | Reuse code; actual rollout/functional completion separately verified |
| 30 Oct 2026 | #315 / #310 | Existing first implementation and recorded exact-head evidence; full tracker remains open | Partial capability, current source/device/production limits remain |

For future entries: bulk/requirement IDs; implementation PR/head; reproduction and test results; required CI; owner-approved release SHA; live/physical/connected evidence; unresolved gaps; state. Never replace missing evidence with a completed checkbox.

---

# Preserved original master — 26 October 2026

The following is the unchanged historical six-workstream contract, including all 47 original unchecked items. Historical draft/CI/status statements describe that earlier snapshot; the current source-state table above supersedes them for routing, without erasing requirements.

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
