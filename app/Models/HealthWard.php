<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A ward: the largest unit of the inpatient facility.
 *
 * Carries the DEFAULT bed-day and nursing-day rates. A room or an individual
 * bed may override them, but a ward always has an answer — a stay must never be
 * charged nothing because somebody forgot to price a bed.
 *
 * `gender_policy` is here rather than on the bed because it is a ward-level
 * decision everywhere: the last free bed in the women's ward is not a bed the
 * board should offer for a male patient.
 */
class HealthWard extends Model
{
    public const TYPES = [
        'general', 'private', 'semi_private', 'icu', 'nicu', 'hdu',
        'isolation', 'maternity', 'emergency', 'daycare', 'other',
    ];

    public const GENDER_POLICIES = ['any', 'male', 'female'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_department_id',
        'name',
        'code',
        'type',
        'gender_policy',
        'floor',
        'daily_rate',
        'nursing_daily_rate',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'daily_rate' => 'decimal:2',
        'nursing_daily_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_department_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function department()
    {
        return $this->belongsTo(HealthDepartment::class, 'health_department_id');
    }

    public function rooms()
    {
        return $this->hasMany(HealthRoom::class, 'health_ward_id');
    }

    public function beds()
    {
        return $this->hasMany(HealthBed::class, 'health_ward_id');
    }

    public static function isType(?string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function typeLabelKey(?string $type): string
    {
        return 'health.ward_type_' . (self::isType($type) ? $type : 'other');
    }

    /**
     * May a patient of this gender occupy a bed in this ward?
     *
     * An unknown / unrecorded patient gender is allowed through: refusing to
     * admit somebody because a field is blank is not a safety rule, it is a
     * data-entry rule, and the ward sister will not thank us for it.
     */
    public function acceptsGender(?string $gender): bool
    {
        $policy = $this->gender_policy ?: 'any';
        if ($policy === 'any') {
            return true;
        }

        $gender = strtolower((string) $gender);
        if ($gender === '' || !in_array($gender, ['male', 'female'], true)) {
            return true;
        }

        return $gender === $policy;
    }
}
