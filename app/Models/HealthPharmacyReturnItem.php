<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HealthPharmacyReturnItem extends Model
{
    protected $table = 'health_pharmacy_return_items';

    protected $fillable = [
        'company_id',
        'return_id',
        'sale_item_id',
        'medicine_id',
        'batch_id',
        'quantity',
        'unit_price',
        'refund_amount',
        'restocked',
    ];

    protected $casts = [
        'quantity' => 'float',
        'unit_price' => 'float',
        'refund_amount' => 'float',
        'restocked' => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function return()
    {
        return $this->belongsTo(HealthPharmacyReturn::class, 'return_id');
    }

    public function saleItem()
    {
        return $this->belongsTo(HealthPharmacySaleItem::class, 'sale_item_id');
    }

    public function medicine()
    {
        return $this->belongsTo(HealthMedicine::class, 'medicine_id');
    }
}
