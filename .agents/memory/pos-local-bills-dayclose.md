---
name: POS local bills, day-close purge & auto-close
description: Retention rules, cashier-gate trap, and midnight auto-close semantics for NestPOS local/provisional bills
---

# NestPOS Local Bills / Day-Close

## Retention (owner-approved)
- Manual delete (F10 Local modal / deleteTransaction / apiDeleteProvisional) = PERMANENT hard-delete.
- Day-close purge AND the auto-close = ARCHIVE (is_archived=true), recoverable via Archive Portal. NEVER hard-delete on any day-close path.
- **Why:** owner wants the day-close cleanup to be safe/reversible, but a deliberate cashier/admin delete to be final.

## Purge query invariant
- Day-close "purge local bills" must target ONLY `invoice_mode='local' AND pra_status='local'` (provisionals). Reporting-OFF finals are `mode + NULL` and must NEVER be swept. A looser query silently deletes/archives real finals.

## Local Invoices report tab & promote month-gate — Jul 2026
- Sales Reports + Tax Reports have an ADMIN-ONLY "Local Invoices" tab (`?tab=local`), fully isolated from the PRA tab via `applyReportFilters()` — local tab bypasses `hide_archived` + filters `invoice_mode='local'`; PRA tab stays pra/NULL. ALL five entry points (reports, reports CSV, taxReports, tax CSV, tax PDF) force `tab='pra'` for non-admins server-side; the tab is also hidden in mode-tabs for cashiers.
- **Promote month gate (owner rule):** only CURRENT-calendar-month local bills may be promoted to PRA — previous months are CLOSED (view/report only). Enforced in BOTH promote paths (apiPromoteProvisional 422 MONTH_CLOSED + retryPra back-error), not just hidden in UI.
- **Promote always renumbers + un-archives:** every local→final promotion allots a fresh POS-YYYY-NNNNN serial (leaves the L-series) and sets `is_archived=false`. retryPra's local branch goes through the race-safe `promoteLocalToPosSerial()` helper (lock + re-verify triple inside the txn) — do NOT revert it to a bare `update()` (double-POST would burn/clobber serials).
- **Archived local bills promotable by ADMINS only** (apiPromoteProvisional 403 ARCHIVED_ADMIN_ONLY for cashiers) — a cashier must not resurrect a bill the owner deliberately finalized as LOCAL.
- Promote consumes monthly bill quota (pre-existing gate) — owner confirmed this is intended.

## Local Final (promote without PRA) — Jul 2026
- Promote modal offers "Finalize LOCAL" (send_to_pra=false): bill keeps its local triple + local number/amounts, is `is_archived=true` on the spot → leaves F10, stays in Local Bills Portal. `archived_by_report_id` stays NULL — nothing depends on it; Archive Portal filters mode to pra/NULL so local finals never leak there.
- `receipt()` bypasses hide_archived ONLY for `invoice_mode='local'` bills (post-finalize popup needs it); archived PRA bills stay hidden.
- Company admins (isPosAdmin) may VIEW /pos/local-bills alongside local_viewer accounts (owner request Jul 2026); cashiers still 404.

## Toggle B / Toggle C
- Toggle B = `companies.pos_auto_purge_local_on_dayclose` (archive locals on every day-close). Effective purge = `requestedPurge || company.toggleB`. A cashier-requested manual purge is rejected, but the company policy still applies regardless of who closes.
- Toggle C = `companies.pos_auto_dayclose_24h` (column name kept for stability; behavior is NO LONGER 24h-inactivity). The `pos:auto-dayclose` command (hourly, withoutOverlapping) is MIDNIGHT-BASED with a 1-full-day grace: it sweeps un-closed days whose calendar date is `< today()->subDay()` (i.e. older than yesterday) — so Monday's day auto-closes at Wednesday 00:00, NOT last-bill+24h. App tz = Asia/Karachi so "midnight" = Pakistan midnight. Yesterday is deliberately left open (grace). It calls performDayClose and its purge follows Toggle B (NOT forced on).
- **Why:** owner (Jul 2026) wanted auto-close tied to the calendar/midnight with one grace day, not to inactivity since the last sale. Do NOT re-add the `havingRaw('MAX(created_at) <= cutoff')` inactivity filter.

## Cashier-gate trap (IMPORTANT)
- Every POS `/pos/settings/*` POST endpoint MUST carry the `isPosCashier() → 403` gate. The Customize page being admin-only is NOT enough — the POST routes are directly reachable by any authenticated POS user.
- **Bypass proven:** a cashier flips Toggle B via the raw POST, then triggers a normal day-close; `closeDayReport` ORs the company flag and the purge fires under cashier authority, defeating the explicit "cashier can't purge" guard. New settings endpoints silently miss this because siblings already have it.

## performDayClose in command context
- `performDayClose($companyId,$date,$closedBy,$purge,$notes)` takes companyId explicitly → safe from non-HTTP (no `currentCompanyId` binding). PosTransaction has ONLY the `hide_archived` global scope (no company scope); PosController has no constructor, so command method-injection resolves it fine. Double-close race is closed by the unique index on `pos_day_close_reports(company_id, report_date)`.

## NTN optional
- NTN is nullable at POS registration; CredentialLedgerService is null-safe (normalize→null skips it in firstUsed/record). NTN becomes mandatory ONLY once PRA is used: `togglePra` (turning ON) + `enablePraIntegration` both 422/redirect on empty NTN, and `businessProfile` blocks CLEARING ntn while `pra_reporting_enabled` is true (else submissions would carry a null NTN).
