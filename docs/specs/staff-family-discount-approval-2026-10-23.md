# Staff / family discount: owner-confirmed implementation contract

Status: DRAFT DESIGN ONLY. No approval, billing or fiscal behavior is implemented
by this document. Do not promote this PR as a working feature. Owner request on
23 October supersedes the previously suggested fixed 10% / two-bill / Rs1000
limits; those suggestions are NOT approved requirements.

## Required customer flow

1. Cashier selects an active employee of the current company and identifies
   staff or family use. Cashier proposes either 1–99 percent OR a rupee amount,
   with a reason. The proposal is an approval request, NOT a final invoice.
2. Owner/company admin sees the employee, family/staff reason, full order and
   proposal. They can retain or change the percentage/amount, approve or reject.
3. Neither reporting ON nor OFF permits finalization before approval. With
   reporting ON, the approved bill follows the existing normal PRA path. With
   reporting OFF, it follows the normal local-final path (PRA invoice mode,
   NULL pra_status). It must never be mislabeled a provisional just to avoid PRA.
4. Approved actual discount appears once on the receipt. The card tax rate is
   still tax; it is not a second discount. Coordinate presentation with #249.
5. Ordinary non-staff bills keep the existing flow. This is not #237's HOLD
   proposal for approving every cashier cash/online payment.

## Money and authorization rules

- One bill-level discount, percentage OR amount; never stack a hidden ordinary
  discount with a staff discount. Existing line discount eligibility must be
  explicitly accounted for when deriving the remaining eligible subtotal.
- Percentage range 1 through 99 inclusive; fixed amount must be positive and
  not exceed the authoritative remaining eligible subtotal. Reject negative,
  nonfinite, malformed or over-total values server-side.
- Price, eligible subtotal, tax rule and payable are calculated server-side by
  existing PosTaxMath/payment pricing, not trusted from browser totals.
- Company-owner/company-admin authorization only for review. A role in another
  guard/company, a cashier, or a guessed request ID grants no review rights.
- Active employee identity must belong to the same company. Employee identity
  source needs to be established against the restaurant's existing staff/user
  roster; do not accidentally depend on HealthStaffProfile.
- Record immutable proposed values, every reviewer revision, employee,
  requester, approver, reason and time. Keep rejected requests and history.
- Editing finalized invoices or reporting already finalized bills again is
  forbidden. Review occurs before the first finalization.

## Required server architecture before activation

Separate tenant/branch-scoped pending requests from pos_transactions and PRA
queues. Pending approval must consume neither a final serial nor payment nor
fiscal submission. Persist cart/order revision, request UUID, proposing cashier,
employee and reason. Existing held order ownership/status and table claims must
be coordinated so a second counter cannot separately settle the same order.

Approval uses optimistic revision plus an atomic request claim. A lost response,
parallel approvals, retry and worker restart must resolve the same final invoice.
Reuse existing idempotency and authoritative tax/pricing/stock/quota services;
do not call storeInvoice inside an outer DB transaction without first separating
its post-commit fiscal/stock side effects. Preserve cashier/branch attribution,
current billing scope and the reporting policy; do not substitute the owner's
own reporting toggle when an owner approves a cashier's request.

Rejection releases the request for correction without generating a bill. Cart
changes after review invalidate approval and require a new revision. Offline
clients may retain an unsent proposal but must not finalize or submit a staff
bill until server approval is obtained. The ordinary offline sale path remains
unchanged. A new optional feature must default OFF for existing companies and
must not reset any saved kitchen, tax, printer or reporting settings.

## Acceptance matrix (required, not yet run)

| Case | Required result |
| --- | --- |
| Cashier proposes 1% / 99% | Pending only; no fiscal call/payment/final serial |
| Percentage 0 / 100; amount <=0 / above eligible | Server rejects |
| Owner changes percentage to amount | Only revised approved discount finalizes |
| Cashier / foreign tenant / foreign employee review | Denied, no writes |
| Two owners, same revision | One final invoice; second gets conflict/replay |
| Lost approval/final response or process crash | Retry recovers original invoice |
| Cart/order edited after approval | Approval invalidated, no stale-price final |
| PRA ON / OFF | Existing normal reporting / NULL-status final respectively |
| PRA network failure after approval | Existing queue/idempotent recovery |
| Staff feature OFF / ordinary bill | Existing payment and offline flow unchanged |
| Existing ordinary or line discount | No duplicated discount; valid eligible base |
| Cash/card, inclusive/exclusive/card-save | Existing PosTaxMath rules retained |
| Both receipt widths | Actual discount once, tax/payable unchanged |
| One/many counters, held/waiter/direct/manual/deal | No bypass/double settle/KOT |
| Real Chrome desktop1366/mobile390 | Cashier proposal + owner revision/reject/approve |

## Current evidence and release boundary

Source reviewed: PosController::storeInvoice, RestaurantPosController payment/
held paths, routes/web.php POS guard/company/branch middleware, User role checks,
architecture invariants. These paths currently finalize directly; no complete
staff approval gate exists. The feature needs coordinated UI, migration,
server-state-machine and fiscal/idempotency tests. This design PR changes only
this document; application tests are not claimed. Full implementation and
supported PHP8.4/MariaDB/Chrome acceptance are outstanding. No production access,
real printer, migration, merge, approval or deployment was performed.
