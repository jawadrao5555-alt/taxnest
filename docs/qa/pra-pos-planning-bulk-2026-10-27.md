# Consolidated PRA POS planning — 27 October 2026

Owner requested one separate planning PR combining #280, #281 and #261. This contains specifications only; it does not implement screens, APIs, migrations, renderers or fiscal behavior and is not a feature deployment candidate.

Base: deployed #303, `a7875f3cb27f1f73b7dc9ea0ebd01c3e2dc43de1`.

| Source | Pinned head | Contract |
|---|---|---|
| #280 | `0dabc5ed86473bd9591453ff94f8381ae1330c7c` | KOT: current + eight optional styles |
| #281 | `b345322eb018de43e0767c2a0d8c94ead2c25897` | Receipts: current + eight optional styles |
| #261 | `0df59a0af08fe0ef4cf0b5b01f0265f5482bbe05` | Staff/family discount proposal and owner revision/approval |

## Retained requirements

- KOT: existing/current option plus eight new options = nine. Local and Fiscal independently select the same or different style.
- Receipts: existing/current option plus eight new options = nine; independent Local/Fiscal selections. Preserve enabled logo/QR/footer/paper width and the opt-in typography delivered in #303.
- Existing companies keep their exact saved designs/settings. Only an authorized user's explicit selection and Save applies a change. Missing fields keep legacy fallback; preview/cancel neither persist nor print.
- Preserve quantities, relevant item notes, mixed fulfillment, add-on/reprint semantics, financial snapshots and factual Local/Fiscal identity. No fabricated fiscal reference or verification QR.
- Staff/family discount: cashier proposes 1–99% or a cash amount; owner/admin may revise then approve/reject before finalization. Normal PRA reporting-OFF finals retain NULL fiscal status; reporting-ON follows normal submission only after approval.
- Prevent duplicate/stale approvals, cross-tenant/role access and double discount. Preserve existing bills, numbering, stock, returns and snapshots.

## Source contracts

- [KOT styles](kot-nine-styles-2026-10-25.md)
- [Receipt styles](receipt-nine-styles-2026-10-25.md)
- [Staff/family discount](../specs/staff-family-discount-approval-2026-10-23.md)

#301 remains the broader six-workstream/47-item master tracker. These three specifications do not implement that programme. #278 reports, #279 notification targeting and #273 POS billing decisions/consent archive remain separate scopes; coordinate overlapping status/discount/history requirements rather than claiming they are duplicates. HOLD #237 remains unchanged.

## Acceptance and release boundary

All source heads/history are preserved. Original PRs remain open as references to this consolidated planning record. Future focused implementation PRs must reproduce/retest actual desktop/390px previews and output, legacy/custom settings, two tenants, Local/Fiscal truth, explicit Save, authorized access and financial/concurrency behavior, and pass their own mandatory current-head checks before owner release approval. Specification CI does not prove any feature implemented.

Validation here: source scope and pinned identities checked; Markdown paths and whitespace checked. Application/browser tests are N/A for this documentation-only consolidation. No company setting/data mutation, physical printing, approval, merge or deploy performed.


---

# B03 — PRA checkout, KOT, receipts and Agent experience

**Primary source records:** #304 retaining #280/#281/#261; reuse merged #303/#311 receipt controls and #305 Agent work; #315 is diagnostic evidence, not the print fix.

**Dependencies:** B02 supplies money/discount invariants; B06 provides correlation. Reproduce operational failures before optional new styles. Upgrade/rollout verification precedes duplicate Agent implementation.

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

## Bulk acceptance

- [ ] B03-A01 — Reproduce/retest each reported search/edit/navigation/table flow in real local Chrome at desktop and 390px, including nonempty cart and second tenant.
- [ ] B03-A02 — Actual device/printer acceptance separately proves receipt and KOT output; queue/spool success alone remains paper-unverified.
- [ ] B03-A03 — One/many printer/counter, legacy Agent, offline/reconnect, double-click, failed-route and reprint cases do not duplicate bills or kitchen dispatch.
- [ ] B03-A04 — Existing custom Local/Fiscal layouts survive upgrade, preview and cancel; all proposed styles preserve truthful fiscal/financial content.
- [ ] B03-A05 — Discount approval concurrency, unauthorized role, stale proposal, returns and snapshot tests pass; HOLD #237 is not included.
- [ ] B03-A06 — Installed Windows launch/update/Save As acceptance recorded separately from packaging CI and browser fallback.

## Audit coverage — primary owner

| Audit ID | Original classification | Required investigation/outcome |
|---|---|---|
| M04 | P1 · VIDEO / retest pending | Consistent normalized phone search across bill/order stages |
| M05 | P1 · VIDEO / transaction test pending | Table/mode selection without unintended KOT or draft loss |
| M06 | P1 · REPORTED + partial LIVE | Saved bill with missing receipt/KOT needs exact trace and physical check |
| M08 | P2 · LIVE + V03 | Inline customer phone/address edit with correct snapshot scope |
| M09 | P2 · LIVE + V05 | Deliveries overlay New Sale navigation and focus |
| M13 | P1 operational · VIDEO + LIVE | Installed Agent native launch/update/fallback verification |
| M15 | P2 · partial LIVE | Receipt typography/width preservation and effective printing guidance |

## Named acceptance scenarios — planned, not passed

| Test | Scenario | Required pass evidence |
|---|---|---|
| T27 | Offline sale/reconnect/retry | One sale/stock movement/fiscal attempt lineage; no duplication or loss |
| T37 | Agent interrupted update | Settings/pairing/offline data preserved; rollback and explicit failure state |
| MT03 | Phone lookup: Same customer with 03…, +92…, spaces/hyphens; Held → Delivered → paid bill → archive | All applicable searches return the same permitted records; date/order/payment filters combine correctly; no cross-company leak |
| MT04 | Mode/table: Takeaway draft → Dine-in free/occupied table; cancel selection, switch back, double-click | No unintended KOT or sale commit; draft preserved; explicit transfer/dispatch creates exactly expected jobs |
| MT05 | Print: Receipt/KOT separately; prompt Yes/No; Agent offline/reconnect, paper out and retry | Correct intended printer; no lost/duplicate sale; truthful queue/Windows/paper states; original bill linked to each attempt |
| MT07 | Customer correction: Edit checkout phone/address/name, this-order-only vs profile; duplicate number | Current draft correct, past invoice immutable, customer history not split accidentally; permission checks |
| MT08 | New Sale: Direct and inline Deliveries; empty and nonempty cart; embedded load failure | Overlay/navigation resolves consistently; focus returns; unsaved draft prompt where needed |
| MT12 | Updates/design: Read update then refresh/relogin/device; upgrade with existing receipt customizations | Correct unread badge; superseded release identified; saved receipt design remains until explicit Save |
| MT13 | Native Agent: Supported Windows upgrade to intended build; native Open POS, fallback, reconnect and print | Installed/target version verified; clear recoverable failure; actual user's device proves launch/print outcome |

Cross-bulk cases remain coordinated: MT12 spans receipt preservation (B03) and unread updates (B08); T29/T33/T34 also inform B04/B05; B07's denial/revocation controls apply to every product. These references do not create duplicate implementation ownership.
