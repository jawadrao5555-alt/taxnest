<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HrFieldDutyRequest extends Model
{
    protected $fillable = [
        'organization_id', 'shift_id', 'requested_by', 'request_key', 'policy_version_id',
        'starts_at_utc', 'ends_at_utc', 'timezone', 'assignment_note',
        'status', 'decided_by', 'decided_at_utc', 'decision_note',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(HrShiftAssignment::class, 'shift_id');
    }

    public function localTime(string $timestamp): string
    {
        return CarbonImmutable::parse($timestamp, 'UTC')->setTimezone($this->timezone)->format('M j, Y H:i T');
    }
}
