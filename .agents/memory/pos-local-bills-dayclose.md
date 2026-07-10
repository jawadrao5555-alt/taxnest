---
name: POS local bills, day-close purge & 24h auto-close
description: Retention rules, cashier-gate trap, and 24h auto-close semantics for NestPOS local/provisional bills
---

# NestPOS Local Bills / Day-Close

## Retention (owner-approved)
- Manual delete (F10 Local modal / deleteTransaction / apiDeleteProvisional) = PERMANENT hard-delete.
- Day-close purge AND the 24h auto-close = ARCHIVE (is_archived=true), recoverable via Archive Portal. NEVER hard-delete on any day-close path.
- **Why:** owner wants the day-close cleanup to be safe/reversible, but a deliberate cashier/admin delete to be final.

## Purge query invariant
- Day-close "purge local bills" must target ONLY `invoice_mode='local' AND pra_status='local'` (provisionals). Reporting-OFF finals are `mode + NULL` and must NEVER be swept. A looser query silently deletes/archives real finals.

## Toggle B / Toggle C
- Toggle B = `companies.pos_auto_purge_local_on_dayclose` (archive locals on every day-close). Effective purge = `requestedPurge || company.toggleB`. A cashier-requested manual purge is rejected, but the company policy still applies regardless of who closes.
- Toggle C = `companies.pos_auto_dayclose_24h`. The `pos:auto-dayclose` command (hourly, withoutOverlapping) closes prior un-closed days once `MAX(created_at) <= 24h ago`; it calls performDayClose and its purge follows Toggle B (NOT forced on).

## Cashier-gate trap (IMPORTANT)
- Every POS `/pos/settings/*` POST endpoint MUST carry the `isPosCashier() → 403` gate. The Customize page being admin-only is NOT enough — the POST routes are directly reachable by any authenticated POS user.
- **Bypass proven:** a cashier flips Toggle B via the raw POST, then triggers a normal day-close; `closeDayReport` ORs the company flag and the purge fires under cashier authority, defeating the explicit "cashier can't purge" guard. New settings endpoints silently miss this because siblings already have it.

## performDayClose in command context
- `performDayClose($companyId,$date,$closedBy,$purge,$notes)` takes companyId explicitly → safe from non-HTTP (no `currentCompanyId` binding). PosTransaction has ONLY the `hide_archived` global scope (no company scope); PosController has no constructor, so command method-injection resolves it fine. Double-close race is closed by the unique index on `pos_day_close_reports(company_id, report_date)`.

## NTN optional
- NTN is nullable at POS registration; CredentialLedgerService is null-safe (normalize→null skips it in firstUsed/record). NTN becomes mandatory ONLY once PRA is used: `togglePra` (turning ON) + `enablePraIntegration` both 422/redirect on empty NTN, and `businessProfile` blocks CLEARING ntn while `pra_reporting_enabled` is true (else submissions would carry a null NTN).
