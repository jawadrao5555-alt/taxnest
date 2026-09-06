<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One press of "Run audit" (Task 1554).
 *
 * Holds the scope somebody asked about, the ruleset version that answered, and
 * the totals — never the answer's prose. The findings are separate rows so a
 * run stays a small, listable thing even when it produced thousands of them.
 */
class HealthAuditRun extends Model
{
    public const ACTIVE_STATUSES = ['pending', 'running'];
    public const PACK_ACTIVE_STATUSES = ['pending', 'building'];

    /** How long a built pack stays on disk before cleanup removes it. */
    public const PACK_RETENTION_DAYS = 14;

    protected $fillable = [
        'company_id',
        'user_id',
        'actor_name',
        'actor_role',
        'date_from',
        'date_to',
        'preset',
        'branch_id',
        'health_department_id',
        'health_doctor_id',
        'subject_user_id',
        'scope_branch_ids',
        'scope_department_ids',
        'ruleset_version',
        'status',
        'progress',
        'rules_run',
        'rules_failed',
        'findings_total',
        'findings_critical',
        'findings_warning',
        'findings_info',
        'events_scanned',
        'risk_score',
        'filters_hash',
        'result_hash',
        'duration_ms',
        'error_message',
        'started_at',
        'completed_at',
        'pack_status',
        'pack_progress',
        'pack_path',
        'pack_size',
        'pack_sha256',
        'pack_signature',
        'pack_generated_at',
        'pack_locked_at',
        'pack_error',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'scope_branch_ids' => 'array',
        'scope_department_ids' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'pack_generated_at' => 'datetime',
        'pack_locked_at' => 'datetime',
    ];

    public function findings()
    {
        return $this->hasMany(HealthAuditFinding::class, 'health_audit_run_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function packIsActive(): bool
    {
        return in_array((string) $this->pack_status, self::PACK_ACTIVE_STATUSES, true);
    }

    public function packExpiresAt(): ?\Illuminate\Support\Carbon
    {
        return $this->pack_generated_at
            ? $this->pack_generated_at->copy()->addDays(self::PACK_RETENTION_DAYS)
            : null;
    }

    /**
     * Risk band for the score. Deliberately three words, not a number with a
     * decimal point: the owner is being told where to look, not given a grade.
     */
    public function riskBand(): string
    {
        if ($this->findings_critical > 0 || $this->risk_score < 60) {
            return 'high';
        }

        if ($this->findings_warning > 0 || $this->risk_score < 85) {
            return 'moderate';
        }

        return 'clear';
    }

    /**
     * Whether a reader confined to these branches / departments may open this
     * run. NULL on the run means "every branch"; NULL on the reader means "may
     * read every branch". A run computed over MORE than the reader may see is
     * refused whole — showing a subset would leave the headline totals lying
     * about what the visible list contains.
     */
    public function readableWithin(?array $branchIds, ?array $departmentIds): bool
    {
        return self::scopeWithin($this->scope_branch_ids, $branchIds)
            && self::scopeWithin($this->scope_department_ids, $departmentIds);
    }

    public static function scopeWithin(?array $runScope, ?array $readerScope): bool
    {
        if ($readerScope === null) {
            return true;                       // reader is unconfined
        }
        if ($runScope === null) {
            return false;                      // run was organisation-wide, reader is not
        }

        $reader = array_map('intval', $readerScope);
        foreach ($runScope as $id) {
            if (!in_array((int) $id, $reader, true)) {
                return false;
            }
        }

        return true;
    }
}
