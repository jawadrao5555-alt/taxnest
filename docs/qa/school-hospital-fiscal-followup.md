# School and Hospital real fiscal activation follow-up

## Status: blocked, draft, no integration acceptance

Based on main `cccb857ab71d0f9814b1b1bae5dc5cb29c04e207` after #393.
This follow-up is not a working School/Hospital FBR adapter. It preserves existing
hard dispatch denials and adds an explicit category-specific evidence inventory.
Neither request fields, an administrator's assertion, nor an existing generic
retail/DI token can satisfy that inventory. No pending ON intent is stored.
No production or sandbox request has been sent by this change.

## Official evidence checked on 16 November 2026

- [Final SRO 428(I)/2024](https://download1.fbr.gov.pk/SROs/2024322153582988SRO-428.pdf)
  replaces the Rule 33A Schedule I. Private hospitals/medical care centres are
  item 8; private educational institutions are item 14, with the stated exception
  for monthly fees below Rs. 1,000 per child. This is official applicability
  evidence, not proof that a specific taxpayer is currently enrolled or that a
  generic sales-tax retail endpoint accepts their receipts. Current amendments,
  taxpayer exclusions and appropriate reportable events still require review.
  The schedule separately lists medical service providers (item 6, fees below
  Rs. 500 excluded), diagnostic/pathological laboratories (item 7), and hospitals
  (item 8); these must not be collapsed into one assumed tax rule.
- [SRO 288(I)/2026](https://download1.fbr.gov.pk/SROs/2026218112270512SRO288dated18.02.2026.pdf)
  is explicitly a proposed amendment. It is not used here as an operative API
  or a replacement for the final schedule. A subsequent final replacement was
  not established by this research.
- [FBR technical assistance](https://www.fbr.gov.pk/pos-technical-assistance/163085/163087)
  presents retailer fiscalization documentation. That label alone does not verify
  the requested School or Hospital reporting contract.
- [DI API v1.12](https://download1.fbr.gov.pk/Docs/20257301172130815TechnicalDocumentationforDIAPIV1.12.pdf)
  describes a generic digital invoicing schema. Its `invoiceRefNo` is a debit-note
  reference, not evidence of an idempotency key. Generic access or success
  envelopes must not be treated as category authorization or accepted invoices.

- [PRAL DI User Manual v1.6](https://dicrm.pral.com.pk/assets/docs/Manuals/Digital%20Invoicing%20User%20Manual%20-%20v1.6.pdf)
  describes integrator selection, IP whitelisting and sandbox onboarding. Its
  page 31 reconnection/resubmission guidance is not proof of remote deduplication
  after an accepted-but-response-lost request. No automatic resend is enabled.

## Separate category evidence still needed

| Evidence | School | Hospital |
|---|---|---|
| Current applicable law/exclusions for target company | Verify enrollment/applicability | Verify enrollment/applicability |
| Official reportable event | Fee receipt vs charge/period/adjustment unresolved | Bill vs installment/payment/adjustment unresolved |
| Endpoint, versioned schema and auth | Not confirmed for category | Not confirmed for category |
| Tax mapping | Fee-head/exemption/discount mapping not confirmed | Service/partial-payment mapping not confirmed |
| Authorized sandbox identity/access | Not supplied | Not supplied |
| Accepted fiscal number and QR | Not obtained | Not obtained |
| Timeout lookup, duplicate identity and safe retry | Not confirmed | Not confirmed |

Do not reuse PRA service rates, retail IMS fields, or DI invoice rules by inference.
Do not invent a status endpoint, response format, QR or accepted sandbox proof.
Credentials must be supplied only through authorized secure handling, never PRs,
notes, screenshots, logs or commit contents.

## Remaining implementation after authoritative contract/access

1. Implement category adapters and validation from the verified contracts.
2. Bind sandbox evidence and reviewed adapter version to the company and category.
3. Permit current SaaS superadmin ON only when those checks are satisfied, default
   OFF. Snapshot eligibility at new-bill creation; never backfill OFF-era bills.
4. Serialize submission ownership and preserve immutable request identity. After
   an uncertain result quarantine and use the official recovery operation before
   another attempt; do not blindly resend. Keep valid historical acknowledgements.
5. Render fiscal number/QR only after a validated accepted acknowledgement. OFF
   stops new submissions and leaves local billing and evidence intact.
6. Prove acceptance, rejection, timeout/status recovery, duplicate races, OFF/ON
   boundaries and company isolation in authorized sandbox; rerun retail/PRA/DI.

## Verification for this bounded checkpoint

The existing control remains OFF-only with audited HTTP 422 rejection of ON.
Category checklist and forged-evidence tests are synthetic, not real sandbox
acceptance. Required CI must finish on the final exact SHA. Keep this PR draft
until both actual sandbox evidence and implementation/required checks are done.
No migrations, tenant data changes, production submission, merge or deploy.

## Company/category evidence isolation

Each readiness response and requirement carries its server-resolved company ID
and School/Hospital category. Evidence is non-transferable, including between two
School companies. Request-supplied readiness, verified flags, company IDs or
category labels never satisfy the gate. Reportable event and authentication are
tracked separately from transport/schema; both remain unverified.

A future verified OFF transition must stop new submissions without deleting
in-flight requests or acknowledgements. Already uncertain requests must remain
quarantined and be reconciled using the official recovery contract, not resent
blindly or silently cancelled. This PR preserves those records but does not claim
to implement that unavailable official reconciliation operation.

## Four independent route candidates and immutable bill design

| Category | IMS | DI |
|---|---|---|
| School | Contract/enrollment/adapter/sandbox unverified | Contract/enrollment/adapter/sandbox unverified |
| Hospital | Contract/enrollment/adapter/sandbox unverified | Contract/enrollment/adapter/sandbox unverified |

The admin selector offers OFF, IMS and DI, but IMS/DI stay disabled. Direct
requests for either route are rejected and audited; no pending route selection
is persisted. Each route has its own company/category-bound requirements. An
IMS acknowledgement is not DI evidence, nor does School authorize Hospital.
The existing PRA integration separation is an implementation reference only,
not an applicability or tax contract for either pilot.

After contracts and category/company sandbox acceptance exist, new-bill creation
must atomically freeze route, adapter version, environment, identity and eligibility
with the original receipt. OFF-era receipts remain local forever. Retry must use
that frozen route, never current selector or a fallback route. An uncertain request
must reconcile on its original route before any possible retry; OFF must not erase
its identity or acknowledgement. A valid acknowledgement updates the same saved
receipt with fiscal identity/QR; Agent printing is a separate verified step.

Those active-route snapshots, actual adapters, official reconciliation and physical
printing acceptance are **not implemented/verified by this blocked selector**.
No schema backfill, migration, route transfer, or live company change is performed.

## Implemented OFF snapshot and fail-closed recovery foundation

New School receipts and Hospital invoices now atomically capture immutable routing
metadata with route OFF, eligibility false, company/category/source and version.
Hospital payment rows inherit the invoice's exact snapshot bytes; installments on
legacy invoices retain null. Duplicate payment recovery never rewrites a snapshot.
Nullable additive columns are intentionally not backfilled, and retained on rollback.
During a rolling deploy before the column exists, local billing remains available
but records have no snapshot and are treated as legacy/ineligible, never inferred ON.

`PilotFiscalRouteSnapshot::recoveryPlan` only reads the allowlisted company-scoped
source and its stored snapshot. It refuses cross-route/category/company matches,
returns local-only for OFF, legacy hold for missing provenance, and same-route
reconciliation-required for stored IMS/DI uncertainty. It never submits, retries,
updates acknowledgements, trusts today's selector, or invents a regulator lookup.
This is a safe recovery planner, not an implemented official reconciliation adapter.
The actual active-route integration, regulator acceptance and Agent print verification
remain blocked as described above.

## Reusable wiring without speculative fiscal mapping

`FbrService::interpretPosDiAcknowledgement` now exposes the existing complete-header/all-item DI validation as a pure classifier; the established DI transport calls it. `FbrPosFiscalResult` supplies the existing explicit IMS success-code/number interpretation to the Agent callback. Company/device/exclusive-claim validation remains outside that classifier and mandatory. Neither classifier authorizes a company or writes a School/Hospital acknowledgement.

`PilotFiscalSourceContext::inspect` is an internal, company-scoped, read-only preparation boundary. It reuses immutable route validation and preserves charge, this payment, cumulative paid at receipt, and historical balance separately. Hospital bill context has no implied payment; later installments do not change an earlier payment context. Legacy missing fields stay unknown. Local fee/service lines are not a regulator payload. `reportable_event`, `tax_mapping`, and `regulator_payload` remain null and `can_dispatch` false until an actual category-specific contract exists. No endpoint, token, route fallback, QR or fiscal number is fabricated.

Synthetic acceptance covers distinct amounts, later installments, cross-company denial, unchanged receipt rows, zero HTTP requests and complete/partial/ambiguous acknowledgement classification. This is reusable preparation, not a working category adapter or sandbox acceptance. Official fee versus payment event, hospital service bill versus installment event, classification/tax/adjustment mapping, company enrollment, and authoritative same-route uncertain-response recovery remain required independently for each of School×IMS, School×DI, Hospital×IMS and Hospital×DI.

## Owner-requested UI dependency reconciliation from #395

The School/Hospital slice is now also included here, preserving #395's source work:
- `bc4894e0`: Hospital scoped outstanding balances and campus/team context.
- `ac72df1d`: School fee rates, required new bank/card payment references, immutable retry references, scoped outstanding accounts/search, collection dashboard/settings/history/receipt presentation and translations.
- `50bf4c97`: native School reference/rate preservation acceptance.
- School browser portion of `95e8cdce` and unassigned pilot-campus view portion of `c6fb1e0a`.

School receipt insertion combines the payment-reference change with #394's immutable OFF route snapshot. Both source test sets are retained. Existing mandatory browser/native jobs already execute the selected School modules. The added nullable rate/reference migration performs no data backfill and retains historical columns on rollback. Unrelated franchise/security/public/tutorial/demo changes remain outside this integration; #395 retains its work for subsequent reconciliation, not deletion.

These UI changes improve local collection usability. They do not establish IMS/DI applicability, activate reporting, replace the missing category contract or constitute sandbox acceptance. Both School and Hospital actual testable-route requirements remain open.

The remaining narrow onboarding dependency from #395 is also reconciled: shared package comparison and FBR landing no longer promise fiscal QR on every receipt, universal silent printing, or automatic School/Hospital reporting. Package entitlement is distinguished from category and verified integration availability. The illustrative receipt is explicitly non-fiscal. Links to guide anchors not present on this branch are not imported. A focused public-claims test protects these distinctions and explicit signup category/package controls; unrelated product guide/tutorial changes stay in #395.

### Latest owner release priority
IMS is the primary requested route independently for School and Hospital: configured Desktop Agent → IMS → validated callback → fiscal identity/QR on the original saved receipt. DI is optional/on-demand and is not an IMS release prerequisite. Historical four-route research and safeguards remain preserved; neither company's/category's evidence transfers to the other. The current code has no working category IMS adapter. Both category IMS mapping/enrollment/sandbox and authoritative uncertain-response contract remain required before claiming testability or promoting this PR.

### School product-event decision (owner specified)
The intended School event is each finalized saved payment receipt, reporting that receipt's received amount only. A 10,000 charge paid as 4,000 then 6,000 has two distinct receipt identities and received amounts; the fee account, charge, outstanding balance and student ledger are not submitted as additional turnovers. Draft/view/reprint do not create a new event. Retry uses the existing company/receipt identity and original route, never a replacement receipt or cross-route fallback.
This is an explicit product decision, not an assertion of FBR approval. Required official School IMS mapping, tax/classification, enrollment, sandbox acceptance and uncertain-response recovery validation remain open. The per-category readiness description now states this distinction rather than asking the owner to choose the event again.

Hospital product event is now also owner-specified: each finalized payment receipt reports its received amount. A 20,000 internal service bill paid as 8,000 and 12,000 has two independent payment-receipt identities, while the bill/patient ledger stays internal and balance reaches zero. This resolves product intent without establishing regulator acceptance. Official Hospital IMS mapping and sandbox verification are still independent of School. No draft, bill view, ledger view or receipt reprint triggers a new report; callbacks must eventually bind valid acknowledgement to the same saved payment receipt through its original route.

`SchoolImsPaymentEvent` and `HospitalImsPaymentEvent` implement this owner-selected local preparation contract from persisted, company-scoped finalized receipts. Tests cover full/split payments, distinct stable local identities, unchanged records, private ledger omission, no draft/view/reprint event and same-route uncertain HOLD. These services do not enqueue, call a regulator, persist acknowledgements or render QR. A local identity is not asserted to be an authority idempotency key. Actual adapter/callback-to-pilot-receipt wiring and accepted sandbox outcomes remain incomplete.
