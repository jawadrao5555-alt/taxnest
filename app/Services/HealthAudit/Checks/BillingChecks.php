<?php

namespace App\Services\HealthAudit\Checks;

use App\Services\HealthAudit\HealthAuditContext;
use App\Services\HealthAudit\HealthAuditRules;
use Illuminate\Support\Facades\DB;

/**
 * Billing checks (Task 1554).
 *
 * Everything the hospital charged, forgave, changed its mind about, or never
 * got round to charging at all. Three of these rules exist in pairs — the act
 * itself as a warning, the act WITHOUT A RECORDED REASON as critical — because
 * a reversal is normal hospital work and a reversal nobody wrote a reason for
 * is the one an auditor has no way to close.
 */
class BillingChecks extends BaseChecks
{
    /** A completed consultation whose fee was never collected or waived. */
    public static function visitFeeNotCollected(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_visits')) {
            return [];
        }

        $query = DB::table('health_visits')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('visit_date', [$ctx->from, $ctx->to])
            ->where('status', 'completed')
            ->where('fee_status', 'pending')
            ->where('net_fee', '>', 0);

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applyDoctor($query);
        $ctx->applySubject($query, ['opened_by', 'closed_by']);

        $rows = $query->orderBy('visit_date')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->visit_date),
            'branch_id' => $row->branch_id,
            'health_department_id' => $row->health_department_id,
            'health_doctor_id' => $row->health_doctor_id,
            'subject_user_id' => $row->closed_by ?: $row->opened_by,
            'subject_name' => self::userName($row->closed_by ?: $row->opened_by),
            'entity_type' => 'health_visits',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->visit_no ?: $row->id),
            'amount' => $row->net_fee,
            'params' => [
                'visit' => (string) ($row->visit_no ?: $row->id),
                'amount' => self::money($row->net_fee),
                'date' => self::dateOnly($row->visit_date),
            ],
            'evidence' => [
                'visit' => [
                    'id' => (int) $row->id,
                    'visit_no' => $row->visit_no,
                    'date' => self::dateOnly($row->visit_date),
                    'fee_amount' => self::money($row->fee_amount),
                    'concession_amount' => self::money($row->concession_amount),
                    'net_fee' => self::money($row->net_fee),
                    'fee_status' => $row->fee_status,
                    'doctor' => self::doctorName($row->health_doctor_id),
                ],
                'link' => self::link('health.clinical.visit', ['id' => (int) $row->id], 'clinical.view'),
            ],
        ], $rows->all());
    }

    /**
     * A consultation fee written off with nothing written down.
     *
     * Waiving a fee is a normal act of a hospital. Waiving it without recording
     * why is the act that cannot be checked afterwards, so the finding is about
     * the missing sentence, not about the money.
     */
    public static function visitFeeWaivedNoReason(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_visits')) {
            return [];
        }

        $query = DB::table('health_visits')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('visit_date', [$ctx->from, $ctx->to])
            ->where(function ($q) {
                $q->where('fee_status', 'waived')
                    ->orWhere('concession_amount', '>', 0);
            })
            ->where(function ($q) {
                $q->whereNull('concession_reason')->orWhere('concession_reason', '');
            });

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applyDoctor($query);
        $ctx->applySubject($query, ['opened_by', 'closed_by', 'fee_collected_by']);

        $rows = $query->orderBy('visit_date')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->visit_date),
            'branch_id' => $row->branch_id,
            'health_department_id' => $row->health_department_id,
            'health_doctor_id' => $row->health_doctor_id,
            'subject_user_id' => $row->fee_collected_by ?: $row->closed_by,
            'subject_name' => self::userName($row->fee_collected_by ?: $row->closed_by),
            'entity_type' => 'health_visits',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->visit_no ?: $row->id),
            'amount' => $row->concession_amount ?: $row->fee_amount,
            'params' => [
                'visit' => (string) ($row->visit_no ?: $row->id),
                'amount' => self::money($row->concession_amount ?: $row->fee_amount),
                'date' => self::dateOnly($row->visit_date),
            ],
            'evidence' => [
                'visit' => [
                    'id' => (int) $row->id,
                    'visit_no' => $row->visit_no,
                    'fee_amount' => self::money($row->fee_amount),
                    'concession_amount' => self::money($row->concession_amount),
                    'concession_reason' => null,
                    'fee_status' => $row->fee_status,
                ],
                'link' => self::link('health.clinical.visit', ['id' => (int) $row->id], 'clinical.view'),
            ],
        ], $rows->all());
    }

    /** Charges taken back off a patient's account inside the period. */
    public static function chargeReversed(HealthAuditContext $ctx): array
    {
        return self::reversedCharges($ctx, false);
    }

    /** The same, but with no reason recorded — the ones nobody can close. */
    public static function chargeReversedNoReason(HealthAuditContext $ctx): array
    {
        return self::reversedCharges($ctx, true);
    }

    protected static function reversedCharges(HealthAuditContext $ctx, bool $onlyWithoutReason): array
    {
        if (self::tableMissing('health_charges')) {
            return [];
        }

        $query = DB::table('health_charges')
            ->where('company_id', $ctx->companyId)
            ->whereNotNull('reversed_at')
            ->whereBetween('reversed_at', [$ctx->fromStart(), $ctx->toEnd()]);

        if ($onlyWithoutReason) {
            $query->where(function ($q) {
                $q->whereNull('reversal_reason')->orWhere('reversal_reason', '');
            });
        } else {
            $query->whereNotNull('reversal_reason')->where('reversal_reason', '!=', '');
        }

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applySubject($query, ['created_by', 'reversed_by']);

        $rows = $query->orderBy('reversed_at')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->reversed_at),
            'branch_id' => $row->branch_id,
            'health_department_id' => $row->health_department_id,
            'subject_user_id' => $row->reversed_by,
            'subject_name' => self::userName($row->reversed_by),
            'entity_type' => 'health_charges',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->charge_no ?: $row->id),
            'amount' => $row->total_amount,
            'params' => [
                'charge' => (string) ($row->charge_no ?: $row->id),
                'amount' => self::money($row->total_amount),
                'by' => self::userName($row->reversed_by) ?? '—',
                'date' => self::dateOnly($row->reversed_at),
            ],
            'evidence' => [
                'charge' => [
                    'id' => (int) $row->id,
                    'charge_no' => $row->charge_no,
                    'charge_date' => self::dateOnly($row->charge_date),
                    'category' => $row->category,
                    'description' => $row->description,
                    'total_amount' => self::money($row->total_amount),
                    'posted_by' => self::userName($row->created_by),
                    'reversed_at' => $row->reversed_at,
                    'reversed_by' => self::userName($row->reversed_by),
                    'reversal_reason' => $row->reversal_reason ?: null,
                    'health_bill_id' => $row->health_bill_id,
                ],
                'link' => $row->health_bill_id
                    ? self::link('health.billing.bill', ['id' => (int) $row->health_bill_id], 'billing.view')
                    : null,
            ],
        ], $rows->all());
    }

    /** A discount large enough that somebody should be able to explain it. */
    public static function chargeHighConcession(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_charges')) {
            return [];
        }

        $pct = HealthAuditRules::CONCESSION_ALERT_PCT;

        $query = DB::table('health_charges')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('charge_date', [$ctx->from, $ctx->to])
            ->where('concession_amount', '>', 0)
            ->where('gross_amount', '>', 0)
            ->whereRaw('(concession_amount / gross_amount) * 100 >= ' . self::num($pct));

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applySubject($query, ['created_by', 'concession_approved_by']);

        $rows = $query->orderByDesc(DB::raw('concession_amount / gross_amount'))
            ->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(function ($row) use ($pct) {
            $share = $row->gross_amount > 0
                ? round(((float) $row->concession_amount / (float) $row->gross_amount) * 100, 1)
                : 0.0;

            return [
                'occurred_on' => self::dateOnly($row->charge_date),
                'branch_id' => $row->branch_id,
                'health_department_id' => $row->health_department_id,
                'subject_user_id' => $row->concession_approved_by ?: $row->created_by,
                'subject_name' => self::userName($row->concession_approved_by ?: $row->created_by),
                'entity_type' => 'health_charges',
                'entity_id' => (int) $row->id,
                'entity_label' => (string) ($row->charge_no ?: $row->id),
                'amount' => $row->concession_amount,
                'params' => [
                    'charge' => (string) ($row->charge_no ?: $row->id),
                    'amount' => self::money($row->concession_amount),
                    'percent' => $share,
                    'threshold' => $pct,
                ],
                'evidence' => [
                    'charge' => [
                        'id' => (int) $row->id,
                        'charge_no' => $row->charge_no,
                        'charge_date' => self::dateOnly($row->charge_date),
                        'description' => $row->description,
                        'gross_amount' => self::money($row->gross_amount),
                        'concession_amount' => self::money($row->concession_amount),
                        'concession_percent' => $share,
                        'concession_reason' => $row->concession_reason,
                        'approved_by' => self::userName($row->concession_approved_by),
                        'posted_by' => self::userName($row->created_by),
                    ],
                    'threshold' => ['concession_percent_at_or_above' => $pct],
                    'link' => $row->health_bill_id
                        ? self::link('health.billing.bill', ['id' => (int) $row->health_bill_id], 'billing.view')
                        : null,
                ],
            ];
        }, $rows->all());
    }

    /**
     * A discount nobody signed for.
     *
     * The person who grants a concession and the person who approves it are
     * meant to be two people. A concession with no approver is a discount the
     * hospital gave itself.
     */
    public static function chargeConcessionUnapproved(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_charges')) {
            return [];
        }

        $query = DB::table('health_charges')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('charge_date', [$ctx->from, $ctx->to])
            ->where('concession_amount', '>', 0)
            ->where(function ($q) {
                $q->whereNull('concession_approved_by')
                    ->orWhereColumn('concession_approved_by', 'created_by');
            });

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applySubject($query, ['created_by', 'concession_approved_by']);

        $rows = $query->orderBy('charge_date')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->charge_date),
            'branch_id' => $row->branch_id,
            'health_department_id' => $row->health_department_id,
            'subject_user_id' => $row->created_by,
            'subject_name' => self::userName($row->created_by),
            'entity_type' => 'health_charges',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->charge_no ?: $row->id),
            'amount' => $row->concession_amount,
            'params' => [
                'charge' => (string) ($row->charge_no ?: $row->id),
                'amount' => self::money($row->concession_amount),
                'by' => self::userName($row->created_by) ?? '—',
            ],
            'evidence' => [
                'charge' => [
                    'id' => (int) $row->id,
                    'charge_no' => $row->charge_no,
                    'charge_date' => self::dateOnly($row->charge_date),
                    'gross_amount' => self::money($row->gross_amount),
                    'concession_amount' => self::money($row->concession_amount),
                    'concession_reason' => $row->concession_reason,
                    'posted_by' => self::userName($row->created_by),
                    'approved_by' => self::userName($row->concession_approved_by),
                    'self_approved' => $row->concession_approved_by
                        && (int) $row->concession_approved_by === (int) $row->created_by,
                ],
                'link' => $row->health_bill_id
                    ? self::link('health.billing.bill', ['id' => (int) $row->health_bill_id], 'billing.view')
                    : null,
            ],
        ], $rows->all());
    }

    /**
     * A live charge with no bill behind it, old enough that the delay is not
     * simply "the patient is still in the building".
     */
    public static function chargeUnbilled(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_charges')) {
            return [];
        }

        $cutoff = \Illuminate\Support\Carbon::parse($ctx->to)
            ->subDays(HealthAuditRules::UNBILLED_AGE_DAYS)->toDateString();

        $query = DB::table('health_charges')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('charge_date', [$ctx->from, $cutoff])
            ->where('status', 'posted')
            ->whereNull('health_bill_id')
            ->whereNull('reversed_at')
            ->where('total_amount', '>', 0);

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applySubject($query, ['created_by']);

        $rows = $query->orderBy('charge_date')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->charge_date),
            'branch_id' => $row->branch_id,
            'health_department_id' => $row->health_department_id,
            'subject_user_id' => $row->created_by,
            'subject_name' => self::userName($row->created_by),
            'entity_type' => 'health_charges',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->charge_no ?: $row->id),
            'amount' => $row->total_amount,
            'params' => [
                'charge' => (string) ($row->charge_no ?: $row->id),
                'amount' => self::money($row->total_amount),
                'days' => HealthAuditRules::UNBILLED_AGE_DAYS,
                'date' => self::dateOnly($row->charge_date),
            ],
            'evidence' => [
                'charge' => [
                    'id' => (int) $row->id,
                    'charge_no' => $row->charge_no,
                    'charge_date' => self::dateOnly($row->charge_date),
                    'category' => $row->category,
                    'description' => $row->description,
                    'total_amount' => self::money($row->total_amount),
                    'status' => $row->status,
                    'health_bill_id' => null,
                    'posted_by' => self::userName($row->created_by),
                ],
                'threshold' => ['unbilled_for_days_at_least' => HealthAuditRules::UNBILLED_AGE_DAYS],
                'link' => $row->health_patient_id
                    ? self::link('health.billing.patient', ['id' => (int) $row->health_patient_id], 'billing.view')
                    : null,
            ],
        ], $rows->all());
    }

    /** The inpatient twin of chargeReversed — a stay's charge taken back off. */
    public static function admissionChargeReversed(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_admission_charges')) {
            return [];
        }

        $query = DB::table('health_admission_charges')
            ->where('company_id', $ctx->companyId)
            ->whereNotNull('reversed_at')
            ->whereBetween('reversed_at', [$ctx->fromStart(), $ctx->toEnd()]);

        $ctx->applyBranch($query);
        $ctx->applyDepartmentVia($query, 'health_admissions', 'health_admission_charges.health_admission_id');
        $ctx->applySubject($query, ['created_by', 'reversed_by']);

        $rows = $query->orderBy('reversed_at')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->reversed_at),
            'branch_id' => $row->branch_id,
            'subject_user_id' => $row->reversed_by,
            'subject_name' => self::userName($row->reversed_by),
            'entity_type' => 'health_admission_charges',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->reference ?: ('#' . $row->id)),
            'amount' => $row->net_amount,
            'severity' => ($row->reversal_reason === null || $row->reversal_reason === '') ? 'critical' : 'warning',
            'params' => [
                'amount' => self::money($row->net_amount),
                'by' => self::userName($row->reversed_by) ?? '—',
                'date' => self::dateOnly($row->reversed_at),
            ],
            'evidence' => [
                'admission_charge' => [
                    'id' => (int) $row->id,
                    'health_admission_id' => $row->health_admission_id,
                    'charge_date' => self::dateOnly($row->charge_date),
                    'category' => $row->category,
                    'description' => $row->description,
                    'net_amount' => self::money($row->net_amount),
                    'reversed_at' => $row->reversed_at,
                    'reversed_by' => self::userName($row->reversed_by),
                    'reversal_reason' => $row->reversal_reason ?: null,
                ],
                'link' => $row->health_admission_id
                    ? self::link('health.ipd.show', ['id' => (int) $row->health_admission_id], 'ipd.view')
                    : null,
            ],
        ], $rows->all());
    }

    /**
     * A bill cancelled after money had already been taken against it.
     *
     * The single strongest signal in the whole ruleset. Perfectly legitimate
     * when the refund is there too — and exactly what it looks like when it is
     * not.
     */
    public static function billCancelledAfterPayment(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_bills')) {
            return [];
        }

        $query = DB::table('health_bills')
            ->where('company_id', $ctx->companyId)
            ->where('status', 'cancelled')
            ->whereNotNull('cancelled_at')
            ->whereBetween('cancelled_at', [$ctx->fromStart(), $ctx->toEnd()])
            ->where('paid_amount', '>', 0);

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applySubject($query, ['created_by', 'cancelled_by', 'finalized_by']);

        $rows = $query->orderBy('cancelled_at')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(function ($row) {
            $unrefunded = round((float) $row->paid_amount - (float) $row->refunded_amount, 2);

            return [
                'occurred_on' => self::dateOnly($row->cancelled_at),
                'branch_id' => $row->branch_id,
                'health_department_id' => $row->health_department_id,
                'subject_user_id' => $row->cancelled_by,
                'subject_name' => self::userName($row->cancelled_by),
                'entity_type' => 'health_bills',
                'entity_id' => (int) $row->id,
                'entity_label' => (string) ($row->bill_no ?: $row->id),
                'amount' => $row->paid_amount,
                'variance' => $unrefunded,
                'params' => [
                    'bill' => (string) ($row->bill_no ?: $row->id),
                    'paid' => self::money($row->paid_amount),
                    'refunded' => self::money($row->refunded_amount),
                    'by' => self::userName($row->cancelled_by) ?? '—',
                ],
                'evidence' => [
                    'bill' => [
                        'id' => (int) $row->id,
                        'bill_no' => $row->bill_no,
                        'bill_date' => self::dateOnly($row->bill_date),
                        'total_amount' => self::money($row->total_amount),
                        'paid_amount' => self::money($row->paid_amount),
                        'refunded_amount' => self::money($row->refunded_amount),
                        'unrefunded' => self::money($unrefunded),
                        'cancelled_at' => $row->cancelled_at,
                        'cancelled_by' => self::userName($row->cancelled_by),
                        'cancel_reason' => $row->cancel_reason,
                    ],
                    'link' => self::link('health.billing.bill', ['id' => (int) $row->id], 'billing.view'),
                ],
            ];
        }, $rows->all());
    }

    /** Money handed back. Normal, and worth a list. */
    public static function billRefunded(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_bills')) {
            return [];
        }

        $query = DB::table('health_bills')
            ->where('company_id', $ctx->companyId)
            ->where('refunded_amount', '>', 0)
            ->whereBetween('bill_date', [$ctx->from, $ctx->to]);

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applySubject($query, ['created_by', 'finalized_by', 'settled_by']);

        $rows = $query->orderByDesc('refunded_amount')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->bill_date),
            'branch_id' => $row->branch_id,
            'health_department_id' => $row->health_department_id,
            'entity_type' => 'health_bills',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->bill_no ?: $row->id),
            'amount' => $row->refunded_amount,
            'params' => [
                'bill' => (string) ($row->bill_no ?: $row->id),
                'amount' => self::money($row->refunded_amount),
                'date' => self::dateOnly($row->bill_date),
            ],
            'evidence' => [
                'bill' => [
                    'id' => (int) $row->id,
                    'bill_no' => $row->bill_no,
                    'total_amount' => self::money($row->total_amount),
                    'paid_amount' => self::money($row->paid_amount),
                    'refunded_amount' => self::money($row->refunded_amount),
                    'status' => $row->status,
                ],
                'link' => self::link('health.billing.bill', ['id' => (int) $row->id], 'billing.view'),
            ],
        ], $rows->all());
    }

    /**
     * The bill says one thing, the receipts say another.
     *
     * Compares the bill's own paid_amount against the sum of the live payments
     * filed under it. They should never disagree; when they do, one of the two
     * screens the hospital trusts is lying and the difference is real money.
     */
    public static function billPaymentMismatch(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_bills', 'health_payments')) {
            return [];
        }

        $tolerance = HealthAuditRules::MONEY_TOLERANCE;

        // A refund is filed as a payment with kind='refund', so it has to come
        // back OFF the received total — otherwise every refunded bill would
        // read as a mismatch. The literal is inlined rather than bound because
        // a bound placeholder inside a joined sub-select re-orders the
        // bindings under it.
        $paid = DB::table('health_payments')
            ->select('health_bill_id', DB::raw("SUM(CASE WHEN kind = 'refund' THEN -amount ELSE amount END) as received"))
            ->where('company_id', $ctx->companyId)
            ->whereNull('reversed_at')
            ->whereNotNull('health_bill_id')
            ->groupBy('health_bill_id');

        $query = DB::table('health_bills as b')
            ->leftJoinSub($paid, 'p', 'p.health_bill_id', '=', 'b.id')
            ->where('b.company_id', $ctx->companyId)
            ->whereBetween('b.bill_date', [$ctx->from, $ctx->to])
            ->whereIn('b.status', ['finalized', 'settled'])
            ->whereRaw('ABS(COALESCE(b.paid_amount,0) - COALESCE(p.received,0)) > ' . self::num($tolerance))
            ->select('b.*', DB::raw('COALESCE(p.received,0) as received'));

        $ctx->applyBranch($query, 'b.branch_id');
        $ctx->applyDepartment($query, 'b.health_department_id');
        $ctx->applySubject($query, ['b.created_by', 'b.finalized_by', 'b.settled_by']);

        $rows = $query->orderBy('b.bill_date')->orderBy('b.id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(function ($row) use ($tolerance) {
            $variance = round((float) $row->paid_amount - (float) $row->received, 2);

            return [
                'occurred_on' => self::dateOnly($row->bill_date),
                'branch_id' => $row->branch_id,
                'health_department_id' => $row->health_department_id,
                'subject_user_id' => $row->settled_by ?: $row->finalized_by,
                'subject_name' => self::userName($row->settled_by ?: $row->finalized_by),
                'entity_type' => 'health_bills',
                'entity_id' => (int) $row->id,
                'entity_label' => (string) ($row->bill_no ?: $row->id),
                'amount' => $row->paid_amount,
                'variance' => $variance,
                'params' => [
                    'bill' => (string) ($row->bill_no ?: $row->id),
                    'bill_says' => self::money($row->paid_amount),
                    'receipts_say' => self::money($row->received),
                    'variance' => self::money($variance),
                ],
                'evidence' => [
                    'bill' => [
                        'id' => (int) $row->id,
                        'bill_no' => $row->bill_no,
                        'bill_date' => self::dateOnly($row->bill_date),
                        'status' => $row->status,
                        'total_amount' => self::money($row->total_amount),
                        'paid_amount_on_bill' => self::money($row->paid_amount),
                        'sum_of_live_receipts' => self::money($row->received),
                        'difference' => self::money($variance),
                    ],
                    'threshold' => ['tolerance' => $tolerance],
                    'link' => self::link('health.billing.bill', ['id' => (int) $row->id], 'billing.view'),
                ],
            ];
        }, $rows->all());
    }
}
