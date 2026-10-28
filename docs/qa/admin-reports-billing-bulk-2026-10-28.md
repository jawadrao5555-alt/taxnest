# Admin planning: consolidate reports, notification targeting and billing consent

Owner-requested consolidation — 28 October 2026.

**PLANNING ONLY — implementation and functional acceptance remain pending. Do not deploy as a finished feature, even if documentation CI is green.**

## Scope
Company IDs/product-specific reports and exports; exact product/category notification delivery; Local/Reported decisions and owner-consented optional archive.

## Pinned source records

| Source PR | Exact head | Preserved specification |
|---|---|---|
| #278 | `858161d4fb47d43b860fb864b0988725654104ce` | `docs/qa/admin-report-filters-2026-10-25.md` |
| #279 | `e688eb9739cb69dca8755a77b9324749d41731ff` | `docs/qa/notification-targeting-2026-10-25.md` |
| #273 | `e9319df287596c7566f09da0926b623bab6c9b7e` | `docs/qa/admin-billing-consent-archive-2026-10-23.md` |

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
Does not implement the remaining six-workstream #301 tracker, AI diagnostics #310, two-step verification, accounts/trials/payment lifecycle or unrelated promotions/recipe/ERP requirements.
#237 HOLD is excluded. #305 Agent bulk and #304 PRA POS planning are separate. No automatic existing settings/design changes. Requirements-only issues and a tracking PR are not implemented features.

## Evidence
Source changed-file lists and pinned document blobs were inspected: each source adds one Markdown specification, with no application changes. This consolidation adds those records plus this index only. No functional test pass, live behavior change or release readiness is claimed. Recheck stale source observations before implementation. No production credentials/data/settings, reprint, cache deletion, approval, merge or workflow dispatch used.
