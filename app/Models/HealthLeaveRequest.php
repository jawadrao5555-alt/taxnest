<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A leave application and its single review decision.
 *
 * The review trail is append-only in spirit: an approved or rejected request is
 * never re-reviewed, it is cancelled and re-filed, so "who allowed this" always
 * has exactly one answer.
 */
class HealthLeaveRequest extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $fillable = [
        'company_id', 'user_id', 'health_leave_type_id',
        'start_date', 'end_date', 'days', 'is_half_day', 'reason',
        'status', 'created_by', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'start_date'  => 'date:Y-m-d',
        'end_date'    => 'date:Y-m-d',
        'days'        => 'decimal:1',
        'is_half_day' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function leaveType()
    {
        return $this->belongsTo(HealthLeaveType::class, 'health_leave_type_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending';
    }

    public static function statusLabelKey(?string $status): string
    {
        return 'health.hr_leave_status_' . (in_array($status, self::STATUSES, true) ? $status : 'pending');
    }
}
