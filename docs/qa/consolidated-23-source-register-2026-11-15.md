# TaxNest 23-source reconciliation inventory

## Implemented intake correction and visual follow-up (local 9639ed5)

This update supersedes the **missing code** issue-intake row at checkpoint
6101cf0 below, without changing the original source requirements.

| Requirement | Current implementation | Evidence and remaining acceptance |
|---|---|---|
| #301/#321 explicit escalation retry | 7d7c6c3 adds company/user/session/product request UUID plus content fingerprint; current-user lock serializes cap and atomically saves suggestion, confirmation and original response. Same ID replays original ref; changed details return409. Client restores a pending card after refresh but never auto-confirms. Retained identities survive rollback; authorized tenant purge includes them | Integrated escalation/usage/shared-component tests34/209PASS. Native simultaneous same-ID/cap race requires its explicit success marker. Both-viewport browser response-loss/refresh/retry/conflict/history tests added in1402642. Hosted execution remains pending. No real support message or fuzzy cross-company dedup was performed. |
| #321 visible AI observation UI | f6fb723 replaces admin CDN-only styling/runtime with locked same-origin Vite assets. Actual browser checks require visible unobstructed heading/grid and usable controls after real survey dismissal; exact scrolled-control captures added | Admin/usage regressions45/302PASS; final screenshot and browser proof pending. Earlier AI images were not accepted merely because hidden body text matched. |
| Aggregate memory isolation | Explicit cycle collection and measured allocator-cache release preserve every test and the1GiB limit | Prior exact87e local run executed6435tests/54659assertions with only the subsequently fixed translation failure. Short53-PDF-test allocator experiment passed265assertions and reduced peak219MB→201MB; it does not prove a full final-head pass. Fresh aggregate result required. |

Actual exact87e receipt/closing detail images were inspected at both widths;
amounts, balances, campus/cashier and Print/Reprint were legible, with collection
buttons and support overlap corrected. That does not certify later-head pixels,
AI usage pages, ERP role screens, physical printing or regulator acceptance.
All external/future holds and original47unchecked source lines below remain.


## Independent remaining-scope checkpoint: local 6101cf0

Inspected current implementation, not just earlier plans. The previous gaps
in profile validation, backup metadata and AI telemetry now have code; the
historical tables below must not be quoted as today's missing-code list.

| Source | Current implemented slice | Actual remaining acceptance or action |
|---|---|---|
| #301/#321 profiles | Generic personal optional DOB/phone validation, private serialization, product-scoped uniqueness, current-membership locking; business CNIC/phone formatting; real profile form and browser fixture | Verify final-head rendered form and aggregate results. Other product-specific forms retain separate rules. Format validation is not identity verification; no greeting enrollment inferred. |
| #321 AI observations | `MadadgarUsage`/policy services and owner/admin UI record scoped provider reservations, observed latency, known tokens/cost, error and escalation metadata; explicitly configured rates/caps, unknowns preserved, locked allowance before HTTP and uncertain spend retained. Forward-only migration preserves caps/history | Native race and exact-head browser/admin styling acceptance must finish. No real provider billing reconciliation or aggregated OCR/other-AI cost claim. Existing provider configuration unchanged. |
| #319 backup sources | `TenantBackupEvidence` authenticates default-OFF source enrollment and explicit tenant grants, bounded metadata only, immutable replay/conflict handling, scoped retained-source reporting and truthful PARTIAL/empty states | Actual authorized producer enrollment, tenant manifest and elapsed collection remain external/operator work. Metadata receipt does not prove backup bytes, restoration, RPO/RTO or seven-day all-source completeness. |
| #377 isolated Hospital ERP | Expanded nine-role synthetic fixture plus `check-erp-demo-acceptance.sh`, role/module/tenant checks and native/browser journey | Worker reports274tests/1675assertions locally for the selected Health suites. Dedicated final native/browser execution and per-scenario evidence still required; not full HMIS/production certification. |
| #301/#321 issue intake retry | **Remaining concrete code gap at this checkpoint:** `MadadgarController::escalate` validates session/title/summary/kind then independently creates `FeatureSuggestion` and confirmation message, with no stable request identity or transaction; count/check is not serialized | Implement same-company/user/session request identity, content fingerprint, atomic suggestion+confirmation and same-ID same-ref replay; changed payload conflict; race and lost-response tests. Preserve legacy callers and explicit confirmation. This is not permission for fuzzy cross-customer merging or automatic external issue creation. |

The issue-intake gap is a genuine actionable correction, not an external hold.
Parent implementation worker has been notified; this register does not claim it
fixed. Bounded assistant commands still intentionally exclude unsupported DI,
cashier and arbitrary-record queries; the full original scoped-assistant and
issue-to-PR programme is not certified by these slices. Existing support-case
linking/reopen features are distinct from retry-safe assistant escalation.

Latest reported evidence must remain separated: remote87e tree7358d69 native
PASS is not a PASS for later local6101cf0; local aggregate6435tests/54659assertions
had one translated-link failure subsequently corrected, while fresh hosted full
and browser results were still pending. Earlier readable receipt detail images
do not prove every UI: AI admin styling/overlay fixes and final visual review
remain in progress. Exact current commit/run links are supplied by the release
coordinator only after verification. No all23 completion claim is justified.

Focused ports389/375/381, landing376, configured cycles374, reviewed fiscal373,
Udhaar328, optional cashier237, CCI evidence228 and School/Hospital workflows
remain implemented slices requiring final integrated acceptance. Source228
actual workbook/batch approval, physical printer/signed installation, fiscal
route/sandbox/central evidence, real backup/field soak and owner price activation
are external holds; full School ERP/future-industry314/378 remain future scope.
Original47unchecked requirements below are preserved, not silently checked off.

## Updated implementation checkpoint: local 9222f62

This supersedes the earlier f274ef1 checkpoint only for the rows below. Source
and test inspection performed; no fresh hosted PASS is inferred. Remote
`6127` run [43699005351](https://github.com/jawadrao5555-alt/taxnest/actions/runs/43699005351)
failed the preservation comparator before reaching later native race proofs.
The listed fixes require publication and a new exact-head run. Full SHA and
final checks must be refreshed from GitHub, not copied from an earlier tree.

| Requirement | Newly inspected implementation | Focused evidence | Remaining proof / scope |
|---|---|---|---|
| #301/#321 authorized company read answers | `MadadgarCompanyReadTools` (8efc69e/418a341) supplies fixed-command, owner-only, same-guard/company/campus billing/payment/tax/stock/recipe/closing adapters. Persisted active owner checked under lock; output numeric allow-list, source/date/timezone/result hash and atomic audit. Missing schema/audit fails closed; no provider forwarding of prior private history | Implementation worker reports combined21tests/158assertionsPASS; inspected tests and source include foreign campus, role change, missing-source and tax precision/provenance cases | **Bounded read gap now implemented**, unlike previous no-live-tool snapshot. DI/other guards, cashier data reads, arbitrary questions/identifiers/date ranges and full issue-to-PR programme remain unsupported. Exact-head hosted acceptance pending. |
| Actual School/Hospital support UI | 2bea394 adds existing support component and CSRF metadata inside no-print wrappers. `madadgar-read-browser.mjs` exercises real UI with one authorized123.45 receipt, foreign-campus999.99 canary and cashier denial at desktop/390px | Browser journey and safe screenshots are implemented, not an observed hosted result | Hosted rendering, privacy/source/audit assertions and mobile acceptance pending; no live company/provider reads used. School/Hospital fiscal OFF guard remains. |
| #266 DI stock safety | 7a48b3b makes generic DI receiving/deduction/reversal and invoice posting share Product-row locking before stock lookup/creation; exact four-decimal writes preserve large balances, unassigned branch concurrency and frozen quantity | Worker reports15tests/107assertionsPASS; independent receive-versus-first-post native race added | Final hosted concurrency, recovery, original return reservations and UI proof still required. Fatal transaction loss is rethrown, not reported as accepted-stock success; fiscal acknowledgement reconciliation remains distinct. No historical backfill or real regulator acceptance. |
| Upgrade value preservation | bfac73f compares widened decimal values exactly without floating point; 0797f74 records downloaded synthetic before/after evidence and adds information_schema capture | Prior mismatch was17.00→17.0000 and29.00→29.0000 for stock/movement/balance, with only previously allowed package backfill differences. Original numeric values were preserved in that artifact | Prior artifact did not record physical column types. Exact DECIMAL(15,2)→DECIMAL(18,4) assertions and new native run pending. Canonical scale comparison does not permit changed numeric amounts, omitted rows or relaxed financial invariants. |
| #373 reviewed callback state width | 9222f62 adds a forward migration widening reviewed evidence state to40 characters, preserves historical strings and adds full-schema/native width assertions | Schema/test correction inspected; strict mode retained | Native callback race confirmation pending. Prior preservation abort cannot count as a callback pass. No truncated state aliases or bypass of reviewed proof/environment guards. |

Source evidence: `docs/qa/madadgar-bounded-company-reads-393.md`,
`docs/qa/di-invoice-inventory.md`, `docs/qa/native-upgrade-decimal-evidence.md`.
These are implementation increments, not acceptance of all23sourcePRs or the
whole47itemmaster. Seven-day field collection, full programmes and previously
listed external/future holds remain explicit. Original unchecked source text
is unchanged. No production actions or fiscal submission occurred here.

## Updated implementation checkpoint: local f274ef1

This section supersedes the **initial inventory** below where it said runtime
was absent. Code inspected at `f274ef1` includes the following added slices.
It does not mark a source programme complete. Remote `2c2f860`/run
[43697615574](https://github.com/jawadrao5555-alt/taxnest/actions/runs/43697615574)
was reported against an earlier tree: native/browser failures and full-suite
work were still being investigated. It is not acceptance for local f274ef1.
Always refresh the exact final head and results before review readiness.

| Original requirement | Implemented authority inspected | Focused evidence available | Still pending / limits |
|---|---|---|---|
| #321 / original #301 workstream5: owner-approved welcome, birthday, anniversary, local date, retries/dedup | `CompanyGreetingService`, `GreetingPreferencesController`, `SendOptInGreetings`, greeting tables and console schedule. Global transport and company preference default OFF; explicit event email consent, exact local occurrence, bounded provider-unavailable retry, durable sending/HOLD after uncertain outcome, dispatch-time consent recheck; stale owner membership rechecked | `OptInGreetingsTest` covers default no-send, local/leap dates, consent revocation, stale owner, once-only occurrence, uncertain outcome HOLD. Native race and desktop/390 browser fixtures are added in 2cbe3f5 | Implemented email-only slice, not activated. Hosted race/browser/final suite proof pending. No real delivery, automatic contact/patient import or SMS/WhatsApp claim. Existing historical programme remains unchecked until its full agreed outcome is accepted. |
| #321: accessible, scoped tutorial discovery | `TutorialController`, `TutorialVideoAdminController`, scoped search, title/language/local-VTT/transcript/reviewed-version metadata and superseded-row handling | `TutorialAccessibilityMetadataTest`; synthetic keyboard/caption browser fixture in `opt-in-greetings-tutorials` | No invented caption content; actual supplied captions and six-theme/screen-reader acceptance not established. Existing publication/audience rules retained. |
| #321 / #301 workstream6: scoped assistant, sources/date, no escalation by content | `MadadgarAnswerScope`, service/controller persisted actor checks, source provenance columns/UI, category-specific local guidance and untrusted-instruction/current-evidence rejection | `MadadgarScopedAssistantTest` includes tenant/role/inactive denial, injection, unknown tool no-write, quota, historical provenance and localized escaped notices | **Authorized read-data requirement still open at this checkpoint.** The inspected scope helper explicitly answers that it has no live records tool. Deterministic refusal/local help is not completion of billing/payment/stock question answering; separate worker is implementing the allowed read adapter. No provider or production-data proof claimed. |
| #316/#337/#374: preserve historical rates, explicit configured periods, consistent review | `AdminPlanController` only prepares POS/FBR/ERP period rates when the administrator explicitly chooses Update configured period rates; absent/old-client controls preserve rates. Availability remains a separate deployment flag. Existing configured DI periods stay compatible | `AdminPlanStorePostTest`, `FbrPosTwoPackageLadderMigrationTest`; shared billing evidence reports focused approval matrix 23 tests/278 assertions and lifecycle matrix62/1289, not new hosted acceptance | Final exact-head checkout/browser/native approval/commission results required. Configuration is not activation; missing/unpriced/retired periods cannot silently become annual. No new commercial prices invented. |
| #316/#320: Healthcare billing authority | `HealthAccessService` maps package checkout/proof routes to non-delegatable owner `billing.manage`; guard-specific route and company checks remain | `HealthPilotReadinessTest` plus existing multi-guard checkout cases | Capability mapping is an implemented boundary, not proof all lifecycle/grant/suspension/accounting requirements are complete. |
| #266/#313: inventory outcome after frozen packing | `DiInvoiceInventory`, additive posting/precision schema, DI web/API snapshots, accepted finalization helper and local-only recovery; base adapter85ea7f0 and browser fixturef274ef1 | `DiInvoiceInventoryTest`, existing packing/integrity tests; socket-only native probe and synthetic desktop/390 `di-inventory-projection` journey added | **Implementation and safety follow-ups active, not complete.** Finalized environment, original return reservation/concurrency, unknown cost, tenant/branch, legacy no-backfill, hash and no-redispatch recovery need final integrated tests. Native/browser proof pending; no actual fiscal acceptance. Never count a test mock as regulator evidence. |

Focus-test counts above are recorded implementation evidence, not a fresh
rerun by this inventory pass. Test code presence is separated from execution.
The 47 original unchecked lines remain unchanged. Seven-day installed-device
diagnostic completeness, actual backup/restore/RPO/RTO, physical paper/signed
installation, verified regulator routes/sandbox, CCI actual evidence/batch
approval, full School ERP and future industry programmes remain separate open
requirements or external holds. Do not translate additional local commits into
“all 23 complete.” School/Hospital fiscal submission remains blocked.

## Register status and evidence rules

This is a checkpoint register, not a declaration that all source requirements
are delivered. Runtime work advanced after this initial inventory. Match final
HEAD tests before upgrading any status. Keep source PRs open until a replacement
is reviewed and merged. Original 47 unchecked requirements are preserved
verbatim in `source-301-original-unchecked-2026-11-15.md`.

Discussion API checked for #301, #313, #318, #266, #264, #319, #320, #321 and
#316: all returned empty timelines. Full source master was fetched at
`cc20a767426717f792a02248c9bd230a9ba09ad9`; it contains 85 audit records,
57 named planned scenarios and 47 original unchecked requirements. Counts are
source inventory, not counts of current defects or passed acceptance.

### Deeper requirement → authority → remaining → test map

| Source requirement | Current authority / evidence | Remaining action and status |
|---|---|---|
| #313/#266 packing conversion, original snapshot, return limits | `DiSellingPacking`, `DiApiRequestIdentity`; `DiSellingPackingTest` covers API quantity, original retry after catalogue change, incompatible/foreign product, inclusive tax, frozen returns/excess, proof, precision, barcode and header edit | Implemented slice; run final-head tests. PDF/stock/end-to-end original whole outcome cannot be inferred from these cases alone. |
| #266 inventory outcome | Generic `InventoryStock`, `InventoryMovement`, `InventoryService` and `/inventory` exist; initial migration is `2026_02_17_150000_create_inventory_module.php`. No invoice-posting reference found in inspected DI invoice controllers/services; #367 release notes explicitly call frozen quantity an inventory-consumer contract | Concrete gap: DI invoice-to-stock posting integration. Reuse company-scoped existing inventory only after checking finalized boundary, opt-in, exact quantities, negative-stock policy, branch scope, unique posting identity and original-deduction-only returns. No silent backfill or new PRA/Healthcare posting. |
| #264/#313 cycle prices, dates, immutable quotes | `DiBillingCyclesTest`: configured exact prices/calendar months, unavailable period refusal, frozen proof terms/retry, current-plan renewal selection; #367 docs | Existing DI slice. #374 supersedes new monthly sales but preserves frozen monthly terms. Shared-cycle worker changes need final-head customer/admin/browser/native proof; no invented currency, tax, discount or commercial rules. |
| #313 integrity and buyer/reportability | `DiIntegrityCoverageTest`, packing proof tests; v4/current and legacy proof paths in #367 evidence | Re-run mutation, legacy and source-byte preservation tests; statutory scenario correctness and external acceptance remain separate. |
| #318 fiscal acknowledgement and pharmacy | #367 medicine-source ordering and existing pharmacy/return tests; new #373 work binds environment/device claim and proof | Execute final frozen-environment, stale/foreign/duplicate callback and stock/refund tests. Physical local/central acceptance remains blocked on authorized external evidence. |
| #319 seven-day diagnostic coverage | `DiagnosticSourceEvidence` explicitly reports `complete=false`, `seven_day_complete=false`, sequence gaps/clock skew unavailable except related PRA evidence; scoped registry/collection/read/export tests exist | Real durable all-source/device collection is not proven by seven calendar-date derived observations. Add missing adapters/collection acceptance without relabelling absent evidence healthy. Installed-device soak and missing events remain evidence gaps. |
| #320 security/recovery | `docs/qa/security-onboarding-bulk-2026-11-07.md` maps reset, two-step, permissions, impersonation, revocation, deletion and synthetic restore quarantine to tests | Reuse shipped implementation. Production backup freshness, actual backup recovery, physical erasure and tenant restore RPO/RTO not demonstrated by access quarantine. Do not claim complete recovery. |
| #321/#301 profile greetings | Search of application jobs/notifications/console/services found no welcome/birthday/anniversary scheduler or deduplicated delivery implementation; matches were unrelated category examples/health fields | Concrete remaining programme: scoped preference/template/local-date delivery ledger and mocked retry/dedup tests. Never send real greetings as an implementation test. |
| #321/#301 scoped assistant | Existing integrations are retained; no prompt-injection acceptance found by searched test/application identifiers | Inventory actual permitted tool surface and add source/date/tenant/role/unsupported-answer/injection tests. Search absence is not proof all assistant behavior is absent. |
| #316 complete lifecycle | Existing #363 snapshot/documents/access/renewal/add-on and adjustment tests; `AgentCommissionDecisionAtomicityTest` and commission services exist | Independently reconcile approval, subscription, suspension, temporary/lifetime grants, payment and effective decision. Test combined suspended/expired/grant case; platform revenue separate from tenant sales; do not call signup validation full lifecycle. New worker commits need exact-head proof. |

At the initial inventory: 3 new ports (#389/#375/#381), 11 partially reused
programmes (#338/#337/#321/#320/#319/#318/#316/#313/#301/#266/#264), 7 identified
assigned gaps (#377/#376/#374/#373/#328/#237/#228), 2 future/external contracts
(#378/#314). Zero entire source programmes certified complete. These categories
must be refreshed from subsequent commits; they are not final delivery counts.

Inspected local main 70f002cc4d32d0fb71b42256230d41cba8dd4e6a and #393 local76294851b25de37b90d9a431ee411750f8d4e89c / remote2b8083e2c1744449dda7691ff92e3be16ff55151 on15Nov2026. All23sources are open/unmerged; status does NOT establish runtime absence. Read full PR bodies/diffs; full original review/comment timelines not yet inspected, so mapping remains partial, not completion certification. Source snapshots in companion JSON preserve exact bodies/diffs.

| Source | Current mapping and remaining evidence |
|---|---|
| [#389](https://github.com/jawadrao5555-alt/taxnest/pull/389) | Runtime gap identified and ported: owner dispatch excludes only pra-agent/test; runtime/lockfile/workflow still trigger. Local actual-predicate tests pass; hosted pending. |
| [#381](https://github.com/jawadrao5555-alt/taxnest/pull/381) | Runtime gap identified and ported: private push hint, reconnect refresh, coalescing, account-token guard. Structural tests only; Android hosted compile and signed-phone push acceptance pending. |
| [#378](https://github.com/jawadrao5555-alt/taxnest/pull/378) | Planning integration contract; future ERP fiscal route remains unimplemented/unverified. Preserve School/Hospital fiscal block; no route invention. |
| [#377](https://github.com/jawadrao5555-alt/taxnest/pull/377) | Isolated demo fixture absent on inspected tree; source contains guarded empty loopback MariaDB seeder+SQLite smoke. Needs port and isolated acceptance; not productionHospital proof. |
| [#376](https://github.com/jawadrao5555-alt/taxnest/pull/376) | Source hero/tablet fix not present at inventory: md navigation breakpoint and overlapping card. Port carefully; 390/768/1024/1366 browser proof pending. |
| [#375](https://github.com/jawadrao5555-alt/taxnest/pull/375) | Runtime gap identified and ported: gated FBR APK, independent Plus APK, no disable-Play-Protect advice. Structural tests pass; rendered page and signed install/asset proof pending. |
| [#374](https://github.com/jawadrao5555-alt/taxnest/pull/374) | Shared billing period spec only. Explicit quarterly/semiannual/annual configured prices, missing price hide+reject, no silent annual fallback; frozen monthly quotes retain terms. Existing DI cycles not proof PRA/FBR/ERP complete. |
| [#373](https://github.com/jawadrao5555-alt/taxnest/pull/373) | Standalone acknowledgement parity spec only: exact number-only shape+claim/POSID/environment/transport proof, immutable requested environment, ambiguous holds no redispatch. Device/central proof pending. |
| [#338](https://github.com/jawadrao5555-alt/taxnest/pull/338) | Planning umbrella; #367 merged slices present but broader #313/#318 not all complete. Preserve HOLD228. |
| [#337](https://github.com/jawadrao5555-alt/taxnest/pull/337) | Planning umbrella; #363-365 evidence files/runtime present. Broad billing, diagnostics, security/onboarding checklist still open; don't repeat implementations. |
| [#328](https://github.com/jawadrao5555-alt/taxnest/pull/328) | Source specification only; new owner now includes implementation. Existing PosDeliveryAccount needs reconcile: fullcredit/fullreceived no partial, rider liability→company receivable, admincollection, cleanup retention and idempotency. |
| [#321](https://github.com/jawadrao5555-alt/taxnest/pull/321) | #365 shipped setup/recovery/support and notification foundations. Remaining greetings/assistant programme, universal tutorials/accessibility/themes coverage not proven. School/Hospital #393 adds category-specific setup/shared controls. |
| [#320](https://github.com/jawadrao5555-alt/taxnest/pull/320) | #365 shipped reset/2step/revocation/impersonation/deletion/quarantine fixture. Production backup freshness/RPO/RTO/physical erase and true restore unproven; broad privacy/retention not automatically done. |
| [#319](https://github.com/jawadrao5555-alt/taxnest/pull/319) | #364 source registry, scoped stored reads, seven-calendar-day derived observations, bill associations, incomplete evidence states exist. Real device 7day soak, deleted/uncollected events/physicalprint/backup impossible to infer. School/Hospital adapters being added separately. |
| [#318](https://github.com/jawadrao5555-alt/taxnest/pull/318) | #367 fiscal/integrity/returns/pharmacy slices require code/test map; standalone373 remains identified gap. Physical fiscal acceptance still external. Current source includes retained planning file. |
| [#316](https://github.com/jawadrao5555-alt/taxnest/pull/316) | #363 existing purchase snapshots/entitlements/atomic reviewed documents/renewal/addon/adjustment proofs present. No proof all general lifecycle/grant/platform-accounting/commission policy covered;374 shared cycles separate gap. |
| [#314](https://github.com/jawadrao5555-alt/taxnest/pull/314) | Full School ERP/future industries remain future; shared-platform/pilot integration only now. Preserve all Q1-Q6/E1-E9 original requirements. |
| [#313](https://github.com/jawadrao5555-alt/taxnest/pull/313) | #367 merged DiSellingPacking and DI short-cycle/runtime work present; source specifications and remaining integrity/import/pharma acceptance require exact mapping. |
| [#301](https://github.com/jawadrao5555-alt/taxnest/pull/301) | Master contains85audit/57acceptance/47original unchecked; cannot close by23PR metadata. Preserve original checklist and carry item-level mapping. |
| [#266](https://github.com/jawadrao5555-alt/taxnest/pull/266) | Original optional Smart UOM spec retained; DiSellingPacking runtime exists. Source PR unmerged does not mean feature absent. Original conversion/return/import snapshot tests need reconcile. |
| [#264](https://github.com/jawadrao5555-alt/taxnest/pull/264) | Original DI shortcycle spec retained; #367 DI cycles exist. Need exact source acceptance map including pricing/renewal/isolation/documents. |
| [#237](https://github.com/jawadrao5555-alt/taxnest/pull/237) | Old mandatory policy explicitly unsafe to port; owner new request permits optional cashier defaultOFF/companyadmin setting with live server/session/offline enforcement. New implementation required. |
| [#228](https://github.com/jawadrao5555-alt/taxnest/pull/228) | Source offline row-locator+DI release-parser code not present at inventory. Port evidence tooling only; actual owner workbook/batchreview remains HOLD. Historical CI source c43e312... run39423137428 not current-head proof. |

Main evidence: docs/qa/admin-billing-bulk-2026-11-07.md, whole-saas-diagnostics-bulk-2026-11-07.md, security-onboarding-bulk-2026-11-07.md explicitly distinguish shipped scope and broad tracker gaps. #393 initially scoped School/Hospital, not these entire programmes. No live inspection/mutation/merge/deploy performed.

