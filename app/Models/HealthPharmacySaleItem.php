<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One sold line, pinned to the BATCH it left. The batch number and expiry are
 * snapshots on purpose: a recall traces the printed receipt, and a later batch
 * edit must never rewrite what the patient was handed.
 */
class HealthPharmacySaleItem extends Model
{
    protected $fillable = [
        'company_id',
        'sale_id',
        'medicine_id',
        'product_id',
        'batch_id',
        'prescription_item_id',
        'item_name',
        'batch_no',
        'expiry_date',
        'quantity',
        'returned_quantity',
        'unit_price',
        'unit_cost',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'line_total',
        'is_substitute',
        'substitute_for_medicine_id',
        'approved_by',
        'dosage_instructions',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'quantity' => 'float',
        'returned_quantity' => 'float',
        'unit_price' => 'float',
        'unit_cost' => 'float',
        'discount_amount' => 'float',
        'tax_rate' => 'float',
        'tax_amount' => 'float',
        'line_total' => 'float',
        'is_substitute' => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function sale()
    {
        return $this->belongsTo(HealthPharmacySale::class, 'sale_id');
    }

    public function medicine()
    {
        return $this->belongsTo(HealthMedicine::class, 'medicine_id');
    }

    public function batch()
    {
        return $this->belongsTo(HealthMedicineBatch::class, 'batch_id');
    }

    public function getReturnableQuantityAttribute(): float
    {
        return max(0, round((float) $this->quantity - (float) $this->returned_quantity, 3));
    }
}
