<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SectorTaxRule extends Model
{
    protected $fillable = [
        'sector_type', 'hs_code', 'override_tax_rate', 'override_schedule_type',
        'override_sro_required', 'override_mrp_required', 'description', 'is_active',
    ];

    protected $casts = [
        'override_tax_rate' => 'float',
        'override_sro_required' => 'boolean',
        'override_mrp_required' => 'boolean',
        'is_active' => 'boolean',
    ];
}
