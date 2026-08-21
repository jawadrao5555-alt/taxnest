<?php

namespace App\Services;

use App\Models\PosCounterClose;
use App\Models\PosDayCloseReport;
use App\Models\PosDayOpening;
use App\Models\PosTerminal;
use App\Models\PosTransaction;
use App\Support\PosPaymentBuckets;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * PER-COUNTER CASH RECONCILIATION (Task 1375) — the single source of truth for
 * "kis counter par kitni cash honi chahiye".
 *
 * Counters already own every bill's attribution (Task 1349); this turns that
 * attribution into a drawer: each counter's own opening float, its own cash
 * sales, its own expected/counted/difference, and whether it has closed.
 *
 * INVARIANTS (why the numbers can be trusted):
 *  - The rows TILE the shop. Every bill lands in exactly one drawer — its
 *    counter, or drawer 0 ("no counter" / shop drawer) — so the rows' cash
 *    sales always sum to the shop's cash figure, never more or less.
 *  - Rider cash follows the BILL. Cash still out with a rider is subtracted
 *    from the counter that billed it; settlements received today for EARLIER
 *    days' bills belong to no counter, so they land on the shop drawer. Sum of
 *    expected = shop expected.
 *  - Counter-less shops get an EMPTY collection, so every day-close surface
 *    stays exactly as it was for them (no new card, no new rule).
 *  - Nothing here writes. Closing a counter writes one PosCounterClose row and
 *    touches no bill, which is what lets the other counters keep billing.
 */
final class PosCounterDrawer
{
    /** Bills that carry no counter reconcile against the shop drawer. */
    public const SHOP_DRAWER = 0;

    /** Are the counter tables/columns present on this box? (prod drift guard) */
    public static function ready(): bool
    {
        return Schema::hasTable('pos_terminals')
            && Schema::hasTable('pos_counter_closes')
            && Schema::hasColumn('pos_transactions', 'terminal_id');
    }

    /** Active counters of a company, keyed by id, in display order. */
    public static function counters(int $companyId): Collection
    {
        if (!Schema::hasTable('pos_terminals')) {
            return collect();
        }

        return PosTerminal::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('terminal_name')
            ->get(['id', 'terminal_name', 'terminal_code'])
            ->keyBy('id');
    }

    /**
     * Display names for EVERY counter (active or retired) — a counter switched
     * off mid-day still has today's bills against its name.
     */
    public static function names(int $companyId): Collection
    {
        if (!Schema::hasTable('pos_terminals')) {
            return collect();
        }

        return PosTerminal::where('company_id', $companyId)->pluck('terminal_name', 'id');
    }

    /** Does this shop run counters at all? Counter-less shops keep the old day close. */
    public static function enabled(int $companyId): bool
    {
        return self::ready() && self::counters($companyId)->isNotEmpty();
    }

    /** Today's close rows for a scope, keyed by terminal id. */
    public static function closes(int $companyId, ?int $branchId, string $date): Collection
    {
        if (!Schema::hasTable('pos_counter_closes')) {
            return collect();
        }

        return PosCounterClose::where('company_id', $companyId)
            ->forBranch($branchId)
            ->whereDate('business_date', $date)
            ->with('closedByUser:id,name')
            ->get()
            ->keyBy(fn ($r) => (int) $r->terminal_id);
    }

    /**
     * Is this counter already closed for the business date? Branch-agnostic on
     * purpose: counters are company-wide (pos_terminals carries no branch), so
     * one closed counter must not keep billing through another branch's view.
     */
    public static function isClosed(int $companyId, int $terminalId, string $date): bool
    {
        if (!Schema::hasTable('pos_counter_closes')) {
            return false;
        }

        return PosCounterClose::where('company_id', $companyId)
            ->where('terminal_id', $terminalId)
            ->whereDate('business_date', $date)
            ->exists();
    }

    /**
     * Cash still out with riders, split by the counter that BILLED it.
     * Mirrors PosController::buildRiderDayFigures' cash_out predicate exactly so
     * the drawer rows sum to the shop's rider adjustment.
     *
     * @return array<int,float> terminal id => rupees out
     */
    public static function riderCashOutByDrawer(int $companyId, ?int $branchId, string $date, ?int $onlyCreatedBy = null): array
    {
        if (!Schema::hasColumn('pos_transactions', 'rider_id') || !Schema::hasColumn('pos_transactions', 'terminal_id')) {
            return [];
        }

        try {
            $hasPartial = Schema::hasColumn('pos_transactions', 'rider_partial_paid');
            $bills = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('business_date', $date)
                ->whereNotNull('rider_id')
                ->when($onlyCreatedBy, fn ($q) => $q->where('created_by', $onlyCreatedBy))
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->when(!$branchId && Schema::hasColumn('pos_transactions', 'branch_id'),
                    fn ($q) => $q->whereNull('branch_id'))
                ->get();

            $out = [];
            foreach ($bills as $t) {
                $praSet = $t->invoice_mode === 'pra' || $t->invoice_mode === null;
                $openCash = $t->payment_method === PosPaymentBuckets::CASH
                    && !$t->rider_settlement_id
                    && $t->delivery_status !== 'returned';
                if (!$praSet || !$openCash) {
                    continue;
                }
                $key = (int) ($t->terminal_id ?? 0);
                $remaining = (float) $t->total_amount - ($hasPartial ? (float) ($t->rider_partial_paid ?? 0) : 0);
                $out[$key] = ($out[$key] ?? 0.0) + $remaining;
            }

            return $out;
        } catch (\Throwable $e) {
            // Rider figures are reporting sugar — never break a day close.
            return [];
        }
    }

    /**
     * The counter-wise cash reconciliation for one scope + business date.
     *
     * @param  iterable  $transactions  the day's already-loaded bill set (the
     *                                  SAME set the shop figures are built from)
     * @param  array     $riderFigures  buildRiderDayFigures() output, if any
     * @return Collection<int,array>    plain arrays — this shape is frozen onto
     *                                  the Z-report as counter_summary
     */
    public static function rows(
        int $companyId,
        ?int $branchId,
        string $date,
        $transactions,
        array $riderFigures = [],
        ?int $onlyCreatedBy = null
    ): Collection {
        if (!self::ready()) {
            return collect();
        }

        $txns = collect($transactions);
        $byDrawer = $txns->groupBy(fn ($t) => (int) ($t->terminal_id ?? 0));
        $counters = self::counters($companyId);

        // A shop that never made a counter must see the day close EXACTLY as
        // before: no counter card, no per-counter close, no new blocker.
        if ($counters->isEmpty() && $byDrawer->keys()->filter(fn ($k) => (int) $k > 0)->isEmpty()) {
            return collect();
        }

        $closes = self::closes($companyId, $branchId, $date);
        $openings = PosDayOpening::drawersForDate($companyId, $date, $branchId);
        $names = self::names($companyId);
        $riderOut = ($riderFigures['active'] ?? false)
            ? self::riderCashOutByDrawer($companyId, $branchId, $date, $onlyCreatedBy)
            : [];
        // Settlements received today against EARLIER days' bills belong to no
        // counter — they were handed to the shop, so they sit on drawer 0.
        $shopCashIn = round((float) ($riderFigures['cash_in'] ?? 0), 2);

        $keys = $counters->keys()
            ->merge($byDrawer->keys())
            ->merge($closes->keys())
            ->merge($openings->keys())
            ->merge(array_keys($riderOut))
            ->map(fn ($k) => (int) $k)
            ->unique();

        // The shop drawer only earns a row when something actually sits in it.
        if ($shopCashIn <= 0.0
            && ($byDrawer[self::SHOP_DRAWER] ?? collect())->isEmpty()
            && !$closes->has(self::SHOP_DRAWER)
            && !$openings->has(self::SHOP_DRAWER)) {
            $keys = $keys->reject(fn ($k) => $k === self::SHOP_DRAWER);
        }

        $rows = $keys->sort()->values()->map(function (int $key) use (
            $byDrawer, $closes, $openings, $names, $riderOut, $shopCashIn, $counters
        ) {
            $group = $byDrawer[$key] ?? collect();
            $sales = $group->filter(fn ($t) => ($t->transaction_type ?? 'sale') !== 'return');
            $returns = $group->filter(fn ($t) => ($t->transaction_type ?? 'sale') === 'return');
            // Returns hand cash BACK across the counter, so they net out of it.
            $cash = round(PosPaymentBuckets::split($sales)['cash'] - PosPaymentBuckets::split($returns)['cash'], 2);

            $opening = $openings->has($key) ? round((float) $openings[$key], 2) : null;
            $out = round((float) ($riderOut[$key] ?? 0), 2);
            $in = $key === self::SHOP_DRAWER ? $shopCashIn : 0.0;
            $expected = round((float) ($opening ?? 0) + $cash - $out + $in, 2);

            $close = $closes[$key] ?? null;
            // A closed counter shows the figures FROZEN at close time — a later
            // sale on another counter must not silently move its difference.
            if ($close) {
                $opening = $close->opening_float === null ? null : round((float) $close->opening_float, 2);
                $cash = round((float) $close->cash_sales, 2);
                $expected = round((float) $close->expected_cash, 2);
            }
            $counted = $close && $close->counted_cash !== null ? round((float) $close->counted_cash, 2) : null;

            return [
                'terminal_id' => $key,
                'name' => $key === self::SHOP_DRAWER
                    ? __('pos.counter_not_set')
                    : ($names[$key] ?? ($counters[$key]->terminal_name ?? (__('pos.counter_word') . ' #' . $key))),
                'opening' => $opening,
                'cash_sales' => $cash,
                'rider_out' => $out,
                'rider_in' => $in,
                'expected' => $expected,
                'counted' => $counted,
                'variance' => $counted === null ? null : round($counted - $expected, 2),
                'bills' => $close ? (int) $close->bills_count : $sales->count(),
                'total' => $close ? round((float) $close->total_sales, 2) : round((float) $group->sum('total_amount'), 2),
                'used' => $group->isNotEmpty(),
                'closed' => (bool) $close,
                'closed_at' => $close?->closed_at?->format('h:i A'),
                'closed_by' => $close?->closedByUser?->name,
                'notes' => $close?->notes,
            ];
        });

        return $rows;
    }

    /** Column totals of the counter rows — the card's "all counters" line. */
    public static function totals(Collection $rows): array
    {
        $counted = $rows->whereNotNull('counted');

        return [
            'opening' => round((float) $rows->sum(fn ($r) => (float) ($r['opening'] ?? 0)), 2),
            'cash_sales' => round((float) $rows->sum('cash_sales'), 2),
            'expected' => round((float) $rows->sum('expected'), 2),
            'counted' => $counted->isEmpty() ? null : round((float) $counted->sum('counted'), 2),
            'variance' => $counted->isEmpty() ? null : round((float) $counted->sum('variance'), 2),
            'bills' => (int) $rows->sum('bills'),
            'total' => round((float) $rows->sum('total'), 2),
            'closed' => $rows->where('closed', true)->count(),
            'open' => $rows->where('closed', false)->count(),
        ];
    }

    /**
     * Drawers that still owe a close: every drawer that took a bill today (plus
     * any already-closed one). A counter that never billed is not made to close
     * — an owner should not have to visit an unused counter to end the day.
     */
    public static function pendingDrawers(Collection $rows): Collection
    {
        return $rows->filter(fn ($r) => ($r['used'] ?? false) && !$r['closed'])->values();
    }

    /** True once every drawer that took a bill today has been closed. */
    public static function allDrawersClosed(Collection $rows): bool
    {
        if ($rows->isEmpty()) {
            return false;
        }

        return self::pendingDrawers($rows)->isEmpty() && $rows->where('closed', true)->isNotEmpty();
    }

    /**
     * The shop-level cash figures implied by the counter closes — used to fill
     * the Z-report when the LAST counter's close ends the day.
     *
     * @return array{opening_float: ?float, counted_cash: ?float}
     */
    public static function shopReconFromCloses(Collection $rows): array
    {
        $totals = self::totals($rows);
        $anyOpening = $rows->contains(fn ($r) => $r['opening'] !== null);

        return [
            'opening_float' => $anyOpening ? $totals['opening'] : null,
            'counted_cash' => $totals['counted'],
        ];
    }

    /** Branch key helper so callers do not have to know the 0-means-none rule. */
    public static function branchKey(?int $branchId): int
    {
        return PosDayCloseReport::branchKey($branchId);
    }
}
