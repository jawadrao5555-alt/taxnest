---
name: PRA POS Opening Cash Balance
description: Day-start opening cash entry rules — today-only save, lock after day-close, auto-fill into day-close reconciliation on BOTH close paths.
---

# Opening Cash Balance (PRA POS, Jul 2026)

- Table `pos_day_openings`: ONE row per company per `business_date` (unique pair), `opening_cash` + `entered_by` + `notes`. Model `PosDayOpening::forDate($companyId, $date)` is `Schema::hasTable`-guarded (prod deploy-before-migrate safe).
- Save = POST `/pos/day-opening` (`PosController::saveDayOpening`): **TODAY-only** — the controller ignores any posted date (a raw POST must not seed arbitrary days). Cashiers ARE allowed (day-start is their job). Upsert via `updateOrCreate`.
- **Lock rule:** once a `pos_day_close_reports` row exists for that date, opening cash can NEVER be saved/changed for it (Z-report is immutable). Dashboard card mirrors this with a locked state — the locked state MUST render a visible note even when no opening row exists (an empty render caused a full deploy-gap misdiagnosis once).
- **Day-close auto-fill (BOTH paths):** `closeDayReport` (manual) AND `performDayClose` (midnight auto-close) fall back to the recorded opening when `opening_float` isn't posted — so the auto-close path still captures the opening. Recon merges BEFORE the report hash, so integrity covers it.
- Variance math: `counted_cash` NULL = "— (nahin gina gaya)" + NO variance row; counted `0` is a real count (Laravel `filled('0')` is true). expected = opening + cash sales. Gating `counted_cash !== null || opening_float !== null` is mirrored across day-close page, PDF, and thermal — keep all three in sync.
- Closed-day page hides the recon block when the day's bills are all archived (pre-existing `stats>0` gating) — ACCEPTED; recon stays visible on PDF + thermal Z-report.
- After-midnight gotcha: a manual close at e.g. 1 AM closes the NEW calendar date, locking that whole day's opening entry — pre-existing day-close date semantics, not a bug.
