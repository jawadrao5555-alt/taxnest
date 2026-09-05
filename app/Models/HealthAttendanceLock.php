<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A locked attendance month.
 *
 * Locking is what turns computed attendance into approved attendance: every
 * day in the period is stamped is_locked, the per-staff totals are snapshotted
 * into `totals`, and the payroll handoff will only export a locked month.
 * Unlocking is recorded too — the pair of stamps is the audit answer to
 * "who reopened March?".
 */
class HealthAttendanceLock extends Model
{
    protected $fillable = [
        'company_id', 'period_year', 'period_month',
        'locked_by', 'locked_at', 'note', 'totals',
        'unlocked_by', 'unlocked_at',
    ];

    protected $casts = [
        'totals'      => 'array',
        'locked_at'   => 'datetime',
        'unlocked_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** A row exists for every lock ever made; only an un-unlocked one binds. */
    public function isActive(): bool
    {
        return $this->locked_at !== null && $this->unlocked_at === null;
    }
}
