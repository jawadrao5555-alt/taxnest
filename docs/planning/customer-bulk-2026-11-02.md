# Customer issues: consolidated implementation queue

Owner direction, 2 November 2026 (Pakistan): one bulk PR for the collected issues below; a separate parked PR for Udhaar Book. This register is not implementation or release acceptance. Preserve existing source PRs/history; do not merge overlapping trees or close unrelated work.

## Duplicate review

Reviewed all 25 currently open PRs and the 100 most recently updated closed PRs. Current main: `0aecc18e18fb52a1c7d4499181f27362ae8b7411`.

| Area | Existing source | Treatment |
|---|---|---|
| Nine KOT/receipt designs | #280/#281 contracts, #304 planning; **#324 merged implementation** | Reuse actual renderers and independent Local/Fiscal selections; do not reimplement nine styles. Earlier contract-only status is superseded by #324. |
| Receipt controls / reprint | #303 and #311 merged | Reproduce remaining failure against main, preserve saved controls. |
| Day close / financial history | #317 merged, release follow-ups #322/#323 merged | Preserve frozen snapshots, existing numbering and cash invariants; implement only verified gaps. |
| Notifications / support copies | #219/#312/#322 merged; #279/#273 retained sources | Inspect current targeting and consent before changing. No duplicate notification system. |
| Agent launch / updates / downloads | #305 merged; #234/#250/#251/#256 retained sources; #274 download foundation | Reuse existing release and recovery mechanisms. Source PRs are not separate release candidates. |
| Rider GPS | #217 merged; #224 mobile builds | Verify installed/device behavior; do not infer completion from server tests. |
| Diagnostics | #315 open; #327 open, #319 parent planning | Independent work, not imported into this bulk. #327 latest head passes 8/8; #315 needs current-main reconciliation. |
| Other programmes | #301 master, #313/#314/#316/#318/#320/#321, #261/#264/#266/#278 | Retain independent scope. This bulk does not claim to complete the nine-workstream programme. |
| Explicit hold / separate pilot | #237 HOLD, #228 CCI evidence | Untouched; not release candidates for this request. |

## Acceptance register (unchecked means unfinished)

- [ ] B01 Local cleanup/series: successful day close with effective delete policy resets next local series to L001; retained data continues series. Respect branch, concurrency, retries and per-bill overrides. Central SaaS copy survives local cleanup, subject to SaaS-admin retention/deletion authority. Reconcile existing consent/archive behavior without silently resetting tenant preferences. Do not claim old deleted data can be reconstructed.
- [ ] B02 Reprint opens preview only; explicit Print creates print intent. Preserve normal new-sale printing preferences and duplicate guards.
- [ ] B03 Shared Sales/Day Close screen and print information hierarchy: summary/analytics first; products below in contiguous category groups; centered quantity. Sales retains PRA/Local tabs. Day Close includes separately labelled PRA and Local scopes within authorized access. PDF footer text only on last page, numbering on every page. Delivery charges move out of Uncategorized/product sales into order-type breakdown and are included once in both payment/order reconciliations; never blindly add to existing inclusive totals. Explain real discrepancies; do not manufacture adjustments. Retained snapshots remain authoritative after cleanup.
- [ ] B04 Day Close exposes only two report choices: Day Close Report — PDF and Day Close Report — Thermal. Open/closed is report status, not six X/Z/summary choices. Viewing/exporting never closes the day. Close Day remains a separate guarded action. Preserve historical links and authorization.
- [ ] B05 Thermal report: continuous content-height output with no mid-report cuts, clear PRA/Local headings and one final cut. Larger legible solid-black text on white, bold headings/totals, clear columns/no clipping. Trace browser pagination, generated paper dimensions, Agent job and driver cuts. Long physical roll output is a separate acceptance requirement; CSS alone does not prove it.
- [ ] B06 Dashboard PRA/Local cards open matching Sales tab for same company/business date. Revenue, Orders, New Customers and Tables cards move above those cards, with scoped clickable detail. Occupancy is live; date-based metrics retain correct business-day meaning.
- [ ] B07 Held/table/delivery recall: latest cash/card selection drives proof/provisional method, applicable tax and total before finalization, in both directions. No stale stored preview, duplicate KOT, unintended fiscal submission or rewritten finalized bill.
- [ ] B08 Successful finalization/accepted print handoff closes the payment/table dialog. Failure remains visible/recoverable; cancelled/ambiguous print must not create another sale. Physical paper and spool acceptance remain distinct.
- [ ] B09 Internal SaaS survey gateway: admin question/options/rating/text, company/product/user/role targeting, configurable period (default three days), daily display while unanswered, no repeat after answer, expiry stop, explicit per-company/per-user response policy, counts/percentages/top answers/pending and filtered export. Deduplication, tenant access, edit/publish behavior and results permissions tested.
- [ ] B10 Product-scoped notices: admin-only changes produce no customer notice; PRA/FBR/DI updates target only relevant companies, multi-product recipients deduplicated. Bell/popup/unread/What's New consistent; preserve read history. Reuse existing release targeting rather than title heuristics.
- [ ] B11 Public product names consistently begin TaxNest (TaxNest PRA POS, TaxNest FBR POS, TaxNest Digital Invoice, TaxNest ERP/Agent). Preserve internal IDs, routes, app IDs/signatures, tenant brand names and historical legal documents.
- [ ] B12 App/Agent successful releases update website download and auto-update to the same verified version. Failed upload retains last working version, rollback remains consistent; immutable assets/signatures/hash and rollout gates preserved.
- [ ] B13 Agent Open POS and reported New Sale/Refresh/Copy actions: identify exact installed buttons and trace configured origin, route, session, error and fallback. Test actual handler and installed Windows behavior before claiming root cause fixed.
- [ ] B14 Premium device-friendly app presentation, with browser feature parity: mobile layout/touch/keyboard/safe areas, desktop responsiveness, working navigation and offline/update behavior. No silent changes to saved settings.
- [ ] B15 Rider/location tracking: permissions, duty/assignment authorization, background/lock/battery/offline/reconnect, fresh/stale timestamps and tenant isolation. Actual supported device evidence required.
- [ ] B16 Receipt settings simplified: basic company-facing header/logo/contact, relevant invoice/customer/table/rider fields, footer, design preview and supported paper/font choices; technical printer/Agent details behind an advanced/support area. Essential fiscal/financial truth stays automatic. Existing nine designs from #324, explicit Save and legacy fallback preserved.

## Separate Udhaar Book boundary

Do not implement customer credit in this bulk. A parked independent PR owns full received OR full credit (no partial), rider-to-company receivable transfer, admin/owner-only later collection, rider attribution and amount-only history after permitted local bill deletion. Bulk cleanup must not invalidate that future ledger contract.

## Verification rules

Before each change: failing flow, existing working configuration, second tenant, old/no-new-setting behavior, applicable one/many printer/device topology. Reproduce locally and retest the original path; run focused tests and real desktop/mobile browser checks. Full CI on final head is required. Keep physical printer/Windows/mobile gaps explicit. No customer media, identifiers, private amounts, credentials or real invoices in this public repository.

No release-ready claim until the implementation register and material acceptance evidence are complete. No merge/deploy is authorized by this implementation request.

## Initial implementation checkpoint

The main Day Close page contained seven report links in each open/closed state (thermal, PDF preview, PDF download, summary screen, summary thermal, summary PDF preview/download). Replaced that selection with a shared two-link component: PDF and Thermal. Existing GET endpoints, selected date/frozen report ID, close action, branch checks and isolated-cashier restriction remain unchanged. PDF opens the existing viewer, which provides print/download. Historical summary endpoints remain compatible; their consolidation and full-page browser acceptance remain pending.

Local PHP 8.4.17 validation: report-action render tests plus stream split, archived history, PDF disposition and cleanup-warning regressions: 23 tests / 198 assertions passed. Language purity: 7 tests / 22 assertions passed. Whitespace check passed. These are not end-to-end browser or physical printer acceptance. No full-suite pass is claimed. All other register items remain unchecked, including thermal styling/cut behavior and the complete report redesign. The separate parked Udhaar contract is PR #328.

## Second implementation checkpoint

- Reprint list rows and list Enter now open the existing preview. Printing requires the preview Print action; recent-bill preview and filters remain intact.
- Reproduced proof-bill stale pricing through the actual HTTP route: requested Card still rendered the stored Cash total. A display-only copy now uses the existing settlement calculator for each explicit method; original order and financial rows remain unchanged. Both table and recalled-panel actions offer Cash/Card proof; silent queue retains the selected method in existing render_query, and deduplication no longer reuses a different method's job. Legacy no-method requests preserve old totals.
- Receipt success dialog closes after queue acceptance or browser afterprint, once all tracked receipt/KOT work drains. Timeout is not print acceptance; stale callbacks cannot close a newer sale. Browser afterprint means dialog dismissed, not proof of physical paper or distinction between Print/Cancel.
- Day Close thermal presentation has 13px solid-black body text, clearer headings, untruncated product/category names and consistent 72mm printable width. Explicit content-height @page replaces invalid width/auto page sizing at print time. This removes browser pagination in the new test contract; hardware driver/cutter acceptance remains unverified.

Local evidence: 100 related PHP tests / 1,116 assertions pass (28 existing deprecations); additional silent-proof queue test 1 / 13 pass. Five JS behavior tests pass. PHP syntax and whitespace pass. New long-roll desktop/mobile Chrome PDF page-count test is wired into the existing mandatory browser lane. Local Chrome installation failed with truncated archives, so no local Chrome pass is claimed. Fresh CI for this checkpoint is required. B01/B03/B06/B09–B16 and unsaved-cart/provisional variants remain unfinished; this is not whole-bulk release readiness.

## Testing and navigation checkpoint

PRA/Local ledger cards now link to their matching Sales report tab with the displayed business date and current cashier filter, across both dashboard wrappers and all seven styles. Existing stream visibility and destination authorization remain authoritative. The four other metric cards still need relocation and matching detail destinations.

36 focused dashboard/isolation/report-action PHP tests pass (210 assertions), including links for both-stream owners and single-stream cashiers. Reprint instruction text now says Preview. Browser acceptance is extended to click the actual Reprint row and press Enter, in both silent/browser modes and desktop/mobile widths; the existing recent-bill path remains covered. It loads the saved receipt iframe and checks that only the explicit Print action reaches the printer boundary. This new acceptance must pass on the latest commit before being claimed verified.

Readiness blockers confirmed during inspection: the existing local number allocator deliberately remains monotonic after deletion, while B01 requests a daily visible reset; the optional support archive currently requires a company-owner grant and does not satisfy the requested always-retained SaaS copy. These are unresolved behavior differences, not passing-test evidence for B01. The survey model is currently fixed-choice only, without the requested publication window/targeting contract. Do not mark these or the remaining report/application work complete because unrelated CI gates are green.

Additional regression evidence: 187 existing-contract tests / 963 assertions passed across archive/numbering, survey, notification, release, rider-location and receipt preferences. Full KOT quantity/carry suite: 42 tests / 245 assertions passed, including consolidated proof content and its no-store response. Reprint preview now owns global sale shortcuts even when opened from the recent-bill strip; focus moves into the dialog, while focused preview buttons retain native keyboard activation. Six JS behavior tests pass, and browser acceptance also activates the explicit Print button by keyboard. Final-head CI remains required; no physical-print or whole-bulk completion claim follows from these checks.

## Daily cleanup, SaaS retention and settings checkpoint

The 5404b47 CI browser lane passed actual desktop/mobile reprint flows and the long-roll one-page PDF checks; native MariaDB and release/build guards also passed. Full PHPUnit found two stale expectations (Urdu Preview wording and the old single proof button), while the Node recent-bill harness lacked Alpine's nextTick/focus dependencies. Corrected the text and updated those harnesses without removing behavioral checks; 10 focused PHP tests and 10 JS behavior tests pass.

Day-close cleanup now restarts an **empty company L-series** at L001 under effective delete behavior. Retained/saved/guarded rows, including another branch's rows, still reserve their references and keep the series moving. Manual Clear remains explicit; retries of an already-closed day cannot reset newly issued numbers. Stable transaction IDs and frozen accounting/quota records are unchanged.

A separate encrypted SaaS cleanup record now keeps local bill business facts, line items and payments before deletion, independently of the existing optional owner-managed support copy. Capture and cleanup share one transaction; a capture/integrity failure prevents deletion. SaaS super admins alone can export/delete a company-scoped copy or stop future retention. All those administrative mutations/exports are audited. Existing saved preferences, optional-copy grants and old historical rows are not migrated or rewritten. Old deleted details cannot be reconstructed. Missing new tables retain old rolling-deploy behavior, so deployment must complete migrations before the retention guarantee applies. Financial summaries and independent backups are distinct from deleting a SaaS copy.

Receipt settings now show basic branding/contact/footer/paper/font controls and the existing nine receipt/KOT design selectors/previews. Technical field visibility and alignment live in closed Advanced sections; printer setup guidance is separately collapsed. Inputs remain enabled so saving basics cannot erase hidden saved choices. Browser acceptance now explicitly checks this preservation case. Product display/catalogue/PWA/app labels use TaxNest names; IDs, scopes, routes, Android identifiers and installer identity stay unchanged.

Order-type aggregates are now retained inside the existing accounting snapshot JSON for future cleaned bills; old snapshots remain explicitly Unknown rather than being misclassified. This is a report-data foundation, not completion of the shared report layout.

Local PHP evidence: 86 focused archive/numbering/day-close/language/settings tests (460 assertions), followed by the updated real day-close + SaaS capture integration (9 tests / 59 assertions). Another 30 financial-history/design/preferences tests passed (228 assertions); updated language/design/preferences checks 31 / 206 passed. JS behavior 10/10, syntax/whitespace pass. A broad substring filter also selected 10 unrelated FBR tests that hit this local static PHP build's missing PDO sqliteCreateFunction API; explicit PRA paths passed. Full CI and native MariaDB must be rerun on this checkpoint. Whole-bulk readiness is still blocked by the unchecked report/dashboard/survey/release/app/device requirements.
