<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'barcode',
        'sku',
        'hs_code',
        'pct_code',
        'default_tax_rate',
        'tax_type',
        'uom',
        'schedule_type',
        'sro_reference',
        'serial_number',
        'mrp',
        'default_price',
        'is_price_editable',
        'is_active',
        'show_on_sale',
        'is_third_schedule',
    ];

    protected $casts = [
        'is_price_editable' => 'boolean',
        'is_active' => 'boolean',
        'show_on_sale' => 'boolean',
        'is_third_schedule' => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
