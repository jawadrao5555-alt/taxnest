# Mixed Serve Here, Parcel and Delivery — PR 272

## Problem and implemented behavior

The original cart merged identical products before considering their service
instruction. On a table bill, a second pizza intended as parcel increased the
already served pizza's quantity. A single note could then make the kitchen
interpret both pizzas as parcel. The original production addToCart function was
reproduced locally before changing it: two different service intentions became
one unlabelled quantity-2 row.

A single bill now supports any two or all three portions: Serve Here, Parcel,
and Delivery. The cashier chooses how newly added items will be served, or
clicks a line's service badge to split its unsent quantity. The portions must
be nonnegative, have at most two decimal places and sum exactly to the line's
quantity. A portion already sent to the kitchen cannot be silently relabelled.

Each portion retains a stable line identity alongside its service instruction.
The product/deal identity, choices, notes, quantities, line discount, unit price,
tax treatment and table/fiscal bill type keep their existing roles. Free-text
notes remain separate. Delivery charges remain one verified fee line excluded
from kitchen preparation. No company/device identity is hard-coded.

## Kitchen, recall and payment

The first kitchen ticket shows SERVE HERE, PACK / TAKEAWAY and PACK / DELIVERY
beside the relevant lines. Recall combines only matching identity/service/note/
price/discount chunks. Re-holding an increased portion carries its previous
printed quantity and creates only the additional unprinted quantity. Historical
reprints retain their original snapshots. Bill settlement does not prepare old
food again. Stale/foreign recalled-order IDs fail instead of opening a new bill.

Delivery portions use one destination per bill and retain the selected customer,
phone and address. They appear in the delivery board and rider payload even
while the bill stays Dine In and retains its table. Delivery feature/package
gates still apply. This does not introduce multiple delivery addresses.

The owner explicitly requested both payment choices:

- Paid at counter: the whole invoice is collected at the counter; rider debt is
  zero even when a rider is assigned for transport.
- Rider collection: the counter receives Serve Here/Parcel and the rider receives
  only the Delivery share plus the verified delivery fee. Card payments create
  no rider cash debt.

The existing invoice total is partitioned using final net line weights, existing
bill discount and applicable tax/exemption. Whole-invoice rounding is allocated
proportionally to delivery; counter cash is the exact residual. The two amounts
sum to the unchanged invoice total. Example: total400, delivery100 -> counter300
and rider100; counter-paid -> counter400 and rider0. Partial rider settlements,
khata, cash drawer and alerts use the remaining delivery debt. Old NULL collection
snapshots retain legacy whole-bill debt. A mixed-delivery return requires a
credit note for the delivery lines before a single or bulk Returned action can
drop its debt; served/collected food is never automatically refunded in full.

The print/preview retains per-line labels and counter/rider amounts. The success
popup uses the server's counter change amount rather than subtracting the whole
invoice from counter cash. Lost-response replays carry the same allocation.

## Compatibility boundary

Mixed service requires an online save/payment in this release. The browser
retains the cart and clearly refuses old Local Core/offline paths that cannot
preserve these instructions. HTTP offline replay and the restaurant projector
also reject new mixed-service input rather than stripping its labels. Existing
single-service/legacy offline flows keep their contract. No Agent release or
physical-printer acceptance is claimed by this web feature.

Nullable fields preserve existing order/bill meaning. Restaurant and transaction
lines carry fulfillment/line_uid; the held order carries collection mode; the
settled bill carries has_delivery_items and nullable rider_collection_amount.
No historical bill backfill or tax/fiscal identity rewrite is performed.

## Local acceptance evidence

Disposable fictional company only, loopback Chrome, no production credentials,
customer data, real printer or external service calls.

- Original addToCart merging reproduced before the fix.
- Supported PHP8.4.27 targeted suite: 71 tests /392 assertions passed, including
  initial three portions, held retry identity, later parcel-only delta, sent
  relabel refusal, legacy printed-order upgrade, stale recall refusal, contact /
  UID /offline validation, delivery fee copy, both cash choices, partial rider
  settlements, counter attribution/tenant isolation and single/bulk return safety.
- Production-function Node tests: 22 passed, including exact split, notes and
  discount preservation, sent quantity safety, matching-identity recall and
  the mixed Dine In address-loading barrier.
- Actual Chrome journeys at1366 and390: both counter/rider choices passed real
  product selection, three-way split, hold, kitchen-ticket render with print
  stamps, reload/table recall, parcel quantity increment, delta render, payment
  and saved receipt/contact/collection snapshots. Page errors were empty.
- An actual pointer failure exposed controls/customer details hiding cart rows
  behind the payment dock. Variable-height details now share the cart scroller;
  the test uses normal pointer actions, without forced clicks.
- Announcement spec parser and dry run:12 points, POS audience,7 food presets.
  The customer wording explains every choice in ordinary Roman Urdu, with a
  pizza example and both payment examples. No release notification is inserted
  from this local environment.

These local browser runs use a disposable fully migrated SQLite application.
They are not MariaDB acceptance. The mandatory GitHub Actions lanes must prove
MariaDB10.6, full browser/role/fiscal compatibility, assets/audit, full PHPUnit
and owner-promotion checks on the published exact HEAD before owner approval.
Do not reuse old green checks. Any final check links/counts belong in the PR
body so adding verification metadata does not itself replace the tested HEAD.

## Release boundary

Base is the owner-released274 main commit8f3a5db2b811710d05481bc21837611f955fdcd5.
Its deploy40055067843 passed exact-SHA extended live verify and successful
outcome callback. This only proves the prerequisite, not272 deployment.
After272's own fresh checks, STOP for separate owner admin-panel approval and
Approval Relay. No merge, deployment, Windows release or live company smoke is
authorized to this agent.
