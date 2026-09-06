<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Money moving between the organisation's own accounts (Task 1552).
 *
 * A bank deposit is modelled as a TRANSFER, not as its own document type: the
 * notes leave the drawer and arrive in the bank, and giving each half its own
 * record is precisely how the two sides stop agreeing. The `kind` column is
 * presentation — which word the screen uses — while the accounting is always
 * the same one balanced pair.
 */
class HealthFundTransfer extends Model
{
    public const KIND_DEPOSIT = 'deposit';
    public const KIND_WITHDRAWAL = 'withdrawal';
    public const KIND_TRANSFER = 'transfer';
    public const KINDS = [self::KIND_DEPOSIT, self::KIND_WITHDRAWAL, self::KIND_TRANSFER];

    public const STATUS_POSTED = 'posted';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'company_id',
        'branch_id',
        'transfer_no',
        'transfer_date',
        'kind',
        'from_account_id',
        'to_account_id',
        'amount',
        'reference',
        'notes',
        'status',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'created_by',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'from_account_id' => 'integer',
        'to_account_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function fromAccount()
    {
        return $this->belongsTo(HealthAccount::class, 'from_account_id');
    }

    public function toAccount()
    {
        return $this->belongsTo(HealthAccount::class, 'to_account_id');
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function kindLabelKey(): string
    {
        return 'health.xfer_kind_' . (in_array($this->kind, self::KINDS, true) ? $this->kind : self::KIND_TRANSFER);
    }
}
