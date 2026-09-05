<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A charge as it stood the moment it was printed (Task 1551).
 *
 * The ledger row stays the truth of what the patient owes; the line is the
 * truth of what the patient was HANDED. They are kept apart on purpose: a
 * concession granted tomorrow must not silently rewrite a receipt somebody is
 * holding today, and an FBR payload must be reproducible from the exact numbers
 * that were filed.
 *
 * The link home (health_charge_id plus the source_* trio copied from the
 * charge) survives even if the ledger row is later reversed, so every line on
 * every bill can still name the visit, prescription, admission, operation or
 * pharmacy sale behind it.
 */
class HealthBillLine extends Model
{
    protected $fillable = [
        'company_id',
        'health_bill_id',
        'health_charge_id',
        'line_no',
        'category',
        'description',
        'reference',
        'health_department_id',
        'department_name',
        'source_type',
        'source_id',
        'source_reference',
        'unit_price',
        'quantity',
        'gross_amount',
        'concession_amount',
        'net_amount',
        'tax_treatment',
        'tax_rate',
        'tax_amount',
        'total_amount',
        'pct_code',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'unit_price' => 'decimal:2',
        'quantity' => 'decimal:3',
        'gross_amount' => 'decimal:2',
        'concession_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'company_id' => 'integer',
        'health_bill_id' => 'integer',
        'health_charge_id' => 'integer',
        'health_department_id' => 'integer',
        'source_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function bill()
    {
        return $this->belongsTo(HealthBill::class, 'health_bill_id');
    }

    public function charge()
    {
        return $this->belongsTo(HealthCharge::class, 'health_charge_id');
    }

    public function isFbrReportable(): bool
    {
        return $this->tax_treatment === HealthTaxCategory::TREATMENT_FBR;
    }
}
