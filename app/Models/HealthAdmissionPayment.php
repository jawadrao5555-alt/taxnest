<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An advance taken against a stay, or a refund given back at the end.
 *
 * Deliberately NOT a charge carrying a negative sign: money the patient has
 * PAID and money the patient OWES are different facts, and a report that adds
 * them together answers neither question. Outstanding is computed from both
 * sides in HealthIpdBillingService, in one place.
 */
class HealthAdmissionPayment extends Model
{
    public const KIND_ADVANCE = 'advance';
    public const KIND_REFUND = 'refund';
    public const KINDS = [self::KIND_ADVANCE, self::KIND_REFUND];

    public const METHODS = ['cash', 'card', 'online', 'cheque', 'other'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_admission_id',
        'kind',
        'amount',
        'method',
        'reference',
        'note',
        'received_at',
        'received_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'received_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_admission_id' => 'integer',
        'received_by' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function admission()
    {
        return $this->belongsTo(HealthAdmission::class, 'health_admission_id');
    }

    public static function methodLabelKey(?string $method): string
    {
        return 'health.pay_method_' . (in_array($method, self::METHODS, true) ? $method : 'other');
    }
}
