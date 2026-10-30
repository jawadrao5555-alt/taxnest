# B01 — Company lifecycle, subscriptions, payments and packages

**Primary source records:** #295, #296 (timeline), #297, #291/#292 history, #312 reporting foundation.

**Dependencies:** Shared authorization/financial-history invariants; coordinate DI cycles with B04. Display-only company context can land before payment/access rule changes.

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

- [ ] B01-R01 — One company overview showing owner/product/branches, selected and historical plan, trial/paid/grace dates, original price, payments, next renewal, usage and effective access with reason.
- [ ] B01-R02 — Separate approval, subscription term, explicit suspension, temporary/lifetime grant, payment and effective access states. Capture suspension/reactivation actor, reason, scope, timestamps and restore condition; unknown old reasons remain unknown.
- [ ] B01-R03 — Consolidate company timeline; distinguish real staff activity from admin viewing/impersonation. Add renewal/overdue/grant-expiring saved views and direct company links.
- [ ] B01-R04 — Versioned plan/entitlement source shared by pricing, signup, admin and server enforcement; explain counted/exempt roles, branches, add-ons, overrides, limits and period reset. Preserve retired plans and historical agreed prices.
- [ ] B01-R05 — Complete #297: selected-package 72-hour trial with unlimited trial bills, full-payment activation, paid period after trial, explicit early/late renewal rules and 7-day expiry grace/reminders/lock/recovery. Preserve grandfathered paid periods and grants; resolve rule precedence before migrations.
- [ ] B01-R06 — Atomic and idempotent payment -> receipt -> allocation -> subscription/approval -> commission; record payer/reference/method/paid-at/verified-by; handle partial payments, credits, refund/reversal and concurrent reviewers.
- [ ] B01-R07 — Separate TaxNest income/expense/receivables/partner payable from tenant shop sales. Trace each aggregate to immutable source entries; define MRR/ARR exclusions if added.
- [ ] B01-R08 — Version discounts, quotes, add-on terms/proration and referral attribution; test commission clawback and prevent double extension/payment/beneficiary.
- [ ] B01-R09 — Controlled product migration and customer groups/cloning; preserve tenant isolation, trial policy and historical access; unify viewer scope and revocation presentation.

## Bulk acceptance

- [ ] B01-A01 — An expired paid term with explicit suspension and a temporary grant has one explainable effective decision, identical across UI/server actions; payment does not silently remove suspension.
- [ ] B01-A02 — Double-click, retry and concurrent payment confirmation create one financial allocation/receipt/extension/commission, or roll back together.
- [ ] B01-A03 — 72-hour, 7-day, month-end/leap-year and PKT boundaries; early/late renewal; existing grandfathered company and second tenant all pass.
- [ ] B01-A04 — Advertised role/branch limits equal enforced limits under concurrent creation; no raw -1 or ambiguous zero/unlimited.
- [ ] B01-A05 — Company overview, subscription history, payment proof, ledger and dashboard reconcile to the same original price, term and source records.

## Audit coverage — primary owner

| Audit ID | Original classification | Required investigation/outcome |
|---|---|---|
| S01 | P1 · LIVE | Company subscription dates, amount, renewal and payment links |
| S02 | P1 · LIVE | Suspension reason, actor/time, scope and restoration condition |
| S03 | P1 · LIVE | Company-linked audit timeline with explicit unknown legacy reasons |
| S04 | P1 · LIVE | Subscription record status versus valid term and effective access |
| S05 | P1 · LIVE | Structured grants, dates and contradictory legacy grant notes |
| S06 | P1 · DESIGN/TEST | Suspension, renewal, grant and global restriction precedence |
| S07 | P2 · LIVE | Business owner/account identity and Unknown versus Never activity |
| S08 | P2 · LIVE | Operational filters and renewal/overdue/expiring-grant views |
| S09 | P1 · LIVE/TEST | Controlled product-switch migration and historical access |
| S10 | P2 · LIVE/TEST | Cross-product groups, cloning, login/trial semantics and isolation |
| S11 | P2 · LIVE | Plan plus add-on plus override effective limits and usage |
| S12 | P2 · LIVE | Unified viewer management with explicit live/archive permissions |
| S13 | P1 · LIVE | Subscription replacement/history reason, actor and financial lineage |
| S14 | P1 · LIVE | Assignment/renewal/change preview with dates, amounts and purpose |
| S15 | P1 · LIVE | One versioned trial policy across signup, clone and products |
| S16 | P1 · LIVE | PRA package limits, roles and branches consistent across surfaces |
| S17 | P1 · LIVE | FBR structured limits versus stale plan marketing text |
| S18 | P2 · LIVE | Retired DI plans and semantic unlimited/historical price display |
| S19 | P1 · LIVE/TEST | Historical agreed price, proof reference and payment allocation |
| S20 | P1 · TEST | Atomic idempotent approvals, payments, refunds and renewals |
| S21 | P1 · LIVE/DESIGN | Separate SaaS collections/receivables from shop sales/MRR definitions |
| S22 | P2 · LIVE/TEST | Discount eligibility, dates, stacking and frozen quotes |
| S23 | P1 · LIVE/TEST | Paid add-on quantity, period, proration and renewal linkage |
| S24 | P2 · LIVE/TEST | Partner attribution, verified collection, refund and payout lineage |
| S29 | P2 · LIVE | Shared onboarding/trial policy across customer/admin/clone channels |
| M14 | P1 consistency · I01/A05 + earlier LIVE | Clear team-account exemptions and feature-versus-seat entitlement |

## Named acceptance scenarios — planned, not passed

| Test | Scenario | Required pass evidence |
|---|---|---|
| T01 | Same customer different product signup | Separate tenants; chosen plan preserved; no leaked data/credentials/devices |
| T02 | Selected package trial start | Current policy exact timestamp, correct features/limits, same admin/customer copy |
| T03 | Trial boundary/expired browser session | Server evaluates expiry on actions, clear renewal path, historical data policy respected |
| T04 | Same proof double click/two reviewers | One receipt, one allocation, one entitlement extension, one commission |
| T05 | Payment save partial failure | No money/subscription half-state; retry safe |
| T06 | Early renewal | Next term starts per published policy, remaining paid time preserved |
| T07 | Late renewal | Start/credit/backdating policy explicit, no silent lost days |
| T08 | Leap year/month-end/timezone | Period calculations and grace boundaries correct in PKT |
| T09 | Suspend paid company | Reason/actor/time; existing sessions/API/devices honour chosen access policy |
| T10 | Payment arrives while suspended | Paid ledger updated; unrelated suspension remains |
| T11 | Reactivate expired company | Expiry still enforced; activation does not silently grant free term |
| T12 | Temporary grant expires/lifetime grant removed | Effective state recomputed; baseline subscription retained; audit complete |
| T13 | Suspend vs grant vs global maintenance | Chosen precedence same UI/API/jobs; explainable denial |
| T14 | Upgrade/downgrade mid-term | Quote/proration/limits and effective date predictable; existing data not destroyed |
| T15 | User/branch/product limit | Included/excluded roles match pricing; simultaneous creation cannot exceed entitlement |
| T16 | Add-on near renewal | Correct remaining-period charge and next-term price; no double billing |
| T17 | Discount dates/stacking | Expired or incompatible discount rejected; historical quote unchanged |
| T18 | Partial payment/credit/refund/reversal | Ledger balances; entitlement and commissions follow documented policy |
| T19 | Partner payout after refund | Hold/clawback and attribution correct; no duplicate beneficiary |
| MT11 | Seats: Each plan, one/excess manager per branch, cashier/waiter/delivery/owner, role/branch change | Visible usage and backend enforcement agree with purchased entitlement and advertised counting rules |

Cross-bulk cases remain coordinated: MT12 spans receipt preservation (B03) and unread updates (B08); T29/T33/T34 also inform B04/B05; B07's denial/revocation controls apply to every product. These references do not create duplicate implementation ownership.
