# Admin planning: consolidate reports, notification targeting and billing consent

Owner-requested consolidation — 28 October 2026.

**PARTIAL IMPLEMENTATION — reports and notification correction are implemented; billing decisions/owner-consented archive remain pending. Not deploy-ready.**

## Scope
Company IDs/product-specific reports and exports; exact product/category notification delivery; Local/Reported decisions and owner-consented optional archive.

## Pinned source records

| Source PR | Exact head | Preserved specification |
|---|---|---|
| #278 | `858161d4fb47d43b860fb864b0988725654104ce` | `docs/qa/admin-report-filters-2026-10-25.md` |
| #279 | `e688eb9739cb69dca8755a77b9324749d41731ff` | `docs/qa/notification-targeting-2026-10-25.md` |
| #273 | `e9319df287596c7566f09da0926b623bab6c9b7e` | `docs/qa/admin-billing-consent-archive-2026-10-23.md` |

All three source PRs contain documentation only. This bulk preserves the relevant original documents byte-for-byte and their published commits as parents; it does not merge stale runtime trees. Base is owner-deployed #311, `f69a4d22650532ba6f10ed37d5da6caf41ffa7d2`. Originals remain open for traceability; use this bulk for combined requirements review. No auto-close keyword is used.

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


## Runtime checkpoint — 28 October 2026

Reports portion of #278 only; notification publishing/correct historical delivery
(#279) and billing decisions/consent archive (#273) remain pending. Bulk stays draft.

Two original real HTTP-rendered/CSV regressions failed before edits: missing
transaction-specific status controls and irrelevant subscription CSV columns.
After correction, portable PHP8.4.27 + disposable SQLite report/billing smoke
suite: **15 tests / 234 assertions PASS**. Covers all thirteen screens, filtered
counts/revenue/CSV, company/product denial, read-only PDF preview, legacy saved
mode/status options, DI environment versus fiscal identity and unchanged records.
Dashboard Company IDs/report shortcuts, relevant controls and table columns,
plus actual canonical desktop/390px browser assertions were added. JS syntax
and whitespace pass; those actual browser assertions are not yet executed.

Full suite, Chrome, native MariaDB and own latest eight CI jobs remain required.
The recovered local portable runtime lacks GD/exif/imap; locked dependencies
were installed without scripts while temporarily ignoring those extension
requirements only for these non-image tests. No lock/gate changes or full
runtime/audit acceptance claimed. Neither bulk is complete or deploy-ready.


## Runtime checkpoint — 29 October 2026

Base refresh preserves owner-deployed #305 squash `2f625267486ee0b18cd9972fcd00057321420099` and source history. Reports browser accessibility correction passed both real desktop/390px checks in run 40825907166; full PHP, MariaDB, Node22, workflow and release proof also passed. That run was **6/8**, not ready: Composer audit found Symfony Mime CVE-2026-45070; validate correctly failed. Patch commit `8f7f5a7b89006c74b2008dcab23a04f33ad488a3` updates Mime 7.4.13 to 7.4.22 and three compatible polyfills without weakening audit or changing composer.json. Fresh local Composer audit reports no advisories. Its CI evidence is superseded by any later implementation head.

Notification correction:
- Reproduced exact historical FBR receipt delivery to PRA with a failing test. Read-time exact deployment-key exclusion now keeps it out of PRA while preserving stored content/key/dates and seen acknowledgements. Same-title unrelated manual and shared Agent notices remain shared. Real HTTP-rendered PRA/FBR pages, bell eligibility and mark-seen denial are covered.
- Committed publication requires explicit PRA/FBR/DI/all scope; malformed/duplicate YAML fields and unknown/incompatible categories fail before transport, including command-line dry runs. Legacy all still means both POS products, never DI. Category registry parity is checked against the actual PHP models.
- Freshness now requires the exact deployment receipt AND exact intended audience/category set, published status, original marker and timestamp (or an exact same-SHA rerun). Archived, deleted, wrong-scope, wrong-SHA and unrelated notices cannot satisfy it. A pre-deployment-key schema fails closed rather than accepting an ambiguous human title; the currently deployed schema already has this key. Existing owner emergency controls are unchanged, never used as acceptance.
- Added 52 actual remote-script transport-fixture scenarios (4 audiences x 13 cases), category SQL checks and a real MariaDB JSON receipt-scope probe in the existing isolated lab. These never access production.

Local PHP8.4.27: reports/billing smoke **15 tests /234 assertions PASS**; notification plus password reset/email verification **91 tests /383 assertions PASS**; strict scope/parser/same-SHA suites PASS; PHP/bash syntax and whitespace PASS. Portable runtime still lacks GD/exif/imap; local Node is 24, not the required CI Node22. Native MariaDB/Unix sockets are unavailable in this container, so no local native acceptance is claimed; the new MariaDB probe and final full/browser/Node22 evidence must pass in current-head CI.

Billing #273 remains explicitly unimplemented. In particular, a read-only label must not become deletion eligibility; owner-only default-OFF consent, atomic snapshots, financial/stock/quota invariants, expiry/revocation and restore suppression still need implementation and acceptance. No companies opted in, settings changed, stored financial history rewritten, production workflows dispatched or deployment claimed.


## Read-only billing classification checkpoint — 29 October 2026

Added the first #273 report slice, not financial decision execution or archive consent. Actual fiscal number establishes Reported. Known pending/failed/submitted-without-number states stay Reporting Pending/Failed; known persisted reporting-OFF finals (completed + PRA/FBR/null routing + null status) are Local Final independently of current company settings. Local/local provisionals remain Pending Decision. Unknown legacy states remain explicitly unclassified, never silently Local. These labels are **not** used for deletion permission or mutation.

Composable billing disposition, Local/unreported grouping (including pending/failed), branch, business date and payment filters share the same SQL query for rows/counts/totals/CSV. Existing company/product constraints and created-at filters remain. PHP rendered/CSV suite now **16 tests /306 assertions PASS**, including 20 state fixtures across PRA/FBR, fiscal-number precedence over failed status/routing, missing-number submitted state, legacy values, all aggregate/filter/CSV matches and unchanged records. Added actual desktop/390px selector assertion and 20 native MariaDB SQL/row equivalence cases; final-head browser/native execution pending.

Still required: owner/company policy decision actions, races and all optional archive consent/storage/expiry/revocation/restore acceptance. No financial state changed and no readiness claim.


## Financial decision guard checkpoint — 29 October 2026

Future queue and owner priority are maintained in planning PR #314; it is not a deployment candidate.

At head `91fdd0167db653c40344084cc6fd08c5e631aad3`, CI40835527970 completed all eight mandatory jobs including validate successfully, with actual desktop/390px, full PHPUnit, native MariaDB, Node22, locked dependency audit/build and workflow/release proof. These greens become historical once the following new changes are committed; they do not complete #273 or optional archive acceptance.

Reproduced two PRA failures before correction: fiscal-number-bearing stale local rows could be finalized locally; and a callback writing a fiscal number between the initial read and locked delete was not rechecked before deletion/quota side effects. Also reproduced FBR provisional deletion of a stale local row already carrying a fiscal number. These are synthetic fixtures, not proof of a specific customer incident.

PRA delete now trusts/rechecks the locked row before stock/quota changes; provisional deletion locks/revalidates and loads items under that lock. Local Final uses a transaction/row lock and checks fiscal identity, return status and current local eligibility; repeat finalization preserves its original archive timestamp. Core promote also rejects existing fiscal identity/returns. PRA day-close selection is ordered and row-locked. FBR provisional delete, atomic promote and day-close actions require absent fiscal number. Existing reporting-OFF NULL semantics, company settings, PIN/role/tenant/isolation, quotas and stock behavior remain unchanged.

Local PHP8.4 decision/stock/day-close group: **121 tests /726 assertions PASS**. Includes reproduced regressions plus normal quota/delete/finalize paths, cashier isolation, stock restoration, mixed deal components, per-bill day-close and bulk close behavior. Focused whitespace check passes. Native concurrent acceptance and own latest full eight CI must be refreshed; SQLite alone is not row-lock concurrency evidence.

Remaining #273 work still includes optional owner consent, encrypted/minimized deletion snapshots, retention/revocation/purge, access auditing and backup/restore acceptance. Do not mark bulk complete or remove draft based on the parent greens.
