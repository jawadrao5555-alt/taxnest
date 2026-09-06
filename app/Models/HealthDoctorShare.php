<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One doctor's earning on one charge (Task 1552).
 *
 * The rule is FROZEN onto the row at accrual time — basis, rate, base and the
 * base amount it bit on. A rule edited next month must never silently restate
 * what has already been reviewed or paid, and "why was I paid this" has to have
 * an answer that does not depend on today's configuration.
 *
 * `dedupe_key` (charge + doctor) is what lets the accrual sweep run as often as
 * it likes without ever paying anybody twice.
 */
class HealthDoctorShare extends Model
{
    public const STATUS_ACCRUED = 'accrued';
    public const STATUS_EXCLUDED = 'excluded';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_REVERSED = 'reversed';

    public const STATUSES = [
        self::STATUS_ACCRUED,
        self::STATUS_EXCLUDED,
        self::STATUS_APPROVED,
        self::STATUS_SETTLED,
        self::STATUS_REVERSED,
    ];

    /** Statuses whose money the doctor is still owed. */
    public const PAYABLE_STATUSES = [self::STATUS_ACCRUED, self::STATUS_APPROVED, self::STATUS_SETTLED];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_department_id',
        'health_doctor_id',
        'health_charge_id',
        'health_bill_id',
        'health_patient_id',
        'health_doctor_share_rule_id',
        'accrual_date',
        'charge_category',
        'description',
        'basis',
        'rate',
        'base',
        'base_amount',
        'share_amount',
        'status',
        'health_doctor_settlement_id',
        'exclusion_reason',
        'excluded_by',
        'excluded_at',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'dedupe_key',
    ];

    protected $casts = [
        'accrual_date' => 'date',
        'rate' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'share_amount' => 'decimal:2',
        'excluded_at' => 'datetime',
        'reversed_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_department_id' => 'integer',
        'health_doctor_id' => 'integer',
        'health_charge_id' => 'integer',
        'health_bill_id' => 'integer',
        'health_patient_id' => 'integer',
        'health_doctor_settlement_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function doctor()
    {
        return $this->belongsTo(HealthDoctor::class, 'health_doctor_id');
    }

    public function charge()
    {
        return $this->belongsTo(HealthCharge::class, 'health_charge_id');
    }

    public function bill()
    {
        return $this->belongsTo(HealthBill::class, 'health_bill_id');
    }

    public function settlement()
    {
        return $this->belongsTo(HealthDoctorSettlement::class, 'health_doctor_settlement_id');
    }

    public function rule()
    {
        return $this->belongsTo(HealthDoctorShareRule::class, 'health_doctor_share_rule_id');
    }

    /** Still waiting to be put on a settlement. */
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_ACCRUED)->whereNull('health_doctor_settlement_id');
    }

    public function statusLabelKey(): string
    {
        return 'health.dsh_status_' . (in_array($this->status, self::STATUSES, true) ? $this->status : self::STATUS_ACCRUED);
    }
}
