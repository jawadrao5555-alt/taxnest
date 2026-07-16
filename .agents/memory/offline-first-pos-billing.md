---
name: Offline-first POS billing dedupe
description: PRA POS offline queue design — offline_uuid must ride on EVERY submit attempt (online too) or lost-response duplicates occur.
---

# Offline-first PRA POS billing (universal sale screen)

**Rule:** The dedupe key `offline_uuid` is generated ONCE per bill at the top of the payload build in `processPaymentManual` and rides on EVERY attempt — including the normal online POST. `queueOfflineBill` must REUSE `payload.offline_uuid`, never mint a fresh one.

**Why:** The classic flaky-WiFi failure is a lost RESPONSE: server committed the bill but the reply never arrived. If the online attempt carried no uuid, the queued retry gets a fresh uuid, the replay guard can't match, and the sync engine creates a duplicate final bill (double quota, double PRA submission, two fiscal serials).

**How to apply:**
- Server: `storeInvoice` replay guard = company-scoped lookup on `(company_id, offline_uuid)` with `withoutGlobalScope('hide_archived')` + `Schema::hasColumn` guard, placed AFTER validation but BEFORE card-normalize and quota — replays never re-charge quota; returns same success JSON + `replayed:true`. Unique index `pos_txn_offline_uuid_unique` is the safety net; concurrent two-tab drain self-heals (loser 500s, next cycle hits replay lookup, deletes from IDB).
- Client: IndexedDB `tn_pos_offline`, records company-scoped via server-rendered `_offlineCompanyId`; drain oldest-first; 419/401 stops drain (offlineNeedsLogin), 403 quota breaks, other errors store tries/last_error.
- Offline bills never settle waiter orders; offline receipt = grand TOTAL only (strictest show-tax-OFF rule); serials allotted server-side only at sync time.

**Known accepted gaps (owner not yet consulted):** no tries cap / device-queue management UI (poison 422 bill retries forever, badge inflated); bill timestamp = sync time, not queued_at (evening bill syncing next morning lands on wrong fiscal day for day-close/Z-report/quota month). Raise before or shortly after production rollout.

## IMS contact is OPTIONAL (owner rule Jul 2026)
- Transport-level agent failures (IMS Fiscal Device service down on localhost:8524, no internet, DNS/timeout) must mark bills `'offline'` — NEVER `'failed'`. Only genuine regulator rejections (HTTP response received, non-100 code) become `'failed'`.
- **Why:** owner requires billing to never depend on IMS availability; 'failed' alarms cashiers and reads as "IMS compulsory". 'offline' rows keep auto-retrying (agent pending query + heartbeat repromote + 2-min cloud job) and are still visible in the F11 modal/badge.
- **How to apply:** agent sends `offline:true` on transport errors; server `AgentController::isTransportError()` pattern-rescues OLD installed agents (their messages contain raw axios codes / "localhost:8524 unreachable"). Applies to BOTH PRA and FBR submit-result branches.
- SyncPosOfflineInvoicesJob skips companies whose agent was seen <10 min ago (agent-first, closes double-submit window); stale/absent agent → job still rescues.
