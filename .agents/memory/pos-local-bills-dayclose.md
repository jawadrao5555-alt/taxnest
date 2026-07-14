---
name: POS local bills, day-close purge & auto-close
description: Retention rules, cashier-gate trap, and midnight auto-close semantics for NestPOS local/provisional bills
---

# NestPOS Local Bills / Day-Close

## Retention — POLICY-DRIVEN since Jul 2026 (F1; REVERSED older "archive-only" rule)
- Manual delete (F10 Local modal / deleteTransaction / apiDeleteProvisional) = PERMANENT hard-delete (unchanged).
- Day-close wash (manual + midnight auto) now follows the STANDING company policy from Customize POS → Local Billing: provisionals (local+local triple) and reporting-OFF finals (completed + pra/NULL mode + NULL status + no fiscal) EACH get 'save' (archive) or 'delete' (permanent, items+payments too). Defaults 'save'.
- Delete + `pos_customer_spend_persist` ON → write `pos_customer_spend_snapshots` ledger rows FIRST (customer-linked bills only); customer history/CSV/PDF merge archived bills + snapshot pseudo-rows via `customerHistoryTransactions()` ("Local · record" tag). Persist OFF = classic live-only view.
- **Why:** owner (Jul 2026) wants shops to choose whether un-reported bills survive day-close at all; snapshots keep customer spend totals honest even after deletes.
- **Quota-bypass trap (architect-caught):** hard-deleted reporting-OFF finals vanish from PlanLimitService's live monthly count — per-report `deleted_final_count`/`deleted_provisional_count` are persisted and canCreatePosBill ADDS current-month deleted finals back (try/catch for pre-migration prod). Z-report PDF prints the deleted counts (stored header totals include deleted finals; itemized list can't). Any future wash/delete path must keep these counters honest.

## Wash query invariant (updated Jul 2026)
- The two wash selectors are DISJOINT and BOTH require `pra_invoice_number IS NULL` + `is_archived=false`: provisionals = `invoice_mode='local' AND pra_status='local'`; finals = `status='completed' AND (invoice_mode='pra' OR NULL) AND pra_status IS NULL`. Any bill with a non-NULL pra_status (pending/submitted/completed/failed/offline) or a fiscal number is in the PRA pipeline — NEVER touched. The finals selector MUST keep `status='completed'` (drafts exist in pos_transactions).

## Local Invoices report tab & promote month-gate — Jul 2026
- Sales Reports + Tax Reports have an ADMIN-ONLY "Local Invoices" tab (`?tab=local`), fully isolated from the PRA tab via `applyReportFilters()`. Tab split = ACTUAL PRA reporting (owner rule: "jis pe PRA fiscal nahi aya wo local hai"), NOT just invoice_mode: PRA tab = pra_status NOT NULL OR pra_invoice_number present (pending/completed/failed/offline pipeline); local tab = `invoice_mode='local'` PLUS reporting-OFF finals (pra/NULL mode + NULL status + no fiscal), bypassing `hide_archived`. ALL five entry points (reports, reports CSV, taxReports, tax CSV, tax PDF) force `tab='pra'` for non-admins server-side; the tab is also hidden in mode-tabs for cashiers. Reporting-OFF finals get a "Final — reporting OFF" pill in the list, never a Submit button (promote endpoint rejects non-'local' triples anyway).
- **generateInvoiceNumber must order by NUMERIC serial, not id:** promote renumbers an OLD row (low id) to the newest POS serial — `orderBy('id','desc')` then reads a stale max from the latest-inserted row and hands out a DUPLICATE serial. Uses `SUBSTR(invoice_number, 10)` cast via DbCompat. Never revert to id-ordering.
- **Promote month gate (owner rule):** only CURRENT-calendar-month local bills may be promoted to PRA — previous months are CLOSED (view/report only). Enforced in BOTH promote paths (apiPromoteProvisional 422 MONTH_CLOSED + retryPra back-error), not just hidden in UI.
- **Serial split (owner rule Jul 2026): POS fiscal serials ONLY for PRA-reported bills.** Provisionals AND reporting-OFF finals draw from the L-series on every create path (storeInvoice, restaurant payOrder); a reporting-OFF finalize/promote KEEPS its L number — only a promote that actually goes to PRA ('pending') renumbers to POS-YYYY-NNNNN. Never let a bill PRA won't see consume the fiscal sequence.
- **Toggle-flip serial re-resolution:** draft-resume (storeInvoice resume branch) and updateTransaction both re-resolve the serial when the reporting toggle changed since the bill was numbered — an L-series bill going to PRA gets a fresh POS serial FIRST (PRA must never receive an L-NNN USIN); a POS-serial bill finalized/edited reporting-OFF keeps its number (never renumber downward). Any new path that flips a bill's PRA destiny must do the same.
- **generateLocalInvoiceNumber must ALSO order by numeric serial** (`SUBSTR(invoice_number, 3)` via DbCompat, both PosController + RestaurantPosController copies): the POS→L downgrade path can put the max L number on an OLD row — id-ordering would re-issue it and trip UNIQUE(company_id, invoice_number). Same lesson as the fiscal generator.
- **PRA-bound promote renumbers + un-archives:** a local→PRA promotion allots a fresh POS-YYYY-NNNNN serial (leaves the L-series) and sets `is_archived=false`. retryPra's local branch goes through the race-safe `promoteLocalToPosSerial()` helper (lock + re-verify triple inside the txn) — do NOT revert it to a bare `update()` (double-POST would burn/clobber serials).
- **Archived local bills promotable by ADMINS only** (apiPromoteProvisional 403 ARCHIVED_ADMIN_ONLY for cashiers) — a cashier must not resurrect a bill the owner deliberately finalized as LOCAL.
- Promote consumes monthly bill quota (pre-existing gate) — owner confirmed this is intended.

## Local Final (promote without PRA) — Jul 2026
- Promote modal offers "Finalize LOCAL" (send_to_pra=false): bill keeps its local triple + local number/amounts, is `is_archived=true` on the spot → leaves F10, stays in Local Bills Portal. `archived_by_report_id` stays NULL — nothing depends on it; Archive Portal filters mode to pra/NULL so local finals never leak there.
- `receipt()` bypasses hide_archived ONLY for `invoice_mode='local'` bills (post-finalize popup needs it); archived PRA bills stay hidden.
- Company admins (isPosAdmin) may VIEW /pos/local-bills alongside local_viewer accounts (owner request Jul 2026); cashiers still 404.

## Toggle B (RETIRED) / Toggle C
- Toggle B (`companies.pos_auto_purge_local_on_dayclose`) is RETIRED since the F1 policy wash — column + toggleAutoPurgeLocal route left in place (dead) but performDayClose no longer reads it and the Customize card is gone. Do NOT re-add a per-close purge checkbox; the wash is unconditional + policy-driven.
- Toggle C = `companies.pos_auto_dayclose_24h` (column name kept for stability; behavior is NO LONGER 24h-inactivity). The `pos:auto-dayclose` command (hourly, withoutOverlapping) is MIDNIGHT-BASED with a 1-full-day grace: it sweeps un-closed days whose calendar date is `< today()->subDay()` (i.e. older than yesterday) — so Monday's day auto-closes at Wednesday 00:00, NOT last-bill+24h. App tz = Asia/Karachi so "midnight" = Pakistan midnight. Yesterday is deliberately left open (grace). It calls performDayClose, so the same policy wash applies.
- **Why:** owner (Jul 2026) wanted auto-close tied to the calendar/midnight with one grace day, not to inactivity since the last sale. Do NOT re-add the `havingRaw('MAX(created_at) <= cutoff')` inactivity filter.

## Cashier-gate trap (IMPORTANT)
- Every POS `/pos/settings/*` POST endpoint MUST carry the `isPosCashier() → 403` gate. The Customize page being admin-only is NOT enough — the POST routes are directly reachable by any authenticated POS user.
- **Bypass proven:** a cashier flips Toggle B via the raw POST, then triggers a normal day-close; `closeDayReport` ORs the company flag and the purge fires under cashier authority, defeating the explicit "cashier can't purge" guard. New settings endpoints silently miss this because siblings already have it.

## performDayClose in command context
- `performDayClose($companyId,$date,$closedBy,$notes)` takes companyId explicitly → safe from non-HTTP (no `currentCompanyId` binding). PosTransaction has ONLY the `hide_archived` global scope (no company scope); PosController has no constructor, so command method-injection resolves it fine. Double-close race is closed by the unique index on `pos_day_close_reports(company_id, report_date)`.

## NTN optional
- NTN is nullable at POS registration; CredentialLedgerService is null-safe (normalize→null skips it in firstUsed/record). NTN becomes mandatory ONLY once PRA is used: `togglePra` (turning ON) + `enablePraIntegration` both 422/redirect on empty NTN, and `businessProfile` blocks CLEARING ntn while reporting is active for ANY account (`Company::praReportingActive()` — company flag OR any user's per-cashier toggle), else submissions would carry a null NTN.

## Per-cashier PRA Reporting toggle — Jul 2026
- `users.pra_reporting_enabled` nullable: NULL = inherit company flag, non-NULL = the user's OWN switch. togglePra flips ONLY the acting user's row — company flag untouched, so one cashier never affects another.
- Acting-user decisions (sale/draft/edit/promote/retry gates + all POS views) read `User::praReportingEnabled($company)`; user-less contexts (offline sync job, `PraIntegrationService::isEnabled`, NTN guard) use `Company::praReportingActive()`.
- **Why NULL-inherit:** zero behavior change on deploy day (no seeding), and pre-migration prod safely falls back to the company flag (getAttributeValue → null; praReportingActive try/catch).
