<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalHsMaster extends Model
{
    protected $table = 'global_hs_master';

    protected $fillable = [
        'hs_code', 'description', 'pct_code', 'schedule_type', 'tax_rate',
        'default_uom', 'sro_required', 'sro_number', 'sro_item_serial_no',
        'mrp_required', 'sector_tag', 'risk_weight', 'mapping_status',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'tax_rate' => 'float',
        'risk_weight' => 'float',
        'sro_required' => 'boolean',
        'mrp_required' => 'boolean',
    ];

    public function scopeSearch($query, ?string $search)
    {
        if (empty($search)) return $query;
        return $query->where(function ($q) use ($search) {
            $q->where('hs_code', 'ilike', "%{$search}%")
              ->orWhere('description', 'ilike', "%{$search}%")
              ->orWhere('schedule_type', 'ilike', "%{$search}%")
              ->orWhere('sector_tag', 'ilike', "%{$search}%")
              ->orWhere('sro_number', 'ilike', "%{$search}%")
              ->orWhere('pct_code', 'ilike', "%{$search}%");
        });
    }

    public function scopeBySchedule($query, ?string $schedule)
    {
        if (empty($schedule)) return $query;
        return $query->where('schedule_type', $schedule);
    }

    public function scopeBySector($query, ?string $sector)
    {
        if (empty($sector)) return $query;
        return $query->where('sector_tag', $sector);
    }

    public function scopeByMapping($query, ?string $status)
    {
        if (empty($status)) return $query;
        return $query->where('mapping_status', $status);
    }
}
