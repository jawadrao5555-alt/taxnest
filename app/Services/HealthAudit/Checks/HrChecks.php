<?php

namespace App\Services\HealthAudit\Checks;

use App\Services\HealthAudit\HealthAuditContext;
use App\Services\HealthAudit\HealthAuditRules;
use Illuminate\Support\Facades\DB;

/**
 * Attendance checks (Task 1554).
 *
 * Attendance is the quietest place in an ERP for money to move, because nobody
 * thinks of a corrected punch as a payment. It is: the payroll is built from
 * these rows. So the rules here are not about lateness — they are about the
 * three ways a day can become something other than what the clock recorded.
 */
class HrChecks extends BaseChecks
{
    /** A day typed in rather than punched. */
    public static function attendanceManualDay(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_attendance_days')) {
            return [];
        }

        $query = DB::table('health_attendance_days')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('attendance_date', [$ctx->from, $ctx->to])
            ->where('is_manual', true);

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applySubject($query, ['user_id']);

        $rows = $query->orderBy('attendance_date')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->attendance_date),
            'branch_id' => $row->branch_id,
            'health_department_id' => $row->health_department_id,
            'subject_user_id' => $row->user_id,
            'subject_name' => self::userName($row->user_id),
            'entity_type' => 'health_attendance_days',
            'entity_id' => (int) $row->id,
            'entity_label' => self::dateOnly($row->attendance_date) . ' · ' . (self::userName($row->user_id) ?? '#' . $row->user_id),
            'params' => [
                'staff' => self::userName($row->user_id) ?? '—',
                'date' => self::dateOnly($row->attendance_date),
                'status' => (string) $row->status,
            ],
            'evidence' => [
                'attendance_day' => [
                    'id' => (int) $row->id,
                    'user' => self::userName($row->user_id),
                    'date' => self::dateOnly($row->attendance_date),
                    'status' => $row->status,
                    'first_in' => $row->first_in,
                    'last_out' => $row->last_out,
                    'worked_minutes' => (int) $row->worked_minutes,
                    'is_manual' => true,
                    'correction_id' => $row->correction_id,
                    'is_locked' => (bool) $row->is_locked,
                ],
                'link' => self::link(
                    'health.hr.attendance.day',
                    ['userId' => (int) $row->user_id, 'date' => self::dateOnly($row->attendance_date)],
                    'hr.attendance.view'
                ),
            ],
        ], $rows->all());
    }

    /**
     * Somebody approved their own attendance correction.
     *
     * The requester and the reviewer being one person is the textbook broken
     * control: nothing else about the record has to be wrong for the approval
     * to be worthless.
     */
    public static function attendanceSelfApproved(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_attendance_corrections')) {
            return [];
        }

        $query = DB::table('health_attendance_corrections')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('attendance_date', [$ctx->from, $ctx->to])
            ->where('status', 'approved')
            ->whereNotNull('reviewed_by')
            ->whereColumn('reviewed_by', 'requested_by');

        // The correction row carries neither branch nor ward; the staff
        // member it corrects is posted somewhere, and that posting is the fence.
        self::applyStaffPosting($ctx, $query, 'health_attendance_corrections.user_id');
        $ctx->applySubject($query, ['user_id', 'requested_by', 'reviewed_by']);

        $rows = $query->orderBy('attendance_date')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->attendance_date),
            'subject_user_id' => $row->reviewed_by,
            'subject_name' => self::userName($row->reviewed_by),
            'entity_type' => 'health_attendance_corrections',
            'entity_id' => (int) $row->id,
            'entity_label' => '#' . $row->id,
            'params' => [
                'staff' => self::userName($row->user_id) ?? '—',
                'by' => self::userName($row->reviewed_by) ?? '—',
                'date' => self::dateOnly($row->attendance_date),
            ],
            'evidence' => [
                'correction' => [
                    'id' => (int) $row->id,
                    'for_user' => self::userName($row->user_id),
                    'attendance_date' => self::dateOnly($row->attendance_date),
                    'type' => $row->type,
                    'requested_status' => $row->requested_status,
                    'requested_minutes' => $row->requested_minutes,
                    'reason' => $row->reason,
                    'requested_by' => self::userName($row->requested_by),
                    'reviewed_by' => self::userName($row->reviewed_by),
                    'reviewed_at' => $row->reviewed_at,
                    'review_note' => $row->review_note,
                    'applied_at' => $row->applied_at,
                ],
                'link' => self::link('health.hr.corrections', [], 'hr.attendance.view'),
            ],
        ], $rows->all());
    }

    /** A recorded punch struck off the day. */
    public static function attendancePunchDisregarded(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_attendance_punches')) {
            return [];
        }

        $query = DB::table('health_attendance_punches')
            ->where('company_id', $ctx->companyId)
            ->whereNotNull('disregarded_at')
            ->whereBetween('disregarded_at', [$ctx->fromStart(), $ctx->toEnd()]);

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applySubject($query, ['user_id', 'disregarded_by']);

        $rows = $query->orderBy('disregarded_at')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->disregarded_at),
            'branch_id' => $row->branch_id,
            'health_department_id' => $row->health_department_id,
            'subject_user_id' => $row->disregarded_by,
            'subject_name' => self::userName($row->disregarded_by),
            'entity_type' => 'health_attendance_punches',
            'entity_id' => (int) $row->id,
            'entity_label' => '#' . $row->id,
            'severity' => ($row->disregard_reason === null || $row->disregard_reason === '') ? 'critical' : 'warning',
            'params' => [
                'staff' => self::userName($row->user_id) ?? '—',
                'by' => self::userName($row->disregarded_by) ?? '—',
                'date' => self::dateOnly($row->punched_at),
            ],
            'evidence' => [
                'punch' => [
                    'id' => (int) $row->id,
                    'user' => self::userName($row->user_id),
                    'punched_at' => $row->punched_at,
                    'direction' => $row->direction,
                    'source' => $row->source,
                    'device_id' => $row->device_id,
                    'disregarded_at' => $row->disregarded_at,
                    'disregarded_by' => self::userName($row->disregarded_by),
                    'disregard_reason' => $row->disregard_reason ?: null,
                ],
                'link' => self::link(
                    'health.hr.attendance.day',
                    ['userId' => (int) $row->user_id, 'date' => self::dateOnly($row->punched_at)],
                    'hr.attendance.view'
                ),
            ],
        ], $rows->all());
    }
}
