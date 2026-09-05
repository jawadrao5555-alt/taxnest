<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One weekly sitting for a doctor: "Mondays, 09:00–13:00, Clifton branch".
 *
 * weekday follows Carbon (0 = Sunday … 6 = Saturday) so nothing has to convert
 * between two week conventions. `max_tokens` caps that sitting's walk-in queue;
 * 0 means the clinic does not cap it.
 */
class HealthDoctorSlot extends Model
{
    public const WEEKDAYS = [0, 1, 2, 3, 4, 5, 6];

    protected $fillable = [
        'company_id',
        'health_doctor_id',
        'branch_id',
        'weekday',
        'start_time',
        'end_time',
        'slot_minutes',
        'max_tokens',
        'is_active',
    ];

    protected $casts = [
        'weekday' => 'integer',
        'slot_minutes' => 'integer',
        'max_tokens' => 'integer',
        'is_active' => 'boolean',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_doctor_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function doctor()
    {
        return $this->belongsTo(HealthDoctor::class, 'health_doctor_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public static function weekdayLabelKey(int $weekday): string
    {
        return 'health.weekday_' . max(0, min(6, $weekday));
    }

    /** "09:00 – 13:00", tolerant of both H:i and H:i:s storage. */
    public function getTimeRangeAttribute(): string
    {
        return substr((string) $this->start_time, 0, 5) . ' – ' . substr((string) $this->end_time, 0, 5);
    }
}
