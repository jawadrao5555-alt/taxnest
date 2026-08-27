<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One physical stock-count session for a branch.
 *
 * A check is a frozen conversation between two numbers: what the system said
 * should be on the shelf when counting started (expected) and what a human
 * physically found (counted). It stays editable while `counting` and becomes
 * an immutable record once `completed`.
 */
class StockCheck extends Model
{
    public const SCOPE_PRODUCTS = 'products';
    public const SCOPE_INGREDIENTS = 'ingredients';
    public const SCOPE_BOTH = 'both';

    public const STATUS_COUNTING = 'counting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'scope', 'status', 'notes',
        'total_lines', 'counted_lines', 'variance_lines',
        'short_value', 'excess_value',
        'started_at', 'posted_at', 'created_by', 'posted_by',
    ];

    protected $casts = [
        'total_lines' => 'integer',
        'counted_lines' => 'integer',
        'variance_lines' => 'integer',
        'short_value' => 'float',
        'excess_value' => 'float',
        'started_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(StockCheckLine::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_COUNTING;
    }

    /** Net rupee effect: negative = shop is short. */
    public function netValue(): float
    {
        return round((float) $this->excess_value - (float) $this->short_value, 2);
    }
}
