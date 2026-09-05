<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A request to change what a day says, with a mandatory reason and one
 * recorded decision.
 *
 * Nothing on this table touches the raw punches by itself — approving it is
 * what writes a manual punch or marks one disregarded, and the correction id
 * is stamped on whatever it produced, so every derived row can name the
 * approval that created it.
 */
class HealthAttendanceCorrection extends Model
{
    /**
     * add_punch      — a missed punch, added as a new manual punch row.
     * disregard_punch— a duplicate/wrong punch, kept but excluded from the maths.
     * set_status     — the day itself is wrong (present/absent/leave/…).
     * set_hours      — an agreed worked-minutes figure (field duty, camp, court).
     */
    public const TYPES = ['add_punch', 'disregard_punch', 'set_status', 'set_hours'];

    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'company_id', 'user_id', 'attendance_date', 'type',
        'punch_at', 'direction', 'target_punch_id',
        'requested_status', 'requested_minutes', 'reason', 'status',
        'requested_by', 'reviewed_by', 'reviewed_at', 'review_note', 'applied_at',
    ];

    protected $casts = [
        'attendance_date' => 'date:Y-m-d',
        'punch_at'        => 'datetime',
        'reviewed_at'     => 'datetime',
        'applied_at'      => 'datetime',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function typeLabelKey(?string $type): string
    {
        return 'health.hr_correction_type_' . (in_array($type, self::TYPES, true) ? $type : 'add_punch');
    }

    public static function statusLabelKey(?string $status): string
    {
        return 'health.hr_correction_status_' . (in_array($status, self::STATUSES, true) ? $status : 'pending');
    }
}
