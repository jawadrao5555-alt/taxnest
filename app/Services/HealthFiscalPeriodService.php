<?php

namespace App\Services;

use App\Models\HealthAccountingSetting;
use App\Models\HealthFiscalPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting months, and the door that shuts on them (Task 1552).
 *
 * A period is created LAZILY, the first time anything is dated into it. Nobody
 * has to remember to "open next month" and no scheduled job can fail to.
 *
 * Closing is one-way on purpose. There is no reopen, anywhere, for anybody —
 * once a month is closed the statements built on it are out in the world, and
 * re-opening it would silently rewrite every one of them. A correction that
 * arrives afterwards posts as an ADJUSTMENT in the current open period, naming
 * the closed period it corrects, so both the frozen figure and the correction
 * survive and can be shown side by side.
 */
class HealthFiscalPeriodService
{
    /** The organisation's settings row, seeded lazily with the defaults. */
    public static function settings(int $companyId): ?HealthAccountingSetting
    {
        if (!Schema::hasTable('health_accounting_settings')) {
            return null;
        }

        $row = HealthAccountingSetting::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->first();

        if (!$row) {
            $row = HealthAccountingSetting::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                // Pakistan's tax year runs July–June, so that is the default
                // rather than the calendar year every foreign package assumes.
                'fiscal_year_start_month' => 7,
                'auto_post_enabled' => true,
                'doctor_share_basis' => HealthAccountingSetting::BASIS_BILLED,
                'doctor_shares_enabled' => true,
            ]);
        }

        return $row;
    }

    /** '2026-11' — the period name a date belongs to. */
    public static function nameFor(string $date): string
    {
        return Carbon::parse($date)->format('Y-m');
    }

    /**
     * The period a date falls in, created if it does not exist yet.
     */
    public static function ensureFor(int $companyId, string $date): ?HealthFiscalPeriod
    {
        if (!Schema::hasTable('health_fiscal_periods')) {
            return null;
        }

        $moment = Carbon::parse($date);
        $name = $moment->format('Y-m');

        $period = HealthFiscalPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->first();

        if ($period) {
            return $period;
        }

        try {
            return HealthFiscalPeriod::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'name' => $name,
                'starts_on' => $moment->copy()->startOfMonth()->toDateString(),
                'ends_on' => $moment->copy()->endOfMonth()->toDateString(),
                'status' => HealthFiscalPeriod::STATUS_OPEN,
            ]);
        } catch (\Throwable $e) {
            // A concurrent request created it first — the unique index doing
            // its job. Re-read rather than failing the posting behind it.
            return HealthFiscalPeriod::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('name', $name)
                ->first();
        }
    }

    /** TRUE when nothing new may be dated into this date's period. */
    public static function isClosed(int $companyId, string $date): bool
    {
        $period = self::ensureFor($companyId, $date);

        return $period ? $period->isClosed() : false;
    }

    /**
     * The earliest date a new entry may carry.
     *
     * Everything up to and including the last closed period is shut, so the
     * answer is the day after the newest closed period ends. NULL means nothing
     * has been closed yet and any date is acceptable.
     */
    public static function firstOpenDate(int $companyId): ?string
    {
        if (!Schema::hasTable('health_fiscal_periods')) {
            return null;
        }

        $lastClosed = HealthFiscalPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', HealthFiscalPeriod::STATUS_CLOSED)
            ->orderByDesc('ends_on')
            ->first();

        return $lastClosed ? Carbon::parse($lastClosed->ends_on)->addDay()->toDateString() : null;
    }

    /**
     * The period an adjustment should be posted into: today's, unless today's
     * is itself closed, in which case the first period that is not.
     */
    public static function currentOpen(int $companyId): ?HealthFiscalPeriod
    {
        $period = self::ensureFor($companyId, now()->toDateString());
        if ($period && $period->isOpen()) {
            return $period;
        }

        // Every period up to today is shut. Open next month so a correction
        // still has somewhere honest to land rather than being refused.
        return self::ensureFor($companyId, now()->addMonthNoOverflow()->startOfMonth()->toDateString());
    }

    /**
     * Close a period.
     *
     * Refuses while an EARLIER period is still open: closing November while
     * October is open would let October keep moving underneath November's
     * frozen snapshot, and the year-to-date figures on every later statement
     * would drift without anybody touching November.
     *
     * @return array{ok:bool,reason?:string,period?:HealthFiscalPeriod}
     */
    public static function close(HealthFiscalPeriod $period, $actor = null, string $note = ''): array
    {
        if ($period->isClosed()) {
            return ['ok' => false, 'reason' => 'already_closed'];
        }

        /*
         * Shutting a period and posting into it are the same fight over one
         * row, so both sides take the SAME lock, and this side takes it before
         * it decides anything. Everything below — the decision, the shut door
         * and the photograph of what was inside — is one transaction, because a
         * period that is closed but never photographed, or photographed but
         * left open, is worse than one that was never closed at all.
         */
        return DB::transaction(function () use ($period, $actor, $note) {
            $live = HealthFiscalPeriod::withoutGlobalScopes()
                ->where('id', $period->id)
                ->lockForUpdate()
                ->first();

            if (!$live) {
                return ['ok' => false, 'reason' => 'already_closed'];
            }

            // Read again now that nobody else can move: the button may have
            // been pressed twice, and a second close would overwrite the first
            // snapshot with a later one.
            if ($live->isClosed()) {
                return ['ok' => false, 'reason' => 'already_closed'];
            }

            $earlierOpen = HealthFiscalPeriod::withoutGlobalScopes()
                ->where('company_id', $live->company_id)
                ->where('status', HealthFiscalPeriod::STATUS_OPEN)
                ->whereDate('ends_on', '<', $live->starts_on)
                ->orderBy('starts_on')
                ->first();

            if ($earlierOpen) {
                return ['ok' => false, 'reason' => 'earlier_period_open', 'period' => $earlierOpen];
            }

            // Door first. Any entry still in flight is holding this same lock
            // and has therefore already landed; anything that starts after this
            // commits reads a closed period and is refused. Photographing the
            // room before locking the door is how a frozen statement ends up
            // missing an entry that lives inside the period forever.
            $live->forceFill([
                'status' => HealthFiscalPeriod::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $actor->id ?? null,
                'close_note' => $note !== '' ? mb_substr($note, 0, 500) : null,
            ])->save();

            // Freeze the trial balance as it stands. Adjustment journals dated
            // into later periods may still reference this one, so without the
            // snapshot "what did November look like" would keep changing answer.
            $snapshot = HealthAccountingReportService::trialBalance(
                (int) $live->company_id,
                $live->starts_on->toDateString(),
                $live->ends_on->toDateString()
            );

            $live->forceFill([
                'closing_snapshot' => [
                    'totals' => $snapshot['totals'] ?? [],
                    'rows' => array_map(fn ($r) => [
                        'account_id' => $r['account_id'],
                        'code' => $r['code'],
                        'debit' => $r['debit'],
                        'credit' => $r['credit'],
                    ], $snapshot['rows'] ?? []),
                    'frozen_at' => now()->toDateTimeString(),
                ],
            ])->save();

            // The caller is holding its own copy of this row; leaving it saying
            // "open" is how a screen reports a close that did happen as one
            // that did not.
            $period->setRawAttributes($live->getAttributes());
            $period->syncOriginal();

            return ['ok' => true, 'period' => $live];
        });
    }

    /** Periods newest first, for the close screen. */
    public static function recent(int $companyId, int $limit = 24)
    {
        if (!Schema::hasTable('health_fiscal_periods')) {
            return collect();
        }

        // Make sure the current month exists so the screen always has a row to
        // act on, even before anything has been posted.
        self::ensureFor($companyId, now()->toDateString());

        return HealthFiscalPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderByDesc('starts_on')
            ->limit($limit)
            ->get();
    }
}
