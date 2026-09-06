<?php

namespace App\Services\HealthAudit\Checks;

use App\Services\HealthAudit\HealthAuditContext;
use App\Services\HealthAudit\HealthAuditRules;
use Illuminate\Support\Facades\DB;

/**
 * Clinical-activity checks (Task 1554).
 *
 * These are the "missing link" rules: a patient who was seen but whose visit
 * was never opened, a theatre case that finished without a charge behind it,
 * the same person registered twice under two file numbers. None of them
 * accuses anybody of anything — every one of them is routinely explained by a
 * busy afternoon — but each is a place where money or a record can go missing
 * without a single deliberate act.
 */
class ClinicalChecks extends BaseChecks
{
    /**
     * An appointment marked completed with no visit behind it.
     *
     * The clinical record for that consultation does not exist, and neither
     * does the consultation fee it would have carried.
     */
    public static function appointmentNoVisit(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_appointments')) {
            return [];
        }

        $query = DB::table('health_appointments')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('appointment_date', [$ctx->from, $ctx->to])
            ->whereIn('status', ['completed', 'in_consultation'])
            ->whereNull('health_visit_id');

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applyDoctor($query);
        $ctx->applySubject($query, ['created_by']);

        $rows = $query->orderBy('appointment_date')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)
            ->get();

        $findings = [];
        foreach ($rows as $row) {
            $findings[] = [
                'occurred_on' => self::dateOnly($row->appointment_date),
                'branch_id' => $row->branch_id,
                'health_department_id' => $row->health_department_id,
                'health_doctor_id' => $row->health_doctor_id,
                'subject_user_id' => $row->created_by,
                'subject_name' => self::userName($row->created_by),
                'entity_type' => 'health_appointments',
                'entity_id' => (int) $row->id,
                'entity_label' => $row->token_no ? ('#' . $row->token_no) : ('#' . $row->id),
                'params' => [
                    'date' => self::dateOnly($row->appointment_date),
                    'doctor' => self::doctorName($row->health_doctor_id) ?? '—',
                    'status' => $row->status,
                ],
                'evidence' => [
                    'appointment' => [
                        'id' => (int) $row->id,
                        'date' => self::dateOnly($row->appointment_date),
                        'time' => $row->appointment_time,
                        'status' => $row->status,
                        'checked_in_at' => $row->checked_in_at,
                        'completed_at' => $row->completed_at,
                        'health_visit_id' => null,
                    ],
                    'link' => self::link('health.appointments', ['date' => self::dateOnly($row->appointment_date)], 'appointments.view'),
                ],
            ];
        }

        return $findings;
    }

    /**
     * A visit still open after the period ended.
     *
     * Informational on purpose. A consultation left in "waiting" overnight is
     * almost always somebody forgetting to close a screen — but it is also what
     * an unbilled consultation looks like, so it is worth counting rather than
     * hiding.
     */
    public static function visitLeftOpen(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_visits')) {
            return [];
        }

        $query = DB::table('health_visits')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('visit_date', [$ctx->from, $ctx->to])
            ->whereIn('status', ['waiting', 'in_consultation']);

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applyDoctor($query);
        $ctx->applySubject($query, ['opened_by']);

        $rows = $query->orderBy('visit_date')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)
            ->get();

        $findings = [];
        foreach ($rows as $row) {
            $findings[] = [
                'occurred_on' => self::dateOnly($row->visit_date),
                'branch_id' => $row->branch_id,
                'health_department_id' => $row->health_department_id,
                'health_doctor_id' => $row->health_doctor_id,
                'subject_user_id' => $row->opened_by,
                'subject_name' => self::userName($row->opened_by),
                'entity_type' => 'health_visits',
                'entity_id' => (int) $row->id,
                'entity_label' => (string) ($row->visit_no ?: $row->id),
                'amount' => $row->net_fee,
                'params' => [
                    'visit' => (string) ($row->visit_no ?: $row->id),
                    'date' => self::dateOnly($row->visit_date),
                    'status' => $row->status,
                ],
                'evidence' => [
                    'visit' => [
                        'id' => (int) $row->id,
                        'visit_no' => $row->visit_no,
                        'date' => self::dateOnly($row->visit_date),
                        'status' => $row->status,
                        'fee_status' => $row->fee_status,
                        'net_fee' => self::money($row->net_fee),
                    ],
                    'link' => self::link('health.clinical.visit', ['id' => (int) $row->id], 'clinical.view'),
                ],
            ];
        }

        return $findings;
    }

    /**
     * The same person registered twice.
     *
     * Matched on the two identifiers a hospital front desk actually re-types:
     * the CNIC and the digits-only phone. A duplicate file splits one patient's
     * history in half and lets the same treatment be billed twice without
     * anybody noticing, so it is reported even when it is obviously innocent.
     *
     * Only groups TOUCHED in the period are reported — otherwise every audit
     * for the rest of the hospital's life would re-report the same pair.
     */
    public static function duplicatePatient(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_patients')) {
            return [];
        }

        $findings = [];

        foreach ([
            ['column' => 'cnic', 'match' => 'cnic'],
            ['column' => 'phone_digits', 'match' => 'phone'],
        ] as $pair) {
            $column = $pair['column'];

            $groups = DB::table('health_patients')
                ->select($column, DB::raw('COUNT(*) as total'), DB::raw('MAX(created_at) as latest'))
                ->where('company_id', $ctx->companyId)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->havingRaw('MAX(created_at) >= ? AND MAX(created_at) <= ?', [$ctx->fromStart(), $ctx->toEnd()])
                ->orderBy($column)
                ->limit(HealthAuditRules::PER_RULE_CAP)
                ->get();

            foreach ($groups as $group) {
                $members = DB::table('health_patients')
                    ->where('company_id', $ctx->companyId)
                    ->where($column, $group->{$column})
                    ->orderBy('id')
                    ->limit(20)
                    ->get(['id', 'mrn', 'branch_id', 'created_at']);

                // A patient file has no ward, so only the branch fence applies —
                // and it applies to the MEMBERS, not to the group: a reader
                // confined to one branch is told about the copies inside their
                // own fence and nothing about a copy elsewhere. Fewer than two
                // visible copies is therefore no finding for that reader; the
                // owner's organisation-wide run still raises it.
                $visible = $members->filter(function ($m) use ($ctx) {
                    $branch = $m->branch_id === null ? null : (int) $m->branch_id;
                    if ($ctx->branchId) {
                        return $branch === $ctx->branchId;
                    }
                    if (is_array($ctx->branchBoundary)) {
                        return $branch === null || in_array($branch, $ctx->branchBoundary, true);
                    }

                    return true;
                })->values();

                if ($visible->count() < 2) {
                    continue;
                }

                $first = $visible->first();

                $findings[] = [
                    'occurred_on' => self::dateOnly($group->latest),
                    'branch_id' => $first->branch_id ?? null,
                    'entity_type' => 'health_patients',
                    'entity_id' => (int) ($first->id ?? 0),
                    'entity_label' => (string) ($first->mrn ?? ''),
                    'params' => [
                        'count' => $visible->count(),
                        'match' => $pair['match'],
                        'mrns' => $visible->pluck('mrn')->filter()->take(6)->implode(', '),
                    ],
                    'evidence' => [
                        'matched_on' => $pair['match'],
                        // Neither the identifier that matched nor the person's
                        // name is copied into the evidence. The pack's contract
                        // is registration numbers only: an auditor without
                        // clinical access reads "two files, same CNIC" and
                        // takes the MRNs to the front desk — not the names.
                        'patients' => $visible->map(fn ($m) => [
                            'id' => (int) $m->id,
                            'mrn' => $m->mrn,
                            'registered_on' => self::dateOnly($m->created_at),
                        ])->all(),
                        'link' => self::link('health.patients.duplicates', [], 'patients.view'),
                    ],
                ];
            }
        }

        return $findings;
    }

    /**
     * A theatre case that finished without its charge being posted.
     *
     * The single largest thing a hospital can forget to bill.
     */
    public static function operationNoCharge(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_operations')) {
            return [];
        }

        $query = DB::table('health_operations')
            ->where('company_id', $ctx->companyId)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$ctx->fromStart(), $ctx->toEnd()])
            ->whereNull('charge_posted_at');

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applySubject($query, ['created_by', 'completed_by']);

        if ($ctx->doctorId) {
            $query->where('primary_surgeon_id', $ctx->doctorId);
        } elseif (is_array($ctx->doctorBoundary)) {
            $query->whereIn('primary_surgeon_id', $ctx->doctorBoundary ?: [0]);
        }

        $rows = $query->orderBy('completed_at')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)
            ->get();

        $findings = [];
        foreach ($rows as $row) {
            $findings[] = [
                'occurred_on' => self::dateOnly($row->completed_at),
                'branch_id' => $row->branch_id,
                'health_department_id' => $row->health_department_id,
                'health_doctor_id' => $row->primary_surgeon_id,
                'subject_user_id' => $row->completed_by,
                'subject_name' => self::userName($row->completed_by),
                'entity_type' => 'health_operations',
                'entity_id' => (int) $row->id,
                'entity_label' => (string) ($row->operation_no ?: $row->id),
                'amount' => $row->price,
                'params' => [
                    'operation' => (string) ($row->operation_no ?: $row->id),
                    'date' => self::dateOnly($row->completed_at),
                    'amount' => self::money($row->price),
                ],
                'evidence' => [
                    'operation' => [
                        'id' => (int) $row->id,
                        'operation_no' => $row->operation_no,
                        // The case TITLE is clinical. Withheld here on purpose;
                        // the operation number is enough to find the record.
                        'completed_at' => $row->completed_at,
                        'price' => self::money($row->price),
                        'charge_posted_at' => null,
                        'surgeon' => self::doctorName($row->primary_surgeon_id),
                    ],
                    'link' => self::link('health.operations.show', ['id' => (int) $row->id], 'operations.view'),
                ],
            ];
        }

        return $findings;
    }
}
