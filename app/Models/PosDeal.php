<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PosDeal extends Model
{
    protected $table = 'pos_deals';

    protected $fillable = [
        'company_id', 'name', 'description', 'price', 'is_active',
        'active_days', 'starts_on', 'ends_on', 'deal_type',
        'special_start_time', 'special_end_time',
        'total_deal_units_limit', 'daily_deal_units_limit',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'active_days' => 'array',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'total_deal_units_limit' => 'integer',
        'daily_deal_units_limit' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function items()
    {
        return $this->hasMany(PosDealItem::class, 'deal_id');
    }

    /**
     * Is this deal live on the given date? Active flag + weekday match
     * (ISO 1=Mon..7=Sun; empty = every day) + optional date range.
     */
    public function isActiveOn(?Carbon $date = null): bool
    {
        $date = $date ?: now();
        if (!$this->is_active) return false;

        $days = array_map('intval', (array) ($this->active_days ?? []));
        if (!empty($days) && !in_array($date->isoWeekday(), $days, true)) return false;

        if ($this->starts_on && $date->lt($this->starts_on->startOfDay())) return false;
        if ($this->ends_on && $date->gt($this->ends_on->endOfDay())) return false;

        return true;
    }

    public function isSpecial(): bool
    {
        return \App\Services\PosDealQuotaService::isSpecial($this);
    }

    public function isAvailableAt(?Carbon $at = null): bool
    {
        return \App\Services\PosDealQuotaService::isAvailable($this, $at);
    }

    public function quotaMetadata(?Carbon $at = null): array
    {
        return \App\Services\PosDealQuotaService::metadata($this, $at);
    }
}
