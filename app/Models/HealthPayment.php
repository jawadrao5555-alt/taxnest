<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Money in, and money back out (Task 1551).
 *
 * A DEPOSIT with no bill is an advance sitting on the patient's account; it
 * gains a health_bill_id the moment it is applied to something. A REFUND is its
 * own kind rather than a negative payment, because "what did we collect today"
 * and "what did we give back today" are two questions the counter is asked
 * separately, and a report that nets them answers neither.
 *
 * Every row can name the shift that took it, which is what makes the drawer
 * reconcile against the billing ledger itself rather than against a tally kept
 * somewhere else.
 */
class HealthPayment extends Model
{
    public const KIND_DEPOSIT = 'deposit';
    public const KIND_PAYMENT = 'payment';
    public const KIND_REFUND = 'refund';
    public const KIND_INSURANCE = 'insurance';
    public const KIND_CORPORATE = 'corporate';
    public const KIND_WRITE_OFF = 'write_off';

    public const KINDS = [
        self::KIND_DEPOSIT,
        self::KIND_PAYMENT,
        self::KIND_REFUND,
        self::KIND_INSURANCE,
        self::KIND_CORPORATE,
        self::KIND_WRITE_OFF,
    ];

    /** Kinds that put money INTO the hospital. */
    public const INFLOW_KINDS = [
        self::KIND_DEPOSIT,
        self::KIND_PAYMENT,
        self::KIND_INSURANCE,
        self::KIND_CORPORATE,
    ];

    public const METHODS = [
        'cash', 'card', 'online', 'cheque', 'insurance', 'corporate', 'credit', 'other',
    ];

    /**
     * Methods that land in the cash drawer.
     *
     * A list rather than `= 'cash'` on purpose: the retail side learned the hard
     * way that one alias missing from a bucket quietly moves money out of the
     * day's totals.
     */
    public const CASH_METHODS = ['cash'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_patient_id',
        'health_bill_id',
        // Set when this receipt was carved out of a bigger advance rather than
        // taken at the counter. The books credit the cash once, on the parent.
        'split_from_payment_id',
        'health_admission_id',
        'health_cashier_shift_id',
        'receipt_no',
        'kind',
        'amount',
        'method',
        'reference',
        'note',
        'received_at',
        'received_by',
        'business_date',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'received_at' => 'datetime',
        'business_date' => 'date',
        'reversed_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_patient_id' => 'integer',
        'health_bill_id' => 'integer',
        'split_from_payment_id' => 'integer',
        'health_admission_id' => 'integer',
        'health_cashier_shift_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function patient()
    {
        return $this->belongsTo(HealthPatient::class, 'health_patient_id');
    }

    public function bill()
    {
        return $this->belongsTo(HealthBill::class, 'health_bill_id');
    }

    public function shift()
    {
        return $this->belongsTo(HealthCashierShift::class, 'health_cashier_shift_id');
    }

    /** Rows that still count — a reversed receipt is evidence, not money. */
    public function scopeLive($query)
    {
        return $query->whereNull('reversed_at');
    }

    public function isInflow(): bool
    {
        return in_array($this->kind, self::INFLOW_KINDS, true);
    }

    /** Signed amount: inflow positive, refund and write-off negative. */
    public function signedAmount(): float
    {
        return round($this->isInflow() ? (float) $this->amount : -(float) $this->amount, 2);
    }

    public static function kindLabelKey(?string $kind): string
    {
        return 'health.pay_kind_' . (in_array($kind, self::KINDS, true) ? $kind : self::KIND_PAYMENT);
    }

    public static function methodLabelKey(?string $method): string
    {
        return 'health.pay_method_' . (in_array($method, self::METHODS, true) ? $method : 'other');
    }
}
