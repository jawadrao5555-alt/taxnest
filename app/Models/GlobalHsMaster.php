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
        'st_withheld_applicable', 'petroleum_levy_applicable',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'tax_rate' => 'float',
        'risk_weight' => 'float',
        'sro_required' => 'boolean',
        'mrp_required' => 'boolean',
        'st_withheld_applicable' => 'boolean',
        'petroleum_levy_applicable' => 'boolean',
    ];

    public function scopeSearch($query, ?string $search)
    {
        if (empty($search)) return $query;
        $like = \App\Helpers\DbCompat::like();
        return $query->where(function ($q) use ($search, $like) {
            $q->where('hs_code', $like, "%{$search}%")
              ->orWhere('description', $like, "%{$search}%")
              ->orWhere('schedule_type', $like, "%{$search}%")
              ->orWhere('sector_tag', $like, "%{$search}%")
              ->orWhere('sro_number', $like, "%{$search}%")
              ->orWhere('pct_code', $like, "%{$search}%");
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
