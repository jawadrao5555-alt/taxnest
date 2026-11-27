<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HrLeaveRequest extends Model
{
    protected $fillable = [
        'organization_id', 'employee_id', 'requested_by', 'request_key', 'starts_on', 'ends_on', 'category',
        'emergency', 'employee_note', 'status', 'decided_by', 'decided_at_utc', 'decision_note', 'policy_version_id',
    ];

    protected $casts = ['emergency' => 'boolean'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
