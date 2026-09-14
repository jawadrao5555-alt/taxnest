<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotelRoom extends Model
{
    public const SERVICE_IN = 'in_service';
    public const SERVICE_OUT = 'out_of_service';

    public const HK_CLEAN = 'clean';
    public const HK_DIRTY = 'dirty';
    public const HK_INSPECTED = 'inspected';

    public const CHARGING_NIGHTLY = 'nightly';

    protected $fillable = [
        'company_id', 'branch_id', 'room_number', 'room_type', 'capacity',
        'rate_amount', 'rate_unit', 'charging_rule', 'service_state',
        'housekeeping', 'notes', 'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'rate_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(HotelStay::class, 'room_id');
    }

    public function isOutOfService(): bool
    {
        return $this->service_state === self::SERVICE_OUT || !$this->is_active;
    }
}
