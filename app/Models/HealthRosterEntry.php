<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One person, one date, one duty. The roster is the SCHEDULE — what somebody
 * was supposed to do — and the attendance day is the EVIDENCE of what happened.
 * Keeping them apart is what lets "late" and "absent" mean anything.
 */
class HealthRosterEntry extends Model
{
    /** shift = on duty, off = weekly off, on_call = reachable, leave/holiday = approved absence. */
    public const TYPES = ['shift', 'off', 'on_call', 'leave', 'holiday'];

    /** Types that put somebody physically on the floor for coverage counting. */
    public const COVERING_TYPES = ['shift'];

    protected $fillable = [
        'company_id', 'user_id', 'duty_date', 'health_shift_id',
        'branch_id', 'health_department_id', 'entry_type', 'notes', 'created_by',
    ];

    protected $casts = [
        'duty_date' => 'date:Y-m-d',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shift()
    {
        return $this->belongsTo(HealthShift::class, 'health_shift_id');
    }

    public function department()
    {
        return $this->belongsTo(HealthDepartment::class, 'health_department_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public static function typeLabelKey(?string $type): string
    {
        return 'health.hr_roster_type_' . (in_array($type, self::TYPES, true) ? $type : 'shift');
    }
}
