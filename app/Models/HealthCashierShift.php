<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A counter's own open→close reconciliation (Task 1551).
 *
 * Opened when the cashier starts, closed when the drawer is counted. At close
 * the expected figures are computed from health_payments and FROZEN next to
 * what was actually counted, so a variance is still answerable months later
 * even after the underlying receipts have been re-read, re-printed or the day
 * has long been closed.
 *
 * `counted_cash` NULL is not zero. NULL means nobody has counted yet; zero
 * means somebody counted and found an empty drawer. Collapsing the two turns a
 * missed count into a clean reconciliation, which is the failure this column
 * exists to prevent.
 */
class HealthCashierShift extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'company_id',
        'branch_id',
        'user_id',
        'opened_at',
        'opened_by',
        'opening_float',
        'closed_at',
        'closed_by',
        'counted_cash',
        'expected_cash',
        'variance',
        'totals',
        'status',
        'note',
        'business_date',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_float' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'variance' => 'decimal:2',
        'totals' => 'array',
        'business_date' => 'date',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'user_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function payments()
    {
        return $this->hasMany(HealthPayment::class, 'health_cashier_shift_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** TRUE only when a human actually counted the drawer. */
    public function wasCounted(): bool
    {
        return $this->counted_cash !== null;
    }
}
