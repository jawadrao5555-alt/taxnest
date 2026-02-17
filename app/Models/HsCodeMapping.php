<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HsCodeMapping extends Model
{
    protected $fillable = [
        'hs_code', 'label', 'sale_type', 'tax_rate', 'sro_applicable', 'sro_number',
        'serial_number_applicable', 'serial_number_value', 'mrp_required', 'pct_code',
        'default_uom', 'buyer_type', 'notes', 'priority', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'sro_applicable' => 'boolean',
        'serial_number_applicable' => 'boolean',
        'mrp_required' => 'boolean',
        'is_active' => 'boolean',
        'tax_rate' => 'decimal:2',
        'priority' => 'integer',
    ];

    public function responses()
    {
        return $this->hasMany(HsMappingResponse::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForHsCode($query, $hsCode)
    {
        return $query->where('hs_code', $hsCode);
    }
}
