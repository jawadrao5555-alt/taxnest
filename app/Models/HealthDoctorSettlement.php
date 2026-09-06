<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One period's payout to one doctor (Task 1552).
 *
 * Four states, and the split between them is the control:
 *
 *   draft      the accountant has gathered the accruals and may still add,
 *              remove or exclude lines. Nothing has hit the books.
 *   approved   an authority above the accountant signed it off. THIS is when
 *              the expense and the payable land in the ledger — an unreviewed
 *              accrual is an estimate, and estimates do not belong in books
 *              somebody reports on.
 *   paid       the money left a cash or bank account.
 *   reversed   both journals undone by their own reversals, lines released.
 *
 * Releasing lines on reversal (rather than deleting them) is deliberate: the
 * work still happened, so the accrual goes back to being unsettled and can be
 * put on a corrected settlement.
 */
class HealthDoctorSettlement extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';
    public const STATUS_REVERSED = 'reversed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
        self::STATUS_PAID,
        self::STATUS_REVERSED,
    ];

    /** Statuses that still owe the doctor money. */
    public const LIVE_STATUSES = [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_PAID];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_doctor_id',
        'settlement_no',
        'period_from',
        'period_to',
        'share_count',
        'gross_amount',
        'deduction_amount',
        'deduction_reason',
        'net_amount',
        'paid_amount',
        'status',
        'approved_at',
        'approved_by',
        'paid_at',
        'paid_by',
        'pay_method',
        'paid_from_account_id',
        'pay_reference',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'share_count' => 'integer',
        'gross_amount' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'reversed_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_doctor_id' => 'integer',
        'paid_from_account_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function doctor()
    {
        return $this->belongsTo(HealthDoctor::class, 'health_doctor_id');
    }

    public function shares()
    {
        return $this->hasMany(HealthDoctorShare::class, 'health_doctor_settlement_id');
    }

    public function payFrom()
    {
        return $this->belongsTo(HealthAccount::class, 'paid_from_account_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    public function statusLabelKey(): string
    {
        return 'health.dset_status_' . (in_array($this->status, self::STATUSES, true) ? $this->status : self::STATUS_DRAFT);
    }
}
