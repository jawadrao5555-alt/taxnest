# TaxNest 23-source reconciliation inventory

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

