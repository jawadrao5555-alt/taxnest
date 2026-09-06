<?php

namespace App\Services\HealthAudit\Checks;

use App\Services\HealthAudit\HealthAuditContext;
use App\Services\HealthAudit\HealthAuditRecorder;
use App\Services\HealthAudit\HealthAuditRules;
use Illuminate\Support\Facades\DB;

/**
 * Access, sign-in, export and trail-integrity checks (Task 1554).
 *
 * The other check classes read the hospital's own tables. These read the audit
 * trail itself, which makes them the only rules that can report on things that
 * leave no operational record at all — somebody opening a confidential file,
 * somebody downloading a register, somebody's rights being widened at 2am.
 *
 * The last rule is the one that watches the watchman: it re-derives every
 * event's hash for the period and reports rows that no longer match, or whose
 * ancestor has gone missing.
 */
class AccessChecks extends BaseChecks
{
    /**
     * Events that change what a PERSON is allowed to do.
     *
     * Deliberately narrower than the whole `access` category. Adding a doctor
     * to the catalogue or opening a new department is also filed under access,
     * but neither hands anybody a right they did not have — raising them here
     * would bury the one event that matters (somebody's rights widening) under
     * ordinary master-data upkeep.
     */
    public const PERMISSION_EVENT_PREFIXES = [
        'access.permissions',
        'access.staff',
        'access.user',
        'access.role',
        'staff.',
        'permission.',
    ];

    /** Rights granted, withdrawn or re-shaped. */
    public static function permissionChanged(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_audit_events')) {
            return [];
        }

        $query = DB::table('health_audit_events')
            ->where('company_id', $ctx->companyId)
            ->where('category', 'access')
            ->where(function ($q) {
                foreach (self::PERMISSION_EVENT_PREFIXES as $prefix) {
                    $q->orWhere('event', 'like', $prefix . '%');
                }
            })
            ->whereBetween('occurred_at', [$ctx->fromStart(), $ctx->toEnd()]);

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applySubject($query, ['actor_user_id']);

        $rows = $query->orderBy('occurred_at')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->occurred_at),
            'branch_id' => $row->branch_id,
            'subject_user_id' => $row->actor_user_id,
            'subject_name' => $row->actor_name,
            'entity_type' => 'health_audit_events',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->entity_label ?: $row->event),
            'params' => [
                'what' => (string) $row->event,
                'target' => (string) ($row->entity_label ?: '—'),
                'by' => (string) ($row->actor_name ?: '—'),
                'at' => (string) $row->occurred_at,
            ],
            'evidence' => [
                'event' => self::eventEvidence($row),
                'link' => self::link('health.team', [], 'staff.manage'),
            ],
        ], $rows->all());
    }

    /**
     * A confidential patient file opened.
     *
     * Reported to whoever holds audit.view, as an ACT — who, when, which file
     * number. Not the file. The whole reason the auditor role exists without
     * clinical.view is that an audit of the money must not become a second
     * doorway into the medicine.
     */
    public static function sensitiveRecordViewed(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_audit_events')) {
            return [];
        }

        $query = DB::table('health_audit_events')
            ->where('company_id', $ctx->companyId)
            ->where('is_sensitive', true)
            ->whereBetween('occurred_at', [$ctx->fromStart(), $ctx->toEnd()]);

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applySubject($query, ['actor_user_id']);

        $rows = $query->orderBy('occurred_at')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        $mrns = self::patientMrns($rows->pluck('health_patient_id')->all());

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->occurred_at),
            'branch_id' => $row->branch_id,
            'subject_user_id' => $row->actor_user_id,
            'subject_name' => $row->actor_name,
            'entity_type' => 'health_audit_events',
            'entity_id' => (int) $row->id,
            'entity_label' => $mrns[(int) $row->health_patient_id] ?? (string) $row->event,
            'params' => [
                'by' => (string) ($row->actor_name ?: '—'),
                'role' => (string) ($row->actor_role ?: '—'),
                'mrn' => $mrns[(int) $row->health_patient_id] ?? '—',
                'at' => (string) $row->occurred_at,
            ],
            'evidence' => [
                'event' => self::eventEvidence($row) + [
                    // The file NUMBER, never the patient's name. An auditor
                    // proving a file was opened does not need to be told whose.
                    'patient_mrn' => $mrns[(int) $row->health_patient_id] ?? null,
                ],
                'link' => null,
            ],
        ], $rows->all());
    }

    /**
     * A run of failed sign-ins against one account in one day.
     *
     * Almost always a forgotten password. Occasionally not, and the difference
     * is only visible if somebody counts.
     */
    public static function failedLoginBurst(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_audit_events')) {
            return [];
        }

        $threshold = HealthAuditRules::FAILED_LOGIN_BURST;

        $query = DB::table('health_audit_events')
            ->selectRaw('DATE(occurred_at) as day, actor_user_id, MAX(actor_name) as actor_name, COUNT(*) as attempts, MIN(occurred_at) as first_at, MAX(occurred_at) as last_at, MAX(ip_address) as ip_address')
            ->where('company_id', $ctx->companyId)
            ->where('event', 'auth.login.failed')
            ->whereBetween('occurred_at', [$ctx->fromStart(), $ctx->toEnd()])
            ->groupBy('day', 'actor_user_id')
            ->havingRaw('COUNT(*) >= ' . self::num($threshold));

        // A login attempt carries no branch or ward, so both fences let it
        // through as organisation-wide; applying them anyway keeps the rule
        // honest the day a login event IS stamped with one.
        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);

        if ($ctx->subjectUserId) {
            $query->where('actor_user_id', $ctx->subjectUserId);
        }

        $rows = $query->orderBy('day')->orderBy('actor_user_id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->day),
            'subject_user_id' => $row->actor_user_id,
            'subject_name' => $row->actor_name,
            'entity_type' => 'users',
            'entity_id' => (int) ($row->actor_user_id ?? 0),
            'entity_label' => (string) ($row->actor_name ?: '—'),
            'params' => [
                'who' => (string) ($row->actor_name ?: '—'),
                'attempts' => (int) $row->attempts,
                'date' => self::dateOnly($row->day),
                'threshold' => $threshold,
            ],
            'evidence' => [
                'sign_in_attempts' => [
                    'account' => $row->actor_name,
                    'date' => self::dateOnly($row->day),
                    'attempts' => (int) $row->attempts,
                    'first_at' => $row->first_at,
                    'last_at' => $row->last_at,
                    'last_ip' => $row->ip_address,
                ],
                'threshold' => ['failed_attempts_at_or_above' => $threshold],
                'link' => null,
            ],
        ], $rows->all());
    }

    /** Registers and reports taken out of the system. */
    public static function dataExported(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_audit_events')) {
            return [];
        }

        $query = DB::table('health_audit_events')
            ->where('company_id', $ctx->companyId)
            ->where('category', 'export')
            ->whereBetween('occurred_at', [$ctx->fromStart(), $ctx->toEnd()]);

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applySubject($query, ['actor_user_id']);

        $rows = $query->orderBy('occurred_at')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->occurred_at),
            'branch_id' => $row->branch_id,
            'subject_user_id' => $row->actor_user_id,
            'subject_name' => $row->actor_name,
            'entity_type' => 'health_audit_events',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->entity_label ?: $row->event),
            'params' => [
                'what' => (string) ($row->entity_label ?: $row->event),
                'by' => (string) ($row->actor_name ?: '—'),
                'at' => (string) $row->occurred_at,
            ],
            'evidence' => [
                'event' => self::eventEvidence($row),
                'link' => null,
            ],
        ], $rows->all());
    }

    /**
     * The trail itself does not add up.
     *
     * Critical, always, and the one finding that is not about the hospital's
     * work at all: it says the record of that work has been tampered with or
     * cut, and therefore that every OTHER finding in this run is standing on
     * something that moved.
     */
    public static function auditTrailBreak(HealthAuditContext $ctx): array
    {
        $result = HealthAuditRecorder::verifyChain($ctx->companyId, $ctx->from, $ctx->to);

        // Three ways the trail can lie: a row that no longer matches its hash,
        // a row whose predecessor is gone, and a tail that was cut off — which
        // only the signed anchor can see. Any one of them is the finding.
        if (!empty($result['intact'])) {
            return [];
        }

        return [[
            'occurred_on' => $ctx->to,
            'entity_type' => 'health_audit_events',
            'entity_id' => null,
            'entity_label' => $ctx->from . ' → ' . $ctx->to,
            'params' => [
                'altered' => $result['altered'],
                'missing' => $result['missing'],
                'checked' => $result['checked'],
                'anchor' => $result['anchor']['status'] ?? 'unknown',
            ],
            'evidence' => [
                'integrity' => $result,
                'link' => null,
            ],
        ]];
    }

    /** The stored event, shaped for evidence. Clinical content never appears. */
    protected static function eventEvidence($row): array
    {
        return [
            'id' => (int) $row->id,
            'event' => $row->event,
            'action' => $row->action,
            'occurred_at' => $row->occurred_at,
            'actor' => $row->actor_name,
            'actor_role' => $row->actor_role,
            'entity' => trim(($row->entity_type ?? '') . ' ' . ($row->entity_id ?? '')),
            'entity_label' => $row->entity_label,
            'reason' => $row->reason,
            'source' => $row->source,
            'ip_address' => $row->ip_address,
            'route' => $row->route,
            'before' => self::decode($row->old_values),
            'after' => self::decode($row->new_values),
            'sha256' => $row->sha256_hash,
        ];
    }

    protected static function decode($json)
    {
        if (!$json) {
            return null;
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** patient id => MRN. Names are deliberately not fetched. */
    protected static function patientMrns(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (empty($ids) || self::tableMissing('health_patients')) {
            return [];
        }

        return DB::table('health_patients')
            ->whereIn('id', $ids)
            ->pluck('mrn', 'id')
            ->map(fn ($m) => (string) $m)
            ->all();
    }
}
