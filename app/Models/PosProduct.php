<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosProduct extends Model
{
    protected $fillable = [
        'company_id', 'name', 'description', 'price', 'tax_rate',
        'hs_code', 'uom', 'category', 'image', 'sku', 'barcode', 'is_active', 'is_tax_exempt',
    ];

    protected $casts = [
        'price' => 'float',
        'tax_rate' => 'float',
        'is_active' => 'boolean',
        'is_tax_exempt' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
