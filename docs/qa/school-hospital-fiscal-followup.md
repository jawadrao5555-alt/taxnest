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
