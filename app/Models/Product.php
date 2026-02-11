<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'hs_code',
        'pct_code',
        'default_tax_rate',
        'uom',
        'schedule_type',
        'sro_reference',
        'default_price',
        'is_active',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
