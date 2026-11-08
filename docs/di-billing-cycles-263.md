# Digital Invoicing multi-cycle billing — Issue #263

Status: implementation pending; this document is a reviewable specification, not a functional feature.

## Scope
Offer monthly (1 month), quarterly (3 months), semi-annual (6 months), and annual (12 months) billing cycles for Digital Invoicing subscriptions. Preserve existing monthly subscriptions and tenant boundaries.

## Configuration
Admin defines a plan's supported cycles, cycle price, discount (if any), currency, applicable tax and effective date. Do not assume prices or discounts. Existing paid subscription terms remain immutable unless customer explicitly changes plans.

## Customer checkout
Show duration, total, effective monthly price, tax, discount, start, paid-through and next renewal date. Store selected cycle and pricing snapshot on the subscription and invoice. Payment confirmation is idempotent.

## Entitlement lifecycle
Subscription validity and DI access follow paid-through dates. Renew on calendar-month boundaries with documented month-end and leap-year handling; prevent duplicate renewals and invoices. Define upgrade/downgrade proration, cancellation, grace period and failed payment policy before release. Provide customer and admin visibility and renewal notices.

## Testing / acceptance
- All four cycles produce correct renewal dates and invoice totals.
- Existing monthly subscribers retain current price and renewal schedule.
- Payment retries/webhooks cannot charge twice.
- Tenant/company permissions isolate pricing, subscriptions and receipts.
- Changing current price never changes an already-issued invoice.
- End-to-end checkout, payment, renewal, failure and cancellation tested.

Implementation must be added in subsequent commits before this PR can be merged. Do not deploy a documentation-only PR as if the feature is live.
