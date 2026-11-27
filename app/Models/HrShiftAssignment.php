<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class HrShiftAssignment extends Model
{
    protected $fillable = [
        'organization_id', 'employee_id', 'work_date', 'starts_at_utc', 'ends_at_utc',
        'timezone', 'grace_minutes', 'absent_after_minutes', 'policy_version_id', 'created_by',
    ];

    protected $casts = ['grace_minutes' => 'integer', 'absent_after_minutes' => 'integer'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function punches(): HasMany
    {
        return $this->hasMany(HrAttendancePunch::class, 'shift_id');
    }

    public function fieldDutyRequests(): HasMany
    {
        return $this->hasMany(HrFieldDutyRequest::class, 'shift_id');
    }

    public function localTime(string $timestamp): string
    {
        return CarbonImmutable::parse($timestamp, 'UTC')->setTimezone($this->timezone)->format('M j, Y H:i T');
    }
}
