<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ONE normalised attendance timeline — the only evidence the calculation reads.
 *
 * Every capture method lands here in the same shape: the biometric device push
 * (mirrored from the shared pos_biometric_punches ingest), a web check-in, a
 * mobile check-in, an optional panel-session mirror, a CSV import and an
 * approved manual correction.
 *
 * APPEND-ONLY. Nothing edits or deletes a punch. A wrong punch is DISREGARDED
 * (disregarded_at + reason + the correction that did it) and stays on the
 * timeline, greyed out, forever — that is what makes the attendance record
 * evidence rather than an opinion.
 */
class HealthAttendancePunch extends Model
{
    public const DIRECTIONS = ['in', 'out', 'unknown'];

    /** Where a punch came from. Ordered by how much we trust it. */
    public const SOURCES = ['biometric', 'web', 'mobile', 'import', 'session', 'manual'];

    protected $fillable = [
        'company_id', 'user_id', 'punched_at', 'direction', 'source', 'source_ref',
        'branch_id', 'health_department_id', 'device_id', 'device_pin',
        'latitude', 'longitude', 'ip', 'note',
        'recorded_by', 'correction_id',
        'disregarded_at', 'disregarded_by', 'disregarded_correction_id', 'disregard_reason',
    ];

    protected $casts = [
        'punched_at'     => 'datetime',
        'disregarded_at' => 'datetime',
        'latitude'       => 'decimal:7',
        'longitude'      => 'decimal:7',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeCounted($query)
    {
        return $query->whereNull('disregarded_at');
    }

    public function isCounted(): bool
    {
        return $this->disregarded_at === null;
    }

    public static function sourceLabelKey(?string $source): string
    {
        return 'health.hr_punch_source_' . (in_array($source, self::SOURCES, true) ? $source : 'manual');
    }
}
