<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HsUsagePattern extends Model
{
    protected $fillable = [
        'hs_code',
        'schedule_type',
        'tax_rate',
        'sro_schedule_no',
        'sro_item_serial_no',
        'mrp_required',
        'sale_type',
        'success_count',
        'rejection_count',
        'confidence_score',
        'admin_status',
        'last_used_at',
        'integrity_hash',
    ];

    protected $casts = [
        'tax_rate' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'mrp_required' => 'boolean',
        'last_used_at' => 'datetime',
    ];
}
