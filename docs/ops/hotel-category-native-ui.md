# Hotel / Guest House category-native UI (not live)

Owner-facing notes for the Hotel Management chrome shipped on
`cursor/hotel-category-native-ui-v2`. This file is **not** a live announcement
and does not publish What's New.

## What staff see (when category is Hotel / Guest House and Rooms is ON)

- Login opens **Front Desk**, not New Sale (housekeeping-only staff open the housekeeping board).
- Primary actions: New Booking, Walk-in, Check-in, Check-out, Add Charge, Receive Payment, Housekeeping.
- Navigation: Front Desk, Reservations, Rooms, Housekeeping, Guests, Folios/Payments, Hotel Reports.
- Generic **New Sale** is hidden unless this property already has kitchen/restaurant mode saved.
- Room board uses vacant / occupied / reserved / dirty / out-of-service colours plus filters.
- Stay screen shows readable status, folio tiles, fiscal bill link, and a stay timeline.

## What does not change

Stay engine, folio math, tax, invoice numbers, idempotency, checkout outstanding
policy, occupancy pending-due counts, tenant/branch isolation, and other
categories. Existing companies keep saved modules, permissions, rooms, extras,
units, tax and historical documents.

## Field training (short)

1. Front desk lives on occupancy + room board — bills still issue from the stay folio.
2. Walk-in checks the guest in tonight; New Booking creates a reservation.
3. Housekeeping only sees rooms; they cannot open folios.
4. Outstanding checkout still follows the saved allow/block rule.

Rollback: revert the PR. No data migration rewrites company settings.
