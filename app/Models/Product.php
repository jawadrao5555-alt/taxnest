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
        // Peti (Wholesale) Rate (Task 1414): "peti mein kitne piece" — nullable.
        // Missing from $fillable ⇒ Eloquent silently drops the write on
        // create()/update() (the exact trap the plan warns about).
        'pack_size',
    ];

    protected $casts = [
        'is_price_editable' => 'boolean',
        'is_active' => 'boolean',
        'show_on_sale' => 'boolean',
        'is_third_schedule' => 'boolean',
        // NULL stays NULL (not-a-peti-product); a real value casts to int.
        'pack_size' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function aliases()
    {
        return $this->hasMany(ProductAlias::class);
    }
}
