<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HsIntelligenceLog extends Model
{
    public $timestamps = false;

    protected $table = 'hs_intelligence_logs';

    protected $fillable = [
        'hs_code',
        'suggested_schedule_type',
        'suggested_tax_rate',
        'suggested_sro_required',
        'suggested_serial_required',
        'suggested_mrp_required',
        'confidence_score',
        'weight_breakdown',
        'based_on_records_count',
        'rejection_factor',
        'industry_factor',
        'created_at',
    ];

    protected $casts = [
        'suggested_tax_rate' => 'decimal:2',
        'suggested_sro_required' => 'boolean',
        'suggested_serial_required' => 'boolean',
        'suggested_mrp_required' => 'boolean',
        'confidence_score' => 'integer',
        'weight_breakdown' => 'array',
        'based_on_records_count' => 'integer',
        'rejection_factor' => 'integer',
        'industry_factor' => 'integer',
        'created_at' => 'datetime',
    ];
}
