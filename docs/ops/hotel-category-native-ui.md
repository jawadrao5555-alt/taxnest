# Hotel / Guest House category-native UI (not live)

Owner-facing notes for the Hotel Management chrome. This file is **not** a live
announcement and does not publish What's New.

## What staff see (when category is Hotel / Guest House and Rooms is ON)

- Login and Admin **Manage as Company** open **Front Desk** (`/pos/hotel`), not
  New Sale or the generic POS dashboard (housekeeping-only staff open the
  housekeeping board).
- Primary Hotel shell: Front Desk, Reservations, Rooms, Housekeeping, Guests,
  Folios/Payments, Hotel Reports.
- Primary actions: New Booking, Walk-in, Check-in, Check-out, Add Charge,
  Receive Payment, Housekeeping.
- Generic **New Sale** is never the main Hotel action — even when this property
  already saved `restaurant_mode` ON.
- If `restaurant_mode` is ON, a separated **Restaurant Outlet** entry appears
  inside the Hotel shell. It opens the existing NestPOS sale/restaurant engine
  with a clear **Back to Front Desk** action. Direct sale URLs stay permission-
  gated; when `restaurant_mode` is OFF, outlet navigation and those sale URLs
  are denied for the Hotel company.
- Stay folio pickers label products as Minibar / Room Service / Stay Extras and
  services as Stay Services / Charges (catalog rows are not renamed or reset).
- Room board uses vacant / occupied / reserved / dirty / out-of-service colours
  plus filters.
- Stay screen shows readable status, folio tiles, fiscal bill link, and a stay
  timeline.

## What does not change

Stay engine, folio math, tax, invoice numbers, idempotency, checkout outstanding
policy, occupancy pending-due counts, tenant/branch isolation, and other
categories. Existing companies keep saved modules, `restaurant_mode`, kitchen
settings, permissions, rooms, products/services, stock, units, tax and
historical documents. No global backfill rewrites company settings.

## Field training (short)

1. Front desk lives on occupancy + room board — bills still issue from the stay folio.
2. Walk-in checks the guest in tonight; New Booking creates a reservation.
3. Housekeeping only sees rooms; they cannot open folios or Restaurant Outlet.
4. Kitchen/counter sale (when already enabled) is Restaurant Outlet, not Front Desk.
5. Outstanding checkout still follows the saved allow/block rule.

Rollback: revert the PR. No data migration rewrites company settings.
