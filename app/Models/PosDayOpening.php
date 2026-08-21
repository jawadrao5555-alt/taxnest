<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Opening Cash Balance — one row per DRAWER per business date.
 * Recorded at day start (drawer float for change); consumed by day-close
 * cash reconciliation (auto-fills opening_float on the Z-report).
 *
 * Task 1375: a drawer is (branch, counter). A shop with two counters keeps two
 * floats; the Z-report's shop-level opening is their SUM (totalForDate), so the
 * counter rows always tile the shop figure. terminal_id 0 = the shop drawer,
 * which is where every counter-less shop's single row lives — unchanged.
 */
class PosDayOpening extends Model
{
    protected $table = 'pos_day_openings';

    protected $fillable = [
        'company_id',
        // Per-branch day close (Task 1360): each branch counts its own drawer.
        // 0 = no branch (branch-less shop / pre-branch history) — never NULL,
        // see PosDayCloseReport::NO_BRANCH for why.
        'branch_id',
        // Per-counter drawer (Task 1375): 0 = shop drawer / no counter.
        'terminal_id',
        'business_date',
        'opening_cash',
        'entered_by',
        'notes',
    ];

    protected $casts = [
        'business_date' => 'date',
        'opening_cash' => 'decimal:2',
    ];

    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /**
     * The recorded opening for ONE drawer, or null. Task 1360: scoped to the
     * close's branch. Task 1375: and to one counter — terminal 0 (the shop
     * drawer) is the default, so every pre-counter caller keeps its old row.
     * Schema-guarded so a box without either column keeps its old lookup.
     */
    public static function forDate(int $companyId, string $date, ?int $branchId = null, int $terminalId = 0): ?self
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('pos_day_openings')) {
            return null;
        }
        return static::where('company_id', $companyId)
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('pos_day_openings', 'branch_id'),
                fn ($q) => $q->where('branch_id', PosDayCloseReport::branchKey($branchId)))
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('pos_day_openings', 'terminal_id'),
                fn ($q) => $q->where('terminal_id', $terminalId))
            ->whereDate('business_date', $date)
            ->first();
    }

    /**
     * Every drawer's opening for a scope + date, keyed by terminal id
     * (0 = shop drawer). One query — the counter reconciliation and the
     * shop total both read it.
     *
     * @return \Illuminate\Support\Collection<int,float>
     */
    public static function drawersForDate(int $companyId, string $date, ?int $branchId = null)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('pos_day_openings')) {
            return collect();
        }
        $hasTerminal = \Illuminate\Support\Facades\Schema::hasColumn('pos_day_openings', 'terminal_id');

        return static::where('company_id', $companyId)
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('pos_day_openings', 'branch_id'),
                fn ($q) => $q->where('branch_id', PosDayCloseReport::branchKey($branchId)))
            ->whereDate('business_date', $date)
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) ($hasTerminal ? ($row->terminal_id ?? 0) : 0) => (float) $row->opening_cash,
            ]);
    }

    /**
     * The SHOP's opening float for a scope + date = the sum of every drawer's
     * float, or null when nobody recorded one. Task 1375: with per-counter
     * drawers the Z-report must not read a single row anymore, or a two-counter
     * shop's expected cash would silently drop the second counter's float.
     * Counter-less shops have exactly one row (terminal 0), so this returns
     * exactly what forDate() used to.
     */
    public static function totalForDate(int $companyId, string $date, ?int $branchId = null): ?float
    {
        $drawers = static::drawersForDate($companyId, $date, $branchId);

        return $drawers->isEmpty() ? null : round((float) $drawers->sum(), 2);
    }
}
