# B05 — FBR POS fiscal delivery, returns, reporting and pharmacy integrity

**Primary source records:** Audit P09-P14/S55; reuse existing FBR reconciliation, IMS/Agent, supplier ledger and medicine-catalogue services.

**Dependencies:** B06 evidence/correlation, B07 credential/access isolation; shared money/stock snapshots coordinate B02 without copying PRA semantics blindly.

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

- [ ] B05-R01 — Separate configured environment/credentials, Agent heartbeat, local IMS acceptance, central upload and central verification. Record last evidence time, pending queue and exact missing proof.
- [ ] B05-R02 — Investigate credential/environment/local-central discrepancies using permitted redacted evidence; preserve original accepted identifiers and prevent blind resubmission/duplicate fiscal sales.
- [ ] B05-R03 — Unify effective reporting/settings status; remove unsupported readiness/certification claims and explain Agent fiscal-vs-printing capabilities.
- [ ] B05-R04 — Reconcile price/tax-rate label, order discounts, taxable base, saved transaction and fiscal payload; establish and test intended pre-/post-tax discount rules using applicable source evidence.
- [ ] B05-R05 — Link sale, return/credit note and cash refund; preserve parent/sign/status and gross/return/net reconciliation, idempotent settlement and historical snapshots.
- [ ] B05-R06 — Profit/cost coverage uses real cost evidence and the same selected period; unknown cost is not zero or 100% coverage.
- [ ] B05-R07 — Business-type screens expose only applicable pharmacy/retail/restaurant controls and honest unsupported-workflow boundaries.
- [ ] B05-R08 — Medicine source sync quarantines invalid rows, handles out-of-order effective dates/ties deterministically and preserves provenance/current applicable MRP; no silent shop repricing.
- [ ] B05-R09 — Verify supplier purchase/bonus/discount/receiving/returns/payments and batch/FEFO/expiry/loose-unit history where touched; cross-branch/tenant scope and existing financial invariants retained.

## Bulk acceptance

- [ ] B05-A01 — Local accepted / central unknown-or-failed fixture cannot produce a centrally verified badge; successful production delivery requires separate matching actual evidence.
- [ ] B05-A02 — Settings and runtime environment agree; invalid credentials are represented honestly without exposing secrets.
- [ ] B05-A03 — Tax/discount/return fixtures reconcile stored invoice, payload, reports and refund ledger; no duplicate fiscal event on retry.
- [ ] B05-A04 — Profit denominator/date basis and unknown costs are explicit; old source updates cannot replace newer applicable medicine data.
- [ ] B05-A05 — Retail/pharmacy role, tenant, branch, stock/batch and historical snapshot regressions pass.

## Audit coverage — primary owner

| Audit ID | Original classification | Required investigation/outcome |
|---|---|---|
| S55 | P1 · LIVE/TEST | Medicine source validation and deterministic effective-date ordering |
| P09 | P1 | FBR local accepted versus centrally verified delivery evidence |
| P10 | P1 | FBR reporting/readiness/environment consistency |
| P11 | P1 | FBR tax/discount label, saved base and payload reconciliation |
| P12 | P1 | FBR sale/return/credit/refund parent, status and signed totals |
| P13 | P2 | FBR cost/profit coverage and selected-date consistency |
| P14 | P2 | FBR business-type form relevance and honest supported workflows |

## Named acceptance scenarios — planned, not passed

| Test | Scenario | Required pass evidence |
|---|---|---|
| T32 | FBR local accepted/central failed | Separate states; safe manual correction; no false central success |
| T36 | Medicine sync out-of-order history | Newest applicable source wins; anomalous rows quarantined; shop changes controlled |

Cross-bulk cases remain coordinated: MT12 spans receipt preservation (B03) and unread updates (B08); T29/T33/T34 also inform B04/B05; B07's denial/revocation controls apply to every product. These references do not create duplicate implementation ownership.
