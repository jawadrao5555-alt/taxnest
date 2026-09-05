<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A kind of leave (casual, sick, annual, unpaid…). Per company, because the
 * quota a clinic gives is not the quota a hospital gives.
 */
class HealthLeaveType extends Model
{
    /** Seeded for a company the first time the leave screen is opened. */
    public const SEED = [
        ['code' => 'casual',    'name_key' => 'health.hr_leave_type_casual',    'annual_quota_days' => 10, 'is_paid' => true],
        ['code' => 'sick',      'name_key' => 'health.hr_leave_type_sick',      'annual_quota_days' => 8,  'is_paid' => true],
        ['code' => 'annual',    'name_key' => 'health.hr_leave_type_annual',    'annual_quota_days' => 14, 'is_paid' => true],
        ['code' => 'unpaid',    'name_key' => 'health.hr_leave_type_unpaid',    'annual_quota_days' => 0,  'is_paid' => false],
        ['code' => 'maternity', 'name_key' => 'health.hr_leave_type_maternity', 'annual_quota_days' => 90, 'is_paid' => true],
    ];

    protected $fillable = [
        'company_id', 'name', 'code', 'annual_quota_days',
        'is_paid', 'requires_approval', 'is_active',
    ];

    protected $casts = [
        'annual_quota_days' => 'decimal:1',
        'is_paid'           => 'boolean',
        'requires_approval' => 'boolean',
        'is_active'         => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

}
