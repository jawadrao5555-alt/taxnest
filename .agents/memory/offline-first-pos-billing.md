---
name: Offline-first POS billing dedupe
description: PRA POS offline queue design — offline_uuid must ride on EVERY submit attempt (online too) or lost-response duplicates occur.
---

# Offline-first PRA POS billing (universal sale screen)

**Rule:** The dedupe key `offline_uuid` is generated ONCE per bill at the top of the payload build in `processPaymentManual` and rides on EVERY attempt — including the normal online POST. `queueOfflineBill` must REUSE `payload.offline_uuid`, never mint a fresh one.

**Why:** The classic flaky-WiFi failure is a lost RESPONSE: server committed the bill but the reply never arrived. If the online attempt carried no uuid, the queued retry gets a fresh uuid, the replay guard can't match, and the sync engine creates a duplicate final bill (double quota, double PRA submission, two fiscal serials). Architect review caught this exact window.

**How to apply:**
- Server: `storeInvoice` replay guard = company-scoped lookup on `(company_id, offline_uuid)` with `withoutGlobalScope('hide_archived')` + `Schema::hasColumn` guard, placed AFTER validation but BEFORE card-normalize and quota — replays never re-charge quota; returns same success JSON + `replayed:true`. Unique index `pos_txn_offline_uuid_unique` is the safety net; concurrent two-tab drain self-heals (loser 500s, next cycle hits replay lookup, deletes from IDB).
- Client: IndexedDB `tn_pos_offline`, records company-scoped via server-rendered `_offlineCompanyId`; drain oldest-first; 419/401 stops drain (offlineNeedsLogin), 403 quota breaks, other errors store tries/last_error.
- Offline bills never settle waiter orders; offline receipt = grand TOTAL only (strictest show-tax-OFF rule); serials allotted server-side only at sync time.

**Known accepted gaps (owner not yet consulted):** no tries cap / device-queue management UI (poison 422 bill retries forever, badge inflated); bill timestamp = sync time, not queued_at (evening bill syncing next morning lands on wrong fiscal day for day-close/Z-report/quota month). Raise before or shortly after production rollout.
