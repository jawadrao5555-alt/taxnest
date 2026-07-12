---
name: PRA POS package limit enforcement
description: Monthly bill quota + team-account quota semantics for PRA POS paid plans; which write paths are gated and which are exempt.
---

# PRA POS package limits (Jul 2026 restructure)

**Rule:** POS paid plans store `invoice_limit` as bills-per-CALENDAR-MONTH (Starter 500, Business 2000, Pro -1) and `user_limit` as POS panel accounts (1/5/-1). Enforcement lives in `PlanLimitService::canCreatePosBill()` / `canAddPosUser()` — controller-level gates, NOT middleware (`plan.limit:invoices` middleware has no 'invoices' case; only its access gate runs).

**Quota counting:** FINAL bills only — `status='completed' AND (invoice_mode IS NULL OR != 'local')`, current calendar month, `withoutGlobalScope('hide_archived')` (archived finals still count). Provisionals are FREE until promoted.

**Gated final-creation paths (all four, keep in sync):**
1. `storeInvoice` (non-provisional only)
2. `apiPromoteProvisional` (send_to_pra=true branch only; local-final branch exempt — stays 'local', no quota)
3. `retryPra` ONLY when `pra_status==='local'` (promote). Plain retries of failed/offline/pending bills are NOT re-charged — they consumed quota at creation.
4. Restaurant `payOrder`

**Account quota:** counts active users with pos_role in (pos_admin, pos_cashier); local_viewer/archive_viewer exempt. Gate BOTH `storeCashier` AND `toggleCashier` reactivation (deactivate→create→reactivate was a bypass found by review).

**Why:** owner package restructure Jul 2026; limit-reached behavior = BLOCK new finals with upgrade message (mirrors DI), provisionals stay allowed so the shop never deadlocks mid-day.

**How to apply:** any NEW write path that creates or promotes a final POS bill must call `canCreatePosBill()` first; overrides (`invoice_limit_override`/`user_limit_override`) win over plan, -1 = unlimited; trial plans skip (20-bill total trial cap lives in SubscriptionAccessService). Promoted provisionals keep original created_at (prior-month promotes are effectively free — accepted).
