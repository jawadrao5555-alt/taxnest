# Admin billing implementation from #337

This first runtime slice addresses the paid-renewal duration defect in #337/#316.
It does not complete the broader #316/#319/#320/#321 workstream.

## Reproduction and correction

At a frozen 7 November 2026 date, approve a real synthetic payment-proof HTTP
request renewing the same paid package whose term ends 28 February 2027.
The previous checkout creates an expiry of 7 November 2027, losing paid time.
The corrected payment approval creates expiry 28 February 2028, retains the
original subscription history and does not extend again on a repeated approval.

The existing company mutex protects selection and replacement. Multiple active
subscription records require review. The renewal option applies only to the
payment-proof lane and a single same-package positive-price, non-trial term
with no active override. Existing manual assignment, trial conversion, expired
terms, grants, and plan changes retain their existing period rules. No stored
customer records are backfilled. The payment audit includes the resulting term.

## Acceptance evidence

- The original HTTP regression failed before enabling the corrected paid renewal
  path: actual 2027-11-07 instead of expected 2028-02-28.
- Focused local tests cover DI, PRA POS and FBR POS, repeated payment approval,
  trial conversion, ambiguous active terms, rollback and another company.
- The adjacent annual-price and company-account overview tests passed locally.
- Local PHP is 8.3.6 with SQLite, below the repository's unchanged PHP 8.4.1
  requirement. Hosted PHP 8.4 full-suite and native MariaDB gates remain required.
- No UI markup changes. The real payment approval HTTP endpoint is exercised
  with fictional records and mocked notifications. Browser and native database
  acceptance specific to this renewal flow remain to be completed.

## Remaining #337 scope

| Area | Current evidence | Remaining acceptance |
|---|---|---|
| Company account overview | Existing CompanyAccountOverview, company-linked history, payments and access display | Reconcile each #316 requirement against current behavior |
| Paid renewal | Correction and focused HTTP regression in this PR | Hosted and native concurrency verification |
| Billing snapshots and payment lifecycle | Existing services and partial add-on/distributor snapshots | Audit immutable purchased features, invoices, receipts, allocations and reversal paths |
| Diagnostics | #327/#349 stored reconciliation and Agent evidence already on main | Remaining products/adapters, seven-day collection and source completeness |
| Security and privacy | Existing authorization and recovery foundations | Reconcile #320 requirements and negative tests |
| Onboarding and support | Existing product-targeted notices and support foundations | Reconcile #321 requirements and authenticated journeys |

#337 remains the source tracker. Existing HOLD/parked programmes and proposed
unapproved prices are outside this slice. Deployment requires owner approval.
