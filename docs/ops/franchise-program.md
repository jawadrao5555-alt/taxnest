# Franchise review and commission accounting

## Authority

- SaaS admin assigns `companies.franchise_id`. A franchise cannot claim or
  reassign a company.
- The franchise may review only its own company while both company status
  columns are `pending`. The review writes `franchise_company_approvals` and
  an audit event. It does **not** change company status, grant a package,
  verify a payment, or activate access.
- SaaS admin handles final company activation, package selection, payment
  verification, rate settings, refund adjustments, and payouts. Reassigning a
  pending company removes the old partner review so the new partner must review.

## Commission event

- Only a verified **subscription package** payment proof can earn commission.
  POS shop sales, trials, admin package grants, and paid add-on proofs do not.
- At payment verification the system stores the active franchise ID, rate, and
  distributor conflict flag on the proof. The immutable ledger decision uses
  those values and the verified received amount; a later rate change or
  company transfer does not rewrite the decision. Older proofs with no snapshot
  are not retroactively awarded by inference.
- A proof with both franchise and distributor attribution records a visible
  `attribution_conflict` decision worth zero, avoiding two automatic payouts.
  Admin must resolve attribution before verifying a future payment.
- `payment_proof_id` is unique on earned decisions. The recording service is
  idempotent and may be retried on a saved verified proof if a transient ledger
  write failed. It never fails the customer payment approval.
- Refund adjustments are signed negative lines tied to an earned entry.
  Super admin can adjust at most its remaining amount, including prior
  adjustments. A net payout atomically settles pending positive and negative
  lines, stores the bank reference and admin ID, and cannot be repeated on the
  same lines. Negative balance after a later refund offsets a later earning.

## Verification

`tests/Feature/FranchiseApprovalCommissionTest.php` covers tenant scoping,
no implicit activation, idempotent commission decisions, fixed rates,
non-package exclusion, attribution conflict, net payout and admin ownership.
`FranchiseIdorTest` asserts the only ID-taking franchise route and blocks
cross-franchise approvals. Run both, then the full PHP suite and browser UI
smoke in the isolated CI environment.
