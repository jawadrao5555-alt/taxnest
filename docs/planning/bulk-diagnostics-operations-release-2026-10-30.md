# B06 — Whole-SaaS diagnostics, direct reports, Agent evidence and release reliability

**Primary source records:** FULL #310 checklist; #315 first PRA implementation; #300/#290 scoped tools; merged #309/#305/#312 foundations.

**Dependencies:** Reconcile deployed SHA and #312 recorded release failure before any new production readiness claim. Product adapters coordinate B02/B04/B05/B09; security contract B07.

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

## Bulk acceptance

- [ ] B06-A01 — Preserve #315 exact implementation and existing evidence; current-head acceptance must be refreshed if implementation changes. Whole #310 remains incomplete until every agreed adapter/source is accepted.
- [ ] B06-A02 — Seven-day and exact retention boundaries, missing/offline/late/duplicate/out-of-order events, failed storage and restored data expose truthful coverage without changing business records.
- [ ] B06-A03 — Real authorized connected-client tests prove reads, redaction, pagination/export, immediate revocation and two-tenant/branch denial.
- [ ] B06-A04 — Cross-midnight synthetic incident and video ambiguity tests produce grounded timelines with no physical-paper or universal-cause claim.
- [ ] B06-A05 — Fresh fleet/source health agrees between detailed and owner views; zero data cannot pass as healthy.
- [ ] B06-A06 — Expired deployment authorization cannot release; exact fixing SHA requires Actions live verification plus promised functional/device acceptance.

## Audit coverage — primary owner

| Audit ID | Original classification | Required investigation/outcome |
|---|---|---|
| S41 | P1 · LIVE | Operational guard-check failure visibility and safe diagnosis |
| S42 | P2 · LIVE | Historical failed-job/export resolution with bounded safe recovery |
| S43 | P1 · LIVE | Agent update failures propagated into company incident summaries |
| S44 | P1 · LIVE | Fresh healthy evidence versus disabled/no activity/stale/unknown |
| S45 | P2 · LIVE | Probe applicability, permissions and inconsistent health sources |
| S46 | P2 · LIVE | Fleet/heartbeat/adoption scope and internal-activity exclusions |
| S47 | P2 · LIVE | Calendar/business date and product/local/status report scope |
| S48 | P2 · LIVE | Saved diagnostics with concise human summary and drill-down |
| S49 | P1 · LIVE | Expired/superseded deployment state and exact release authorization |
| S50 | P2 · LIVE/TEST | Planning, code, announcement, deployment and acceptance separation |
| M07 | P2 · LIVE | Print evidence labels and device/bill/job correlation |

## Named acceptance scenarios — planned, not passed

| Test | Scenario | Required pass evidence |
|---|---|---|
| T35 | Export timeout/large data | Job progress, failure recovery, tenant-bound download, one complete output |
| T38 | Stale heartbeat/zero activity | Unknown/Stale/Disabled distinct from healthy; same issue on aggregate/company cards |
| T39 | Expired deployment approval | Never deployed under elapsed approval; current exact commit and status preserved |
| T44 | Peak load and resource fairness | Billing latency measured during exports/imports; noisy tenant cannot starve checkout |

Cross-bulk cases remain coordinated: MT12 spans receipt preservation (B03) and unread updates (B08); T29/T33/T34 also inform B04/B05; B07's denial/revocation controls apply to every product. These references do not create duplicate implementation ownership.
