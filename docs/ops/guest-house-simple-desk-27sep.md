# Guest House simple desk — PR 124

## Approved scope

One focused PR covers the reference room dashboard, shared role-aware sidebar,
room/guest search, upcoming/current/completed stays, editable agreed nightly
rate, room-only fixed/percentage discount, optional advance at check-in, focused
stay actions, change previews, and guided checkout with receipt links.

## Money contract

- Room master remains unchanged; each stay snapshots its standard and agreed rate.
- Folio `gross_amount` / `discount_amount` describe the agreed charge; existing
  `amount` stays NET. Invoice item discounts use canonical POS item-discount fields;
  header discount is zero to avoid deducting room discount from extra services.
- Both preview and fiscal invoice use saved net charges, configured method rates,
  and PosTaxMath inclusive/card-save behavior. UI sends no authoritative tax total.
- Fixed rupee discount applies once. Percentage applies to additional room nights.
- Room changes can reprice remaining unbilled dated room charges; past segments
  keep their amounts. Existing issued invoices cannot be repriced.
- Existing cashier percentage limit includes any negotiated-rate reduction.
  An ordinary move/extension keeps previously agreed pricing without requiring
  the cashier to re-approve a manager's discount; a new rate still enforces the cap.
- Checkout locks the stay, records payment and issues the covered bill in one
  transaction. Repeated completed checkout cannot take payment again. PRA handoff
  is deferred until commit. Deposits never become room revenue.
- Existing outstanding-checkout policy is retained and requires an explicit UI
  checkbox to leave a remaining balance in the new guided checkout.

## Compatibility / regression matrix

| Case | Required result |
|---|---|
| Old call with no pricing fields | Existing saved room rate; zero discount |
| New walk-in + advance retry | One stay, one advance |
| Amount / percent discount | Net amount, tax, invoice and preview agree |
| Inclusive / exclusive / card-save | Existing pricing-mode meaning retained |
| Extension | Fixed discount not repeated; percentage applies to added nights |
| Move to another room | Past nights preserved; future unbilled segments explicit |
| Frozen issued bill | Discount/rate revision rejected; ordinary move still possible |
| Cashier / housekeeping | Existing discount cap and front-desk access respected |
| Manager-negotiated existing rate | Cashier can move/extend at saved terms; further rate reduction still blocked |
| Other company/branch | Room, stay, quote and checkout inaccessible |
| Checkout retry / failure | No duplicate payment, no partial financial write |
| Desktop / 390px mobile | Reference room flow to edited rate, advance, checkout, receipt |

## Verification in this workspace

- PHP, Composer and vendor are absent. Attempted apt bootstrap failed with
  setgroups/seteuid permission errors. PHPUnit/MariaDB/actual Chrome app QA cannot
  run here; no claim of local PHP or rendered UI success.
- `node --test scripts/tests/hotel-desk-preview.test.mjs`: four behavioral tests
  for request races, invalid room state, failed quote and cash/card changes.
- Node syntax checks, diff whitespace, translation key order checks performed.
- New HotelSimpleDeskPricingTest covers money, idempotency, permissions and rendering.
- Mandatory isolated RC browser acceptance includes `hotel-simple-desk` at both
  desktop and mobile; the standalone local hotel smoke is updated too.

This document is implementation evidence, not a live verification claim.
