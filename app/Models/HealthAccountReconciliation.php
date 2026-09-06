<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "What the books say, against what the bank or the drawer says" (Task 1552).
 *
 * The difference is STORED rather than forced to zero. An unexplained three
 * hundred rupees is information the owner needs; a screen that refuses to save
 * until the two sides agree simply stops being used, and the reconciliation
 * that never happened is worse than the one with a noted gap.
 *
 * Clearing that gap is a separate, deliberate act: an adjustment journal, whose
 * id is recorded here, so the correction is traceable back to the
 * reconciliation that justified it.
 */
class HealthAccountReconciliation extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_account_id',
        'statement_date',
        'period_from',
        'book_balance',
        'statement_balance',
        'difference',
        'status',
        'adjustment_journal_id',
        'notes',
        'closed_at',
        'closed_by',
        'created_by',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'period_from' => 'date',
        'book_balance' => 'decimal:2',
        'statement_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'closed_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_account_id' => 'integer',
        'adjustment_journal_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function account()
    {
        return $this->belongsTo(HealthAccount::class, 'health_account_id');
    }

    public function adjustment()
    {
        return $this->belongsTo(HealthJournal::class, 'adjustment_journal_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
