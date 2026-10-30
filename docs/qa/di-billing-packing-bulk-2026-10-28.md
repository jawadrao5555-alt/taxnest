# DI planning: consolidate subscription cycles and Smart UOM packing

Owner-requested consolidation — 28 October 2026.

**PLANNING ONLY — implementation and functional acceptance remain pending. Do not deploy as a finished feature, even if documentation CI is green.**

## Scope
Monthly/quarterly/semi-annual/annual DI subscription billing; optional product sell-by packings, exact base/FBR conversions and immutable invoice/return/inventory snapshots.

## Pinned source records

| Source PR | Exact head | Preserved specification |
|---|---|---|
| #264 | `c4b42f42da76473a3ed71f96b7bfe77eaa2f08ad` | `docs/di-billing-cycles-263.md` |
| #266 | `a9dc76e4bee4edb84972d6e6242a9fb11c48da95` | `docs/smart-uom-packing-265.md` |

All five source PRs contain documentation only. This bulk preserves the relevant original documents byte-for-byte and their published commits as parents; it does not merge stale runtime trees. Base is owner-deployed #311, `f69a4d22650532ba6f10ed37d5da6caf41ffa7d2`. Originals remain open for traceability; use this bulk for combined requirements review. No auto-close keyword is used.

## Delivery checklist
- [ ] Re-audit current runtime against each source requirement; distinguish already-deployed behavior from missing implementation.
- [ ] Reproduce original defects or missing flows with fictional tenants and record evidence.
- [ ] Implement using existing policies/services; preserve existing company settings, financial history, fiscal identities and tenant boundaries.
- [ ] Add relevant regression, denial, retry/concurrency and backwards-compatibility coverage.
- [ ] Verify actual affected UI at desktop/390px and PHP8.4/MariaDB where applicable.
- [ ] Require own latest current-head eight mandatory CI jobs including validate, locked audit/build, browser, full PHP, MariaDB, Node22, workflow and release proof; clean mergeability and review findings.
- [ ] Obtain fresh owner Admin approval for the exact implementation head. Agent never approves, merges or deploys.

## Boundaries and overlaps
No guessed statutory UOM mapping or prices, billing charges, fiscal submissions, legacy pack_size reinterpretation or changes to issued invoices. #262 rounding coordination must be reviewed separately.
#237 HOLD is excluded. #305 Agent bulk and #304 PRA POS planning are separate. No automatic existing settings/design changes. Requirements-only issues and a tracking PR are not implemented features.

## Evidence
Source changed-file lists and pinned document blobs were inspected: each source adds one Markdown specification, with no application changes. This consolidation adds those records plus this index only. No functional test pass, live behavior change or release readiness is claimed. Recheck stale source observations before implementation. No production credentials/data/settings, reprint, cache deletion, approval, merge or workflow dispatch used.


---

# B04 — Digital Invoice integrity, reporting, billing cycles and Smart UOM

**Primary source records:** #313 retaining #264/#263 and #266/#265; coordinate #262 rounding; preserve CCI/HOLD source boundaries.

**Dependencies:** Shared payment/entitlement engine B01, inventory conversion semantics B02, scoped diagnostics B06 and security B07.

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

- [ ] B04-R01 — Retain every original #313 billing-cycle and optional Smart UOM/packing requirement and original source documents; this expansion does not replace their exact rules.
- [ ] B04-R02 — Resolve protected/locked vs failing integrity verification using original stored values and versioned canonical hashing; distinguish genuine mutation from legacy compatibility and never silently rehash away evidence.
- [ ] B04-R03 — Explain the actual taxable base/rate/scenario and reconcile quantity, MRP/selling price, discounts, tax, totals, saved invoice/PDF/payload; verify statutory mappings separately from UI arithmetic.
- [ ] B04-R04 — Separate buyer identity from registration status and product exemption from scenario/reportability; preserve drafts, invoice locking, submission idempotency and fiscal data isolation.
- [ ] B04-R05 — Reconcile invoice outcomes vs API attempts, periods, withholding/completed counts and ledger/MIS filters; label output tax and effective billing grants accurately.
- [ ] B04-R06 — Implement monthly/quarterly/semiannual/annual DI cycles using configured/versioned prices and discounts, compatible plans, renewal receipts and shared paid/grace rules; do not invent commercial amounts.
- [ ] B04-R07 — Optional Sell By packings: exact purchase/base/sales/FBR UOM conversions for compatible units, bags/cartons/pack sizes and fractional sizes; reject incompatible conversions and preserve legacy pack_size meaning.
- [ ] B04-R08 — Freeze conversion, tax, rate, price, cost and packing at sale time for invoices/PDF/API/returns/inventory. Re-upload/import grouping idempotent with row-level errors and no source invoice mixing.
- [ ] B04-R09 — Record source evidence required for any CCI import and leave #237 and other explicit HOLD decisions unchanged; no guessed buyer/SKU/tax evidence or regulator submission.

## Bulk acceptance

- [ ] B04-A01 — Current and legacy integrity fixtures detect genuine tampering and preserve original immutable evidence; UI protection status matches actual verification.
- [ ] B04-A02 — Billing cycles pass month/leap/PKT boundaries, retry/concurrency, refunds and existing-plan preservation.
- [ ] B04-A03 — Exact compatible UOM examples reconcile bill/API/PDF/stock/return; changing later pack definitions never rewrites issued invoices.
- [ ] B04-A04 — Invoice vs attempt reports, rows/counts/totals/export align and unknown data remain explicit; second tenant denied.
- [ ] B04-A05 — Full original #263/#265 contracts and #262 coordination are verified before complete status; existing CCI evidence/HOLD conditions remain.

## Audit coverage — primary owner

| Audit ID | Original classification | Required investigation/outcome |
|---|---|---|
| P01 | P1 | DI integrity verification agrees with locked/protected evidence |
| P02 | P1 | DI quantity/base/MRP/rate arithmetic and scenario explanation |
| P03 | P1 | Buyer identity/registration and exemption/reportability semantics |
| P04 | P2 | DI invoice outcome versus API attempts and report counts |
| P05 | P2 | Output-tax wording and effective commercial grant display |

## Named acceptance scenarios — planned, not passed

| Test | Scenario | Required pass evidence |
|---|---|---|
| T30 | DI integrity legacy/current | Genuine mutations detected; stable original hashes/versioning; every displayed protection claim evidence-backed |
| T31 | DI batch grouping/re-upload | Separate source invoices stay separate; idempotent import and row-level errors |

Cross-bulk cases remain coordinated: MT12 spans receipt preservation (B03) and unread updates (B08); T29/T33/T34 also inform B04/B05; B07's denial/revocation controls apply to every product. These references do not create duplicate implementation ownership.
