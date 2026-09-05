<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The employment record that hangs off an existing healthcare user account.
 *
 * Deliberately NOT a second identity: the login, the name, the role, the branch
 * postings and the department postings all stay on the user the foundation
 * already created. This table only adds what employment needs — designation,
 * status, joining date, supervisor, work pattern and the two payroll input
 * rates — so a person can never exist twice with two different spellings.
 */
class HealthStaffProfile extends Model
{
    /** How somebody is engaged. Drives nothing on its own; reports group by it. */
    public const EMPLOYMENT_TYPES = ['permanent', 'contract', 'visiting', 'locum', 'intern', 'daily_wage'];

    /** Where somebody is in their employment lifecycle. */
    public const EMPLOYMENT_STATUSES = ['active', 'probation', 'notice', 'suspended', 'left'];

    /** Statuses that are still on duty and therefore still rostered. */
    public const WORKING_STATUSES = ['active', 'probation', 'notice'];

    protected $fillable = [
        'company_id', 'user_id',
        'employee_code', 'designation', 'employment_type', 'employment_status',
        'joined_on', 'left_on',
        'branch_id', 'supervisor_user_id', 'default_shift_id',
        'weekly_off_days', 'attendance_exempt', 'overtime_eligible',
        'basic_salary', 'overtime_hourly_rate',
        'qualification', 'license_no', 'cnic', 'emergency_contact', 'notes',
    ];

    protected $casts = [
        'joined_on'          => 'date:Y-m-d',
        'left_on'            => 'date:Y-m-d',
        'weekly_off_days'    => 'array',
        'attendance_exempt'  => 'boolean',
        'overtime_eligible'  => 'boolean',
        'basic_salary'       => 'decimal:2',
        'overtime_hourly_rate' => 'decimal:2',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function defaultShift()
    {
        return $this->belongsTo(HealthShift::class, 'default_shift_id');
    }

    public function isWorking(): bool
    {
        return in_array($this->employment_status, self::WORKING_STATUSES, true);
    }

    public static function typeLabelKey(?string $type): string
    {
        return 'health.hr_emp_type_' . (in_array($type, self::EMPLOYMENT_TYPES, true) ? $type : 'permanent');
    }

    public static function statusLabelKey(?string $status): string
    {
        return 'health.hr_emp_status_' . (in_array($status, self::EMPLOYMENT_STATUSES, true) ? $status : 'active');
    }
}
