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
