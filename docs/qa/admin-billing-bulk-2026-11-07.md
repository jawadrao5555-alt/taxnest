# Admin billing bulk — P1

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

## Implementation acceptance

- [ ] Inventory every signup, payment-proof, manual assignment, renewal, upgrade and add-on path; identify invoice/receipt/allocation authority and legacy behavior.
- [ ] Freeze agreed price, product, plan limits and purchased features at purchase/approval with an explicit snapshot version; catalogue edits must not rewrite historical purchases.
- [ ] Link unique subscription invoice, verified payment, receipt, allocation and activation atomically; retries and concurrent approval create one result.
- [ ] Complete itemized company/admin billing history and authorized invoice/receipt downloads.
- [ ] Reconcile upgrade/add-on prorating against existing rules. Preserve existing agreed terms; proposed annual prices are not approved.
- [ ] Verify shared backend/browser/mobile/Agent feature enforcement against purchased access, including legacy records with no snapshot.
- [ ] Handle partial payment, credit and refund/reversal through explicit audited transitions; never delete accounting history or automatically issue real refunds.
- [ ] Exercise DI/PRA/FBR POS, trial/expired/granted/paid states, a second tenant, concurrent reviewers, duplicate callbacks, catalogue changes and reversals.

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

Existing-policy upgrades/proration, companion parity and audited financial
adjustments remain open. Proposed prices and new commercial policies are not
approved. Finish all three workstreams before one combined approval PR.
