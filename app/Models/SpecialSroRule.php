<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpecialSroRule extends Model
{
    protected $fillable = [
        'hs_code', 'schedule_type', 'sro_number', 'serial_no',
        'applicable_sector', 'applicable_province', 'concessionary_rate',
        'description', 'effective_from', 'effective_until', 'is_active',
    ];

    protected $casts = [
        'concessionary_rate' => 'float',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'is_active' => 'boolean',
    ];
}
