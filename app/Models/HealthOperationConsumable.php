<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What was used up performing an operation.
 *
 * `is_billable` is false for anything a package already covers. The usage
 * record still has to be complete — theatre stock needs to know what left the
 * shelf — but the patient must not be charged for it twice.
 */
class HealthOperationConsumable extends Model
{
    protected $table = 'health_operation_consumables';

    protected $fillable = [
        'company_id',
        'health_operation_id',
        'item_name',
        'unit',
        'quantity',
        'unit_price',
        'amount',
        'is_billable',
        'note',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'is_billable' => 'boolean',
        'company_id' => 'integer',
        'health_operation_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function operation()
    {
        return $this->belongsTo(HealthOperation::class, 'health_operation_id');
    }
}
