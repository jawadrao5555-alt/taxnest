<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HsMasterGlobal extends Model
{
    protected $table = 'hs_master_global';

    protected $fillable = [
        'hs_code',
        'description',
        'schedule_type',
        'default_tax_rate',
        'sro_required',
        'default_sro_number',
        'serial_required',
        'default_serial_no',
        'mrp_required',
        'st_withheld_applicable',
        'petroleum_levy_applicable',
        'default_uom',
        'confidence_score',
        'last_source',
        'is_active',
    ];

    protected $casts = [
        'default_tax_rate' => 'decimal:2',
        'sro_required' => 'boolean',
        'serial_required' => 'boolean',
        'mrp_required' => 'boolean',
        'st_withheld_applicable' => 'boolean',
        'petroleum_levy_applicable' => 'boolean',
        'is_active' => 'boolean',
        'confidence_score' => 'integer',
    ];
}
