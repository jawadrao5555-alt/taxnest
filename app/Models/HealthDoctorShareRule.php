<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How much of a charge belongs to the doctor who produced it (Task 1552).
 *
 * Resolution is MOST SPECIFIC WINS: a rule naming this doctor and this charge
 * category beats one naming only the category, which beats the organisation
 * default. `priority` breaks a genuine tie, so an owner can always force an
 * answer instead of discovering afterwards that the system quietly picked one
 * of two equally-good rules.
 *
 * `base` defaults to NET — after concession, before tax — for two reasons that
 * have cost hospitals real money elsewhere: a share of TOTAL hands the doctor a
 * slice of tax the hospital owes the regulator, and a share of GROSS makes the
 * hospital fund a concession out of its own margin while the doctor is paid on
 * a price nobody actually charged.
 */
class HealthDoctorShareRule extends Model
{
    public const BASIS_PERCENT = 'percent';
    public const BASIS_FIXED = 'fixed';
    public const BASES = [self::BASIS_PERCENT, self::BASIS_FIXED];

    public const BASE_NET = 'net';
    public const BASE_GROSS = 'gross';
    public const BASE_TOTAL = 'total';
    public const BASE_AMOUNTS = [self::BASE_NET, self::BASE_GROSS, self::BASE_TOTAL];

    /** 'all' plus every health_charges category. */
    public const CATEGORY_ALL = 'all';

    protected $fillable = [
        'company_id',
        'health_doctor_id',
        'health_department_id',
        'branch_id',
        'name',
        'charge_category',
        'basis',
        'value',
        'base',
        'min_amount',
        'max_amount',
        'effective_from',
        'effective_to',
        'priority',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'company_id' => 'integer',
        'health_doctor_id' => 'integer',
        'health_department_id' => 'integer',
        'branch_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function doctor()
    {
        return $this->belongsTo(HealthDoctor::class, 'health_doctor_id');
    }

    public function department()
    {
        return $this->belongsTo(HealthDepartment::class, 'health_department_id');
    }

    /**
     * How closely this rule matches — higher is more specific.
     *
     * Kept here rather than in the resolver so "which rule won" can be shown on
     * the screen with the same number the engine used.
     */
    public function specificity(): int
    {
        $score = 0;
        if ($this->health_doctor_id) {
            $score += 8;
        }
        if ($this->charge_category !== self::CATEGORY_ALL) {
            $score += 4;
        }
        if ($this->health_department_id) {
            $score += 2;
        }
        if ($this->branch_id) {
            $score += 1;
        }

        return $score;
    }

    public function isEffectiveOn(string $date): bool
    {
        if ($this->effective_from && $date < $this->effective_from->toDateString()) {
            return false;
        }
        if ($this->effective_to && $date > $this->effective_to->toDateString()) {
            return false;
        }

        return true;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
