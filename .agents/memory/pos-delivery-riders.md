---
name: PRA POS Delivery Riders
description: Rider CRUD/khata/settlement invariants — khata definition, confinement, day-close recon + wash guard, what riders must never touch
---

# Delivery Riders (PRA POS restaurant module)

## Khata definition (the ONE filter — reuse, never re-derive)
Open cash khata = `payment_method='cash'` + `rider_settlement_id IS NULL` + `delivery_status != 'returned'` (NULL passes), ALWAYS `withoutGlobalScope('hide_archived')` — day-close archives bills still out with a rider, khata must survive archive. Canonical source: `PosRider::openCashBills()`.

## Invariants
- Rider paths NEVER touch `invoice_mode` / `pra_status` / serials / totals. Billing snapshots only add `order_type`, `rider_id`, `delivery_status` via array-union `+` (validated company-scoped + is_active, invalid ids silently dropped).
- 'returned' = khata drop ONLY — never voids/reverses a PRA-submitted bill.
- Settled bills are LOCKED: assign + status changes reject when `rider_settlement_id` set.
- **Terminal-state rider lock (owner, Jul 2026)**: rider is ALSO locked once `delivery_status` in delivered/returned — blade renders plain text (no select) AND `assign()` rejects (reassigning would silently move the cash khata to a rider who never carried the order). Reassign stays OPEN while assigned/dispatched — rider suddenly leaves → swap rider, khata follows `rider_id` live. `updateStatus()` mirrors the guard: returned = final; delivered can ONLY move → returned (else a raw POST could flip delivered→dispatched and re-open assign).
- `settle()` = DB::transaction + lockForUpdate + re-verifies each bill open-cash-for-THIS-rider (no double settle). Partial settle = checkbox bill selection.
- Rider logins: `users.pos_role='pos_rider'`, EXCLUDED from `PlanLimitService::canAddPosUser` count AND team page lists. PosAuth confines pos_rider to exact `pos/rider` + `pos/rider/` prefix (`/pos/riders` stays admin) — everything else redirects to portal. Portal writes = mark OWN assigned/dispatched bill delivered ONLY (double-scoped rider_id + user_id).
- Rider CRUD + login mgmt admin/manager only; deliveries board + settle open to cashiers (cashier receives the cash).

## Day-close integration
- `buildRiderDayFigures()` schema-guarded (try/catch + hasColumn) — safe pre-migration on PROD.
- Recon expected cash = float + cash − cash_out + cash_in. cash_out = TODAY's PRA-set unsettled rider cash; cash_in = today's settlements of EARLIER-day PRA-set bills. PRA-set-only is INTENTIONAL (consistency with stored cash_amount) — local provisionals excluded from both sides.
- Wash DELETE-guard: unsettled cash rider bills are ARCHIVED (never deleted) even under 'delete' policy + `localSummary[kind]['rider_guarded']` count. Settled/returned/non-cash wash normally.
- `rider_summary` (TEXT json) persisted on pos_day_close_reports via guarded forceFill; shown on day-close page + PDF + thermal.

## UX rules learned
- Deliveries board rider list = active riders + ANY inactive rider with open khata (else deactivation strands his cash — no settle button anywhere). Assign dropdown filters back to active-only in the blade.
- assign/updateStatus/settle deliberately NOT behind deliveryGate() — feature toggled OFF must not strand in-flight cash (architect finding accepted as intentional).
- Portal route is `pos/rider/deliveries/{id}/delivered` (not `/pos/rider/delivered/{id}`).
