# Admin billing bulk — P1

## Pending add-on approval purchased-entitlement parity

Reproduced a second live-catalogue drift path after the DI dashboard correction:
the final review of an already quoted POS feature add-on compared its codes with
the current editable pricing-plan row. If the catalogue later moved that feature
into the package, the pending paid proof was rejected as a package change even
though the subscription ID, purchased package snapshot, quoted codes, amount and
expiry were unchanged. The approval recheck now uses the same immutable
`entitlementPlan()` view as access enforcement. New purchases still use the live
catalogue, and a real package-ID or expiry change is still refused.

Focused actual-controller coverage freezes Caller ID as optional, files its
server quote, edits only the live catalogue gate, and requires one approved
add-on at the original amount and subscription. No price, proration, refund,
credit, package term or add-on policy changed. Exact updated-HEAD hosted
PHP8.4/full-suite/native/browser/build acceptance remains required.

## Signup/trial evidence continuation

The corrected disposable signup cases reproduced two additional gaps against
the original service: a missing DI trial seed borrowed the PRA product's trial
plan, and newly created trials had no immutable terms. The old service was
loaded independently in a temporary test bootstrap to confirm those failures;
the feature branch was not reset for reproduction.

Trial creation now takes the same company lock as paid assignment. It returns
an existing active term unchanged, selects only its own product's trial plan,
retains the existing time-bounded plan-less fallback when that seed is absent,
and freezes available trial terms using the existing versioned snapshot.
The snapshot explicitly says trial_assignment and zero charged price; no paid
invoice or receipt is invented. A wrong-product creation request is refused;
legacy unknown product and the Healthcare stored aliases retain compatibility.
No existing trial, paid term, limit, price or company setting is backfilled.

Local PHP8.3.6/SQLite: **56 tests /268 assertions passed** across actual
DI/PRA/FBR signup, requested/picked packages, trial idempotency, existing paid
term preservation, migrated snapshot terms, missing-seed product isolation,
wrong-product refusal, registration and Healthcare pre-pilot. Paid package
selection during signup creates only the trial, retaining the requested plan
for the existing review flow; paid documents remain empty. Fixtures use the
actual sellable plan names and required PRA package selection.

The required native MariaDB lane now races two independent trial service
workers: both must return the same one frozen zero-price term, leaving another
company's paid term and all invoice/receipt counts unchanged. Native and
supported PHP8.4/full suite/browser/validate evidence is pending for this new
exact HEAD. No local MariaDB/Chrome acceptance is claimed. Whole registration
rollback/browser acceptance, upgrades/proration, companion/API parity and
audited financial adjustments remain in the full-scope checklist; this is
not completion of #363 or readiness for owner approval.

Base: 363a0d6823eae113336a6cd6069f160fc7a1a441 (merged #362). Priority: P1, owner requested 7 November 2026.

This is an implementation workstream opened as a Draft. Initial content records source reconciliation and acceptance; runtime implementation and acceptance are not complete. No approval-ready or deployed claim follows from documentation CI.

Source trackers: #337 / #316. Delivery order: first active batch.

## Current source evidence

- SubscriptionAssignmentService and AdminPaymentProofController already provide company/proof locking. #362 fixes same-package renewal duration. Preserve this path and its native concurrency probe.
- PosAddonService already quotes remaining package months, narrows submitted quote snapshots and resolves existing entitlements. Do not replace it with a second entitlement service or assume all quoting is missing.
- BillingController already stores distributor quote snapshots; add-on snapshots exist. Complete immutable purchase coverage requires reconciliation across all purchase paths.

## Release acceptance map

The implementation delivered by #363 is the existing-policy billing workstream;
#316 remains the broader planning tracker. No new package-price, differential
upgrade-credit, automatic-refund or commission-clawback policy is authorized
by this release.

| Required behavior | Runtime authority / behavioral acceptance |
|---|---|
| Signup, trial and unpaid selection | `TrialSubscriptionService`, transactional DI/PRA/FBR registration and DI proof-selection flow; `TrialPurchaseEvidenceTest`, `SignupTrialSubscriptionTest`, isolated desktop/mobile signup and receipt journeys |
| Reviewed assignment and renewal | `SubscriptionAssignmentService`, `AdminPaymentProofController`, `SubscriptionPurchaseSnapshot`; `SubscriptionPurchaseSnapshotTest`, `PaidRenewalSchemaTest` and native two-worker approval/trial probes |
| Immutable purchased access | `Subscription::entitlementPlan` shared by backend, billing views, consultant and DI API; snapshot catalogue-edit/legacy/tenant cases |
| Desktop/mobile companion parity | Actual PRA/FBR pairing preview/confirm routes with frozen entitlement, later opposite catalogue gate and retained legacy key; `PurchasedCompanionAccessTest`. Android shells reuse authenticated server pages; no installer credential or new mobile protocol |
| Itemized history/documents | `VerifiedSubscriptionDocuments`, `SubscriptionBillingHistory`, scoped company/admin PDFs; `VerifiedSubscriptionDocumentsTest`, `SubscriptionDocumentAccessTest` |
| Existing package-change policy | Reviewed change buys the existing full annual term at the canonical quote. Original term/snapshot is retained inactive; second tenant unchanged; retries issue one new term/invoice/receipt. Actual-controller matrix in `SubscriptionPurchaseSnapshotTest` |
| Existing add-on proration | Remaining calendar months (partial month rounds up), quoted price retained at approval, real changed-subscription/expiry refused; `PosPaidAddonFlowTest`, `PosExtraBranchPurchaseFlowTest`, `PosRenewalBranchSlotReviewTest` |
| Partial/excess and explicit adjustments | Invoice balance/excess evidence does not forgive debt or move bank money; `SubscriptionFinancialAdjustmentTest`, `VerifiedSubscriptionDocumentsTest`, native concurrent refund-evidence probe |

Release readiness additionally requires all eight hosted exact-head checks.
A green earlier head is never reused as proof for a new change.

## Verification and preservation

Use fictional tenants and mocked mail/payment delivery. Required PHP8.4/full suite, native MariaDB concurrency and authenticated desktop/mobile billing journeys must pass for the final HEAD. Preserve sales, payments, stock, subscriptions, fiscal references, printers and pairing. Unknown legacy terms require explicit review rather than guessed backfill.

## First implementation increment — assignment evidence

The real synthetic payment-approval route reproduced a missing snapshot: the
new subscription's purchase_snapshot was null. Assignment now stores version 1
catalogue identity, feature flags, limits, quoted package/branch price and period
under the existing company lock and a locked plan read. A nullable additive
migration leaves legacy history unknown; no backfill or price policy change.
Normal Eloquent updates reject replacing or adding historical snapshots. This
is an application write guard, not a database trigger against arbitrary SQL.

The snapshot describes assignment-time package terms, not bank receipt or net
distributor payment. It is not yet the authoritative entitlement reader. The DI
self-subscribe path, signup/trials, submitted purchase quote, add-on documents,
invoices/receipts, purchased-access enforcement, prorated upgrades and reversals
still require implementation/reconciliation. The checklist above stays open.

Local PHP8.3.6/SQLite tests passed: 51 tests /1178 assertions for the new actual
HTTP DI/PRA/FBR POS snapshot regressions plus renewal, instant-access, annual
pricing and account-overview suites. Tests cover catalogue edits, duplicate
approval, write immutability, unknown legacy rows and another tenant. The
initial adjacent add-on run hit missing local GD; GD was added to the private
runtime and the unchanged add-on suite was rerun. Supported PHP8.4/full-suite
and native MariaDB validation remain hosted requirements. The existing native
two-worker paid-renewal probe now also asserts snapshot stability across plan
edits and approval retry. Syntax and diff checks pass.

No UI markup changed in this increment. A full authenticated billing journey
and native result must still be verified on the published commit. This PR is
work in progress and is not ready for owner approval/deployment.

## Verified subscription invoice and payment evidence

Reproduced the actual approval HTTP route for PRA, FBR POS and DI: the new term
was activated but subscription_invoices remained empty. Added reviewed
subscription documents to the existing invoice/payment tables inside the same
proof/company/assignment transaction. A nullable migration preserves legacy
rows without guessed backfill. Each proof has one unique invoice identity and
its payment receipt; the existing account overview now has actual source rows.
No second billing ledger or entitlement authority was introduced.

Versioned document snapshots retain assignment terms, the reviewed amount,
company/product, reviewer/time and allocation. The invoice amount comes from
the existing enforced subscription price or already-validated distributor net
quote; the payment amount comes from independent admin verification, never the
customer's entered claim. Underpayment retains a part_paid balance; excess is
recorded as unallocated evidence, not reusable credit or an automatic refund.
The existing approval/access policy is preserved. In particular, DI's existing
enforced annual pricing is retained even when a synthetic catalogue row has a
different raw price. Proposed prices/billing policies remain unapproved.

Issued snapshots and document fields reject normal Eloquent changes; this is
an application guard, not a database trigger against arbitrary SQL. Legacy
history remains unknown. No live payment or transfer is performed.

Local PHP8.3.6/SQLite:54 tests/1236 assertions passed across snapshot/documents,
payment instant access, annual pricing, account overview and real-migration
renewal. Includes actual approval for three products, partial/excess payment,
immutable documents, retry and a real late receipt uniqueness failure that
rolls back invoice/assignment/proof while preserving another tenant's ledger.
The focused snapshot/document suites were rerun after adding explicit
post-retry invoice/payment counts:7 tests/115 assertions passed.
The required native two-worker approval probe also checks one linked invoice
and receipt, preserved after catalogue edit/retry. Its exact-HEAD hosted result
is still required, alongside PHP8.4/full suite/browser/validate.

This increment covers reviewed subscription proofs only. Add-on document
paths, authoritative purchased-access reading, self-service upgrade/proration,
explicit adjustments/refunds/credits
and the remaining signup/payment paths are not complete. The broad acceptance
checklist stays open. Owner's latest delivery decision is to finish363/364/365
and then combine on a latest-main feature branch with fresh combined testing
before presenting one bulk PR for owner approval. No merge/deploy authorized.

## Authorized verified-document history and downloads

Added a shared company-scoped history reader for the verified subscription
documents above. Digital Invoice, PRA POS and FBR POS billing screens now show
the frozen package/period, due amount, independently verified receipt amount,
remaining balance and unallocated excess. Owner/admin users can download the
invoice and receipt PDFs; SaaS admins receive the same documents from the
company account view. Every lookup is constrained to the authenticated company
or explicit admin company route. An independently logged-in product guard can
no longer be selected accidentally: the route name chooses the exact guard.

Only records carrying both a document identity and versioned snapshot are
downloadable. Unknown legacy charges stay visible to SaaS admins but are not
turned into invented invoices or receipts. PDFs render an allow-listed subset
of the snapshot and do not expose proof files, customer claims or arbitrary
metadata. Responses are private/no-store. This adds no activation, payment,
credit, refund or pricing behavior.

Local PHP8.3.6/SQLite focused regression:21 tests/1102 assertions passed across
tenant/admin authorization, cross-tenant denial, legacy unknown handling,
actual PDF responses, verified document creation and company account history.
Supported PHP8.4/full suite and authenticated desktop/mobile acceptance remain
hosted requirements. Add-on documents and the other unchecked billing items
remain pending, so the broad checklist and PR stay work in progress.

## Verified add-on invoices and receipts

Extended the same immutable reviewed-document path to paid PRA feature
add-ons, extra branch slots and DI AI-page top-ups. Their approval forms now
require an independently verified bank/receipt amount; the company-entered
claim remains display evidence only. The due amount is the server quote that
the existing approval path actually activates. Partial balances and excess
remain evidence only and do not forgive debt, create credit/refund or alter
entitlements.

The existing invoice table now permits a null subscription reference because
an AI-page top-up may legitimately be approved without a live package. Company
and unique payment-proof attribution remain mandatory. Feature and branch
add-ons keep their actual current subscription reference. Documents store a
versioned purchase kind and safe quote/quantity/code snapshot; history and PDF
labels distinguish add-ons without exposing proof uploads. Approval and
document creation remain one transaction, so a late document failure rolls
back entitlement/slot/page activation.

Local PHP8.3.6/SQLite focused run:75 tests/329 assertions passed across feature
add-ons, extra branches, reviewed package documents, authorized downloads and
standalone/same-subscription add-on document attribution. Syntax and diff
checks pass. Native migration/concurrency and full PHP8.4/browser acceptance
are still required for the next exact published HEAD. Shared purchased-feature
readers, unpaid/signup activation paths, upgrade/proration and explicit
credit/refund/reversal transitions remain pending; this PR is not ready.

## Purchased plan readers

The shared DI/PRA/FBR feature and numeric-limit readers now resolve a read-only
plan view from the version1 purchase snapshot. A catalogue edit no longer
replaces the recorded limits/features/name for that assignment. The snapshot
must match both company and assigned plan. Unknown versions, legacy rows and
misattributed snapshots retain the existing catalogue fallback; omitted legacy
fields also keep their existing meaning. Price/campaign fields are never
overlaid, and the related catalogue model is not mutated. Existing trial,
internal and administrator override rules continue through the same gates.

Local PHP8.3.6/SQLite:164 tests/617 assertions passed across purchased snapshot,
paid add-on, DI premium, Caller ID, monthly quota, offline gate, quick-create
and analytics gate suites. A wider run also hit5 workbook-import failures in
the private runtime, which lacks ZipArchive; those import checks remain hosted
requirements rather than a claimed local pass. Full PHP8.4/native/browser
checks must verify the next published HEAD. Remaining add-on/ERP/Agent direct
catalogue readers still require reconciliation alongside unpaid activation,
upgrades/proration and audited adjustment transitions.

## DI unpaid package selection

The existing direct Subscribe route deactivated the current term and created
an active paid subscription without a receipt. Replaced that mutation with a
server-created, company-scoped session selection and a labelled receipt form.
The existing payment-proof review/temporary-access rules remain the only
submission path; choosing a package alone creates no subscription or payment.
The existing paid term is retained byte-for-byte. Only the company owner may
select. Retired, trial and other-product plans remain refused, including a
second validation after locking the catalogue row.

Repeat selection and later receipt upload reuse the server quote for that
company/plan. A completed verified proof invalidates the older selection so
its first-purchase discount cannot be replayed for renewal. Existing legacy
active subscription quote compatibility remains in the distributor service.
No client-supplied quote or claimed payment amount becomes receipt evidence.

Local PHP8.3.6/SQLite:34 tests/265 assertions passed across actual DI selection,
repeated quote and later upload, unchanged existing paid term, staff refusal,
retired endpoints and existing temporary payment-proof access. Added required
real desktop1366/mobile390 browser journey for package selection and receipt
upload while the current paid package stays visible. Node syntax and Blade
compilation pass locally. Current environment has no Chrome or MariaDB binary;
actual browser/native/full-suite evidence remains required on hosted CI.
Signup, package upgrades/proration, remaining direct entitlement readers and
audited credit/refund/reversal transitions still need completion.

## Remaining central purchased-access consumers

Connected existing add-on/branch-slot gates, DI AI allowance, Healthcare module
and department gates, terminal/inventory controller checks, middleware and
billing usage displays to the same purchased plan view. Add-on expiry and
subscription binding, internal/granted/trial compatibility and company numeric
overrides stay in the existing paths. Sale/renewal quotes continue to use the
catalogue and existing pricing services. Healthcare tests prove purchased OPD
modules/department limits survive a later catalogue edit without modifying the
catalogue. DI tests additionally prove a purchased page allowance is retained.

Local PHP8.3.6/SQLite:181 tests/791 assertions passed across snapshot, paid
add-ons/branch slots, branch renewal, AI credits/reader, Healthcare foundation
and subscription lock plus DI API access. Follow-up access-service regression
passed59 tests/341 assertions. All required hosted full-suite/native/browser/Agent gates remain required on
the next published HEAD. This does not complete signup/upgrade/proration or
audited financial-adjustment acceptance.

## Atomic public signup continuation

An actual HTTP trial-creation failure reproduced retained company/user/credential
rows on PRA and FBR POS registration. DI already wrapped these writes. PRA/FBR
now wrap company, owner, credential ledger and trial in one transaction; login
and referral-session consumption happen only after commit. Existing language,
category, requested package, annual cycle and pending approval defaults remain.
A late failure leaves no burned credential or orphan owner/company. The same
credentials can retry, with one zero-price trial and no paid documents; another
paid tenant and its original term remain byte-for-byte unchanged.

Local supported-behavior regression on private PHP8.3/SQLite: **53 tests /285
assertions passed** across atomic signup, trial, requested/picked packages,
registration and tables-first behavior. New browser acceptance exercises actual
DI/PRA/FBR registration forms in isolated contexts at1366/390, alongside the
existing selection/receipt flow. Browser/native/full PHP8.4/validate proof is
pending for the updated exact HEAD; no claim follows from local SQLite. Earlier
bae94f3e4e745c862e8ecf944ab861e028557562 native job128463373975 explicitly
passed concurrent trial creation with one frozen term, paid company and documents
unchanged; that result does not cover this latest transaction/UI update.

Existing-policy upgrades/proration and companion parity remain open. Proposed
prices and new commercial policies are not approved. Finish all three
workstreams before one combined approval PR.

## Nest ERPS / Healthcare billing-document parity

Reproduced a product-surface gap: Healthcare owners could use the shared
purchased-plan gates but could not see or download the same issued subscription
invoice and receipt evidence available in DI, PRA POS and FBR POS. The existing
company-scoped history reader and PDF renderer are now reused on the Healthcare
settings screen. No second ledger, price calculation or access policy was
introduced.

The route name selects the `health` guard explicitly. Healthcare ownership is
resolved by `HealthAccessService`, so the legacy `company_admin` owner remains
compatible while a healthcare administrator or delegated staff member cannot
read owner subscription documents. Every document lookup remains bound to the
current company; a second Healthcare owner receives not-found for another
tenant's invoice. Unknown legacy rows are still not invented.

Local PHP8.3.6/SQLite: **42 tests / 228 assertions passed** across the focused
document access and Healthcare foundation suites. The narrower document suite
passed **4 tests / 48 assertions**, covering owner history, both PDFs,
administrator denial and second-tenant isolation. Hosted PHP8.4/full suite and
desktop/mobile acceptance remain required after publication. This closes the
Healthcare browser-document parity slice only; mobile/Agent/API enforcement,
existing-policy upgrades/proration and audited financial adjustments were
still open at that checkpoint; the next section records the adjustment slice.

## Append-only financial-adjustment evidence

Added explicit, company/invoice-scoped evidence for a reviewed credit note, an
externally completed refund and an exact reversal. These are immutable
append-only rows with a unique request identity, persisted reviewer, reason,
time, amount and safe invoice/receipt snapshot. A refund must identify the
exact reviewed receipt and an external reference. A reversal retains the
original row and must exactly offset it; it cannot rewrite or delete history.

The service locks the persisted billing administrator, company, invoice and
receipt. Active credit evidence cannot exceed the issued invoice and active
refund evidence cannot exceed the reviewed receipt. Concurrent requests are
therefore bounded. A same-key retry returns the original row only when every
material field matches. A separate audit row is written atomically. Foreign
company invoices/receipts and stale non-billing roles are refused.

This is deliberately evidence only. It does not move bank money, forgive a
balance, issue an automatic refund, change subscription access, alter a price,
or update an issued invoice/receipt. The SaaS company account screen exposes
the retained rows and a clearly labelled administrator form; company product
logins do not receive the admin route.

Focused feature coverage now exercises partial refund evidence, retained
credit, exact reversal, idempotent retry, over-allocation refusal, tenant/role
isolation, immutable rows and unchanged company/subscription/invoice/payment
records. A mandatory native MariaDB two-worker probe races two 700 PKR refund
records against one 1,000 PKR receipt, requires exactly one winner, then proves
idempotent retry, retained reversal/replacement, audit count and byte-for-byte
preservation of the billing records and a second tenant. Local PHP execution is
unavailable in this current workspace, so no local PHP/native pass is claimed;
the exact published HEAD requires hosted PHP8.4, MariaDB, browser and validate
evidence.

The first hosted native migration stopped before exercising runtime logic
because Laravel's generated invoice foreign-key identifier exceeded MariaDB's
64-character limit. The migration now uses explicit short FK and index names;
no constraint, assertion or business rule was removed. Fresh exact-HEAD hosted
evidence is required after this correction.

This closes the explicit audited credit/refund/reversal runtime slice without
approving a new commercial policy. Existing-policy upgrade/proration behavior
and remaining mobile/Agent/API companion parity stay open.

## Consultant companion purchased-access parity

The consented consultant console previously read the mutable catalogue plan for
client plan/quota/trial chips while backend access used the frozen purchased
snapshot. Referral commission descriptions and trial exclusion had the same
drift. Both paths now call the shared read-only entitlement plan. A later
catalogue rename, trial flag or invoice-limit edit cannot rewrite what the
client bought or what its consented consultant sees; company overrides and the
existing commission rate/final-price rules remain unchanged.

A focused regression keeps the active consent link, company, subscription and
snapshot fixed, edits the catalogue, and requires the original purchased name,
quota and paid/trial state plus a single commission described by the purchased
plan. This does not add consultant write access, new pricing, proration or an
automatic refund/credit policy. Hosted PHP8.4/full-suite acceptance is required

## DI dashboard purchased-access parity

The DI company dashboard now derives both its displayed monthly invoice quota
and plan tier from the same immutable purchased entitlement used by access
enforcement. Editing the live catalogue after purchase can no longer change the
customer's displayed quota or tier while their frozen term is active. Existing
company quota overrides remain authoritative.

An actual authenticated dashboard regression creates a frozen Kaarobar term,
edits the catalogue row to Asaan with a different invoice limit, and requires
the rendered dashboard to retain the purchased quota and enterprise tier while
leaving the catalogue row unchanged. This changes no price, term, upgrade,
proration, credit or refund policy. Hosted PHP8.4/full-suite/browser acceptance
must verify the published exact HEAD.
for the exact published HEAD; no local PHP runtime is available here.

## SaaS admin subscription-history purchased-name parity

The SaaS administrator subscription table and its retained history still
rendered names directly from the mutable catalogue relationship. Editing a
catalogue label after purchase could therefore rewrite what the administrator
saw for an existing frozen term even though runtime access already used the
immutable purchase snapshot.

Current and historical subscription labels now use the same read-only
`entitlementPlan()` view. The new-assignment selector and reactivation checks
continue to use the live catalogue and existing sellability policy; no price,
term, access, upgrade or proration rule changed. A focused actual admin-page
regression freezes a purchased name, edits the catalogue name and requires the
purchased label on the subscription page.

This closes this administrator display slice only. Existing-policy
upgrade/proration and remaining mobile/Agent/API companion parity remain open,
and exact hosted full-suite/browser acceptance is required for the published
HEAD.

## Company-detail purchased-name parity

The legacy company detail and the SaaS company account views still mixed the
frozen purchased entitlement with the mutable catalogue relationship. A later
catalogue rename could therefore rewrite the active-plan summary, retained
subscription history and the context shown beside a manual correction even
though access enforcement and the subscription list already used the immutable
purchase snapshot.

Those existing read-only labels now use `entitlementPlan()`. The package picker
continues to list the live catalogue for an explicitly reviewed change; no
assignment, price, term, access, upgrade or proration behavior changed. A
focused authenticated company-detail regression freezes the purchased label,
renames the catalogue entry and requires the purchased label across current,
history and correction context while preserving both records.

This closes another administrator companion-display slice only. Existing-policy
upgrade/proration and remaining mobile/Agent/API companion parity remain open;
fresh exact-HEAD hosted PHP8.4/full-suite/browser acceptance is required.

## DI push API purchased-access parity

The billable DI push endpoint already calls the shared subscription access
service after machine-key and company-lifecycle authentication. An actual HTTP
regression now freezes a one-invoice DI trial purchase, edits the live catalogue
into a paid 100-invoice package, and requires the connected ERP to create only
the one purchased invoice. The second request receives the existing structured
subscription-lock response and creates no invoice row.

This verifies that browser/backend and stateless DI API callers share the same
immutable purchased entitlement while the read-only status endpoint and legacy
snapshot fallback remain unchanged. No API key, price, term, proration, credit
or refund policy changed. Hosted PHP8.4/full-suite acceptance is required for
the exact published HEAD. Mobile/Agent companion acceptance and existing-policy
upgrade/proration behavior remain open.

## Release-candidate closure — 8 November 2026

Hosted baseline `b7266d7a44e933105ac907842cdf895b361d30c3` ran
6,000 tests and failed only the new DI API quota fixture: it expected a draft
invoice to exhaust a one-invoice trial. Existing policy counts only submitted
DI invoices. The corrected real-HTTP regression first proves two drafts remain
free, then marks one retained synthetic submission and requires the next API
request to be refused at the frozen one-invoice cap. No fiscal request, quota
policy or assertion was removed.

Added real PRA/FBR desktop/mobile pairing acceptance with both purchased and
excluded offline access, opposite catalogue edits, retained legacy agents,
unchanged saved printer and second-tenant records. Added actual reviewed
package-change acceptance for DI/PRA/FBR at the existing annual quote with
one result under retry, original historical terms retained and second-tenant
isolation. Existing calendar-month add-on proration acceptance remains required.

The mandatory hosted PHP lane now runs these billing/companion regressions
before the full suite, without removing any existing gate. The release notice
now describes this billing release rather than repeating the inherited KOT
note announcement. Final exact-head CI results are recorded in the PR.
