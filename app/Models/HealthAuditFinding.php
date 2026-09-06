<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Something a rule noticed (Task 1554).
 *
 * A finding is NEVER a verdict. It carries the exact rows it was derived from
 * so the owner reads the source and decides; the only thing the panel claims is
 * "this combination is worth a look". Severity says how hard to look, not how
 * guilty anybody is.
 */
class HealthAuditFinding extends Model
{
    public const SEVERITIES = ['critical', 'warning', 'info'];

    public const STATUSES = ['open', 'acknowledged', 'investigating', 'resolved', 'false_positive'];

    /** Statuses that no longer need the owner's attention. */
    public const CLOSED_STATUSES = ['resolved', 'false_positive'];

    protected $fillable = [
        'company_id',
        'health_audit_run_id',
        'rule_key',
        'rule_version',
        'category',
        'severity',
        'occurred_on',
        'branch_id',
        'health_department_id',
        'health_doctor_id',
        'subject_user_id',
        'subject_name',
        'entity_type',
        'entity_id',
        'entity_label',
        'amount',
        'variance',
        'params',
        'evidence',
        'fingerprint',
        'status',
        'status_note',
        'status_by',
        'status_by_name',
        'status_at',
    ];

    protected $casts = [
        'occurred_on' => 'date',
        'status_at' => 'datetime',
        'params' => 'array',
        'evidence' => 'array',
        'amount' => 'decimal:2',
        'variance' => 'decimal:2',
    ];

    public function run()
    {
        return $this->belongsTo(HealthAuditRun::class, 'health_audit_run_id');
    }

    public function notes()
    {
        return $this->hasMany(HealthAuditNote::class, 'health_audit_finding_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /** The finding's sentence, in the reader's language. */
    public function message(): string
    {
        return __('health.audit_rule_' . $this->rule_key . '_msg', $this->params ?? []);
    }

    public function titleKey(): string
    {
        return 'health.audit_rule_' . $this->rule_key;
    }
}
