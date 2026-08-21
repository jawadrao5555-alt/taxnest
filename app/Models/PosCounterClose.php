<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ONE COUNTER'S DAY CLOSE (Task 1375) — one row per counter per business date.
 *
 * A two/three-counter shop keeps separate cash at every counter, so every
 * counter counts its own drawer: its own opening float, its own counted cash and
 * its own difference. Writing this row closes ONE counter — it touches no bill,
 * so the other counters keep billing; the shop's Z-report is created once every
 * used drawer has a row (see PosController::closeCounter).
 *
 * terminal_id 0 = the shop drawer, i.e. bills that carry no counter (and the
 * shop-level rider settlements that belong to no counter). branch_id follows the
 * Task 1360 convention: 0 = no branch / company-wide.
 */
class PosCounterClose extends Model
{
    protected $table = 'pos_counter_closes';

    /** Bills with no counter reconcile against the shop drawer. */
    public const SHOP_DRAWER = 0;

    protected $fillable = [
        'company_id', 'branch_id', 'terminal_id', 'business_date',
        'opening_float', 'cash_sales', 'expected_cash', 'counted_cash', 'cash_variance',
        'bills_count', 'total_sales', 'closed_by', 'notes', 'closed_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'closed_at' => 'datetime',
        'opening_float' => 'decimal:2',
        'cash_sales' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'cash_variance' => 'decimal:2',
        'total_sales' => 'decimal:2',
    ];

    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function closedByUser()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** Rows of ONE close scope (Task 1360 branch semantics). */
    public function scopeForBranch($query, ?int $branchId)
    {
        return $query->where('branch_id', PosDayCloseReport::branchKey($branchId));
    }
}
