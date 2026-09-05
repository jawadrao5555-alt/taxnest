<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The computed attendance day — derived, never typed.
 *
 * Recomputed from the roster and the counted punches whenever the day, the
 * policy or an approved correction changes, EXCEPT when the month is locked
 * (is_locked) or an approved override froze the row (is_manual). That is the
 * whole contract: fixing a policy repairs unpaid history automatically and
 * cannot rewrite a month payroll already went out on.
 */
class HealthAttendanceDay extends Model
{
    public const STATUSES = [
        'present', 'half_day', 'absent', 'leave', 'holiday',
        'weekly_off', 'on_call', 'missed_punch', 'exempt',
    ];

    /** Statuses that count as a payable day in the payroll handoff. */
    public const PAID_STATUSES = ['present', 'on_call', 'holiday', 'weekly_off', 'exempt'];

    /** Exception flags stored in the JSON column. */
    public const FLAG_OVERNIGHT    = 'overnight';
    public const FLAG_SPLIT        = 'split';
    public const FLAG_CROSS_BRANCH = 'cross_branch';
    public const FLAG_OPEN_SPAN    = 'open_span';
    public const FLAG_MISSED_PUNCH = 'missed_punch';
    public const FLAG_LATE         = 'late';
    public const FLAG_EARLY_LEAVE  = 'early_leave';
    public const FLAG_OVERTIME     = 'overtime';
    public const FLAG_UNSCHEDULED  = 'unscheduled';
    public const FLAG_CORRECTED    = 'corrected';

    protected $fillable = [
        'company_id', 'user_id', 'attendance_date',
        'health_shift_id', 'branch_id', 'health_department_id',
        'shift_start', 'shift_end', 'first_in', 'last_out',
        'scheduled_minutes', 'worked_minutes', 'break_minutes',
        'late_minutes', 'early_leave_minutes', 'overtime_minutes',
        'status', 'exceptions', 'punch_count', 'is_open', 'cross_branch',
        'leave_request_id', 'is_manual', 'correction_id', 'computed_at', 'is_locked',
    ];

    protected $casts = [
        // date:Y-m-d, never a bare 'date': a bare date cast STORES
        // "2026-03-31 00:00:00", which silently falls outside a
        // whereBetween('2026-03-01','2026-03-31') month window and never
        // matches an exact-date lookup. The duty date is a calendar day.
        'attendance_date' => 'date:Y-m-d',
        'shift_start'     => 'datetime',
        'shift_end'       => 'datetime',
        'first_in'        => 'datetime',
        'last_out'        => 'datetime',
        'exceptions'      => 'array',
        'is_open'         => 'boolean',
        'cross_branch'    => 'boolean',
        'is_manual'       => 'boolean',
        'is_locked'       => 'boolean',
        'computed_at'     => 'datetime',
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

    public function isPayable(): bool
    {
        return in_array($this->status, self::PAID_STATUSES, true);
    }

    public function flags(): array
    {
        return is_array($this->exceptions) ? $this->exceptions : [];
    }

    public static function statusLabelKey(?string $status): string
    {
        return 'health.hr_day_status_' . (in_array($status, self::STATUSES, true) ? $status : 'absent');
    }

    public static function flagLabelKey(string $flag): string
    {
        return 'health.hr_flag_' . $flag;
    }

    /** "8h 25m" — one formatting decision for every screen and export. */
    public static function hoursLabel(?int $minutes): string
    {
        $minutes = max(0, (int) $minutes);

        return intdiv($minutes, 60) . 'h ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . 'm';
    }
}
