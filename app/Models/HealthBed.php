<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The bed — the unit the whole inpatient module counts.
 *
 * `status` is the live truth the bed board renders and `health_admission_id` is
 * who is in it right now. Both are written inside the same transaction as the
 * admission move (HealthIpdService), never inferred afterwards, so a bed can
 * never read "available" while somebody is lying in it.
 *
 * Note the deliberate absence of a "free the bed" convenience method: releasing
 * a bed is a decision about a STAY, so it belongs to the service that owns the
 * stay, under the same lock. A model helper would let any caller quietly orphan
 * an occupied bed.
 */
class HealthBed extends Model
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_CLEANING = 'cleaning';
    public const STATUS_BLOCKED = 'blocked';

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_OCCUPIED,
        self::STATUS_RESERVED,
        self::STATUS_CLEANING,
        self::STATUS_BLOCKED,
    ];

    /** Statuses staff may set by hand on the bed board. */
    public const MANUAL_STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_CLEANING,
        self::STATUS_BLOCKED,
    ];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_ward_id',
        'health_room_id',
        'code',
        'label',
        'daily_rate',
        'nursing_daily_rate',
        'status',
        'health_admission_id',
        'reserved_for_admission_id',
        'status_note',
        'status_changed_at',
        'is_active',
    ];

    protected $casts = [
        'daily_rate' => 'decimal:2',
        'nursing_daily_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'status_changed_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_ward_id' => 'integer',
        'health_room_id' => 'integer',
        'health_admission_id' => 'integer',
        'reserved_for_admission_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function ward()
    {
        return $this->belongsTo(HealthWard::class, 'health_ward_id');
    }

    public function room()
    {
        return $this->belongsTo(HealthRoom::class, 'health_room_id');
    }

    public function admission()
    {
        return $this->belongsTo(HealthAdmission::class, 'health_admission_id');
    }

    public static function statusLabelKey(?string $status): string
    {
        return 'health.bed_status_' . (in_array($status, self::STATUSES, true) ? $status : 'blocked');
    }

    /** Free for a new patient right now. */
    public function isAssignable(): bool
    {
        return (bool) $this->is_active && $this->status === self::STATUS_AVAILABLE;
    }

    /**
     * The bed-day rate that actually applies: bed → room → ward.
     *
     * NULL at a level means "inherit", which is why this walks up rather than
     * falling back to zero. A bed whose whole chain is unpriced returns 0.00 and
     * the ward setup screen flags it — an unpriced bed is a setup bug, and it is
     * better seen on the setup screen than discovered on a discharge bill.
     *
     * Relations are read via the loaded relation when present so the bed board
     * (hundreds of beds) does not fire a query per bed.
     */
    public function resolvedDailyRate(): float
    {
        return $this->resolveRate('daily_rate');
    }

    public function resolvedNursingRate(): float
    {
        return $this->resolveRate('nursing_daily_rate');
    }

    private function resolveRate(string $column): float
    {
        $own = $this->getAttributeValue($column);
        if ($own !== null && $own !== '') {
            return (float) $own;
        }

        $room = $this->relationLoaded('room') ? $this->getRelation('room') : $this->room;
        if ($room) {
            $value = $room->getAttributeValue($column);
            if ($value !== null && $value !== '') {
                return (float) $value;
            }
        }

        $ward = $this->relationLoaded('ward') ? $this->getRelation('ward') : $this->ward;
        if ($ward) {
            $value = $ward->getAttributeValue($column);
            if ($value !== null && $value !== '') {
                return (float) $value;
            }
        }

        return 0.0;
    }
}
