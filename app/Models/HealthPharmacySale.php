<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A pharmacy counter sale — walk-in or patient-linked (Task 1549).
 *
 * FBR-ready, not FBR-filed: the tax split, the fiscal identifiers and the
 * readiness flag are persisted at sale time so the healthcare billing module
 * can file these rows later without re-deriving money that was already agreed
 * with the customer. Nothing in this module talks to the regulator.
 */
class HealthPharmacySale extends Model
{
    public const TYPE_COUNTER = 'counter';
    public const TYPE_PATIENT = 'patient';
    public const TYPE_PRESCRIPTION = 'prescription';

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIALLY_RETURNED = 'partially_returned';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_VOID = 'void';

    public const PAYMENT_METHODS = ['cash', 'card', 'online', 'credit'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_department_id',
        'sale_number',
        'sale_type',
        'prescription_id',
        'patient_id',
        'patient_name',
        'patient_mr_no',
        'patient_phone',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'cost_amount',
        'paid_amount',
        'change_amount',
        'refunded_amount',
        'tax_rate',
        'payment_method',
        'status',
        'fbr_ready',
        'fbr_status',
        'fbr_invoice_number',
        'business_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'discount_amount' => 'float',
        'tax_amount' => 'float',
        'total_amount' => 'float',
        'cost_amount' => 'float',
        'paid_amount' => 'float',
        'change_amount' => 'float',
        'refunded_amount' => 'float',
        'tax_rate' => 'float',
        'fbr_ready' => 'boolean',
        'business_date' => 'date',
    ];

    /**
     * Warnings raised while this bill was being dispensed (short-dated lots,
     * expired stock used under override). Deliberately a plain property and
     * NOT a model attribute: it is counter feedback for the current request,
     * not a stored column, and putting it in the attribute bag makes the very
     * next save() try to write a column that does not exist.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $dispenseWarnings = [];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public static function statusLabelKey(?string $status): string
    {
        return 'health.sale_status_' . ($status ?: self::STATUS_COMPLETED);
    }

    public static function paymentLabelKey(?string $method): string
    {
        return 'health.pay_' . (in_array($method, self::PAYMENT_METHODS, true) ? $method : 'cash');
    }

    public function items()
    {
        return $this->hasMany(HealthPharmacySaleItem::class, 'sale_id');
    }

    public function returns()
    {
        return $this->hasMany(HealthPharmacyReturn::class, 'sale_id');
    }

    public function prescription()
    {
        return $this->belongsTo(HealthPrescription::class, 'prescription_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Money actually kept after refunds — every report reads this, not total. */
    public function getNetAmountAttribute(): float
    {
        return round((float) $this->total_amount - (float) $this->refunded_amount, 2);
    }
}
