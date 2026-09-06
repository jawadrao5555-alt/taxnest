<?php

namespace App\Services\HealthAudit\Checks;

use App\Services\HealthAudit\HealthAuditContext;
use App\Services\HealthAudit\HealthAuditRules;
use Illuminate\Support\Facades\DB;

/**
 * Ledger and doctor-payout checks (Task 1554).
 *
 * The accounting core (health_journals, health_doctor_shares and friends) is a
 * newer part of the panel than the clinic screens, so EVERY rule here is
 * guarded on the table existing. An organisation that has not switched the
 * books on gets no accounting findings — not a stack trace.
 */
class AccountsChecks extends BaseChecks
{
    /**
     * A billed charge in a category somebody has a share rule for, with no
     * accrual behind it.
     *
     * This is the doctor's side of "missing links": the consultant earned on a
     * charge and the payout ledger never heard about it. Deliberately limited
     * to categories where an ACTIVE rule exists, because a charge nobody has a
     * rule for is not a missing accrual, it is a charge nobody shares.
     */
    public static function doctorShareMissing(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_charges', 'health_doctor_shares', 'health_doctor_share_rules')) {
            return [];
        }

        $ruleRows = DB::table('health_doctor_share_rules')
            ->where('company_id', $ctx->companyId)
            ->where('is_active', true)
            ->get(['charge_category']);

        if ($ruleRows->isEmpty()) {
            return [];
        }

        $categories = $ruleRows->pluck('charge_category')->unique()->values()->all();
        $allCategories = in_array('all', $categories, true);

        $query = DB::table('health_charges as c')
            ->leftJoin('health_doctor_shares as s', 's.health_charge_id', '=', 'c.id')
            ->where('c.company_id', $ctx->companyId)
            ->whereBetween('c.charge_date', [$ctx->from, $ctx->to])
            ->where('c.status', 'billed')
            ->whereNull('c.reversed_at')
            ->where('c.net_amount', '>', 0)
            ->whereNull('s.id')
            ->select('c.*');

        if (!$allCategories) {
            $query->whereIn('c.category', $categories);
        }

        $ctx->applyBranch($query, 'c.branch_id');
        $ctx->applyDepartment($query, 'c.health_department_id');
        $ctx->applySubject($query, ['c.created_by']);

        $rows = $query->orderBy('c.charge_date')->orderBy('c.id')
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
            'amount' => $row->net_amount,
            'params' => [
                'charge' => (string) ($row->charge_no ?: $row->id),
                'category' => (string) $row->category,
                'amount' => self::money($row->net_amount),
            ],
            'evidence' => [
                'charge' => [
                    'id' => (int) $row->id,
                    'charge_no' => $row->charge_no,
                    'charge_date' => self::dateOnly($row->charge_date),
                    'category' => $row->category,
                    'net_amount' => self::money($row->net_amount),
                    'health_bill_id' => $row->health_bill_id,
                    'doctor_share_rows' => 0,
                ],
                'link' => self::link('health.accounts.shares', [], 'accounts.view'),
            ],
        ], $rows->all());
    }

    /**
     * A settlement that does not add up to the shares attached to it.
     *
     * The payout note says one figure; the accruals filed under it say another.
     * One of the two is what the doctor was actually owed.
     */
    public static function doctorSettlementVariance(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_doctor_settlements', 'health_doctor_shares')) {
            return [];
        }

        $tolerance = HealthAuditRules::MONEY_TOLERANCE;

        $attached = DB::table('health_doctor_shares')
            ->select('health_doctor_settlement_id', DB::raw('SUM(share_amount) as attached_total'), DB::raw('COUNT(*) as attached_count'))
            ->where('company_id', $ctx->companyId)
            ->whereNull('reversed_at')
            ->whereNotNull('health_doctor_settlement_id')
            ->groupBy('health_doctor_settlement_id');

        $query = DB::table('health_doctor_settlements as s')
            ->leftJoinSub($attached, 'a', 'a.health_doctor_settlement_id', '=', 's.id')
            ->where('s.company_id', $ctx->companyId)
            ->whereBetween('s.period_to', [$ctx->from, $ctx->to])
            ->whereNotIn('s.status', ['reversed'])
            ->whereRaw('ABS(COALESCE(s.gross_amount,0) - COALESCE(a.attached_total,0)) > ' . self::num($tolerance))
            ->select('s.*', DB::raw('COALESCE(a.attached_total,0) as attached_total'), DB::raw('COALESCE(a.attached_count,0) as attached_count'));

        $ctx->applyBranch($query, 's.branch_id');
        $ctx->applyDepartmentVia($query, 'health_doctors', 's.health_doctor_id');
        $ctx->applyDoctor($query, 's.health_doctor_id');
        $ctx->applySubject($query, ['s.created_by', 's.approved_by', 's.paid_by']);

        $rows = $query->orderBy('s.id')->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(function ($row) use ($tolerance) {
            $variance = round((float) $row->gross_amount - (float) $row->attached_total, 2);

            return [
                'occurred_on' => self::dateOnly($row->period_to),
                'branch_id' => $row->branch_id,
                'health_doctor_id' => $row->health_doctor_id,
                'subject_user_id' => $row->approved_by ?: $row->created_by,
                'subject_name' => self::userName($row->approved_by ?: $row->created_by),
                'entity_type' => 'health_doctor_settlements',
                'entity_id' => (int) $row->id,
                'entity_label' => (string) ($row->settlement_no ?: $row->id),
                'amount' => $row->gross_amount,
                'variance' => $variance,
                'params' => [
                    'settlement' => (string) ($row->settlement_no ?: $row->id),
                    'doctor' => self::doctorName($row->health_doctor_id) ?? '—',
                    'note_says' => self::money($row->gross_amount),
                    'shares_say' => self::money($row->attached_total),
                    'variance' => self::money($variance),
                ],
                'evidence' => [
                    'settlement' => [
                        'id' => (int) $row->id,
                        'settlement_no' => $row->settlement_no,
                        'doctor' => self::doctorName($row->health_doctor_id),
                        'period' => self::dateOnly($row->period_from) . ' → ' . self::dateOnly($row->period_to),
                        'status' => $row->status,
                        'share_count_recorded' => (int) $row->share_count,
                        'share_count_attached' => (int) $row->attached_count,
                        'gross_recorded' => self::money($row->gross_amount),
                        'gross_from_shares' => self::money($row->attached_total),
                        'difference' => self::money($variance),
                        'approved_by' => self::userName($row->approved_by),
                    ],
                    'threshold' => ['tolerance' => $tolerance],
                    'link' => self::link('health.accounts.settlements', ['highlight' => (int) $row->id], 'accounts.view'),
                ],
            ];
        }, $rows->all());
    }

    /** A doctor paid more than the settlement said they were owed. */
    public static function doctorSettlementOverpaid(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_doctor_settlements')) {
            return [];
        }

        $tolerance = HealthAuditRules::MONEY_TOLERANCE;

        $query = DB::table('health_doctor_settlements')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('period_to', [$ctx->from, $ctx->to])
            ->whereRaw('COALESCE(paid_amount,0) - COALESCE(net_amount,0) > ' . self::num($tolerance));

        $ctx->applyBranch($query);
        $ctx->applyDepartmentVia($query, 'health_doctors', 'health_doctor_settlements.health_doctor_id');
        $ctx->applyDoctor($query);
        $ctx->applySubject($query, ['created_by', 'approved_by', 'paid_by']);

        $rows = $query->orderBy('id')->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(function ($row) {
            $variance = round((float) $row->paid_amount - (float) $row->net_amount, 2);

            return [
                'occurred_on' => self::dateOnly($row->paid_at ?: $row->period_to),
                'branch_id' => $row->branch_id,
                'health_doctor_id' => $row->health_doctor_id,
                'subject_user_id' => $row->paid_by,
                'subject_name' => self::userName($row->paid_by),
                'entity_type' => 'health_doctor_settlements',
                'entity_id' => (int) $row->id,
                'entity_label' => (string) ($row->settlement_no ?: $row->id),
                'amount' => $row->paid_amount,
                'variance' => $variance,
                'params' => [
                    'settlement' => (string) ($row->settlement_no ?: $row->id),
                    'doctor' => self::doctorName($row->health_doctor_id) ?? '—',
                    'owed' => self::money($row->net_amount),
                    'paid' => self::money($row->paid_amount),
                    'variance' => self::money($variance),
                ],
                'evidence' => [
                    'settlement' => [
                        'id' => (int) $row->id,
                        'settlement_no' => $row->settlement_no,
                        'doctor' => self::doctorName($row->health_doctor_id),
                        'net_amount' => self::money($row->net_amount),
                        'paid_amount' => self::money($row->paid_amount),
                        'overpaid_by' => self::money($variance),
                        'paid_at' => $row->paid_at,
                        'paid_by' => self::userName($row->paid_by),
                        'pay_reference' => $row->pay_reference,
                    ],
                    'link' => self::link('health.accounts.settlements', ['highlight' => (int) $row->id], 'accounts.view'),
                ],
            ];
        }, $rows->all());
    }

    /** An accrual taken off a doctor's payout by hand. */
    public static function doctorShareExcluded(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_doctor_shares')) {
            return [];
        }

        $query = DB::table('health_doctor_shares')
            ->where('company_id', $ctx->companyId)
            ->whereNotNull('excluded_at')
            ->whereBetween('excluded_at', [$ctx->fromStart(), $ctx->toEnd()]);

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applyDoctor($query);
        $ctx->applySubject($query, ['excluded_by']);

        $rows = $query->orderBy('excluded_at')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->excluded_at),
            'branch_id' => $row->branch_id,
            'health_department_id' => $row->health_department_id,
            'health_doctor_id' => $row->health_doctor_id,
            'subject_user_id' => $row->excluded_by,
            'subject_name' => self::userName($row->excluded_by),
            'entity_type' => 'health_doctor_shares',
            'entity_id' => (int) $row->id,
            'entity_label' => '#' . $row->id,
            'amount' => $row->share_amount,
            'severity' => ($row->exclusion_reason === null || $row->exclusion_reason === '') ? 'critical' : 'warning',
            'params' => [
                'doctor' => self::doctorName($row->health_doctor_id) ?? '—',
                'amount' => self::money($row->share_amount),
                'by' => self::userName($row->excluded_by) ?? '—',
            ],
            'evidence' => [
                'share' => [
                    'id' => (int) $row->id,
                    'doctor' => self::doctorName($row->health_doctor_id),
                    'accrual_date' => self::dateOnly($row->accrual_date),
                    'charge_category' => $row->charge_category,
                    'base_amount' => self::money($row->base_amount),
                    'share_amount' => self::money($row->share_amount),
                    'excluded_at' => $row->excluded_at,
                    'excluded_by' => self::userName($row->excluded_by),
                    'exclusion_reason' => $row->exclusion_reason ?: null,
                ],
                'link' => self::link('health.accounts.shares', [], 'accounts.view'),
            ],
        ], $rows->all());
    }

    /** Ledger entries backed out. */
    public static function journalReversed(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_journals')) {
            return [];
        }

        $query = DB::table('health_journals')
            ->where('company_id', $ctx->companyId)
            ->whereNotNull('reversed_at')
            ->whereBetween('reversed_at', [$ctx->fromStart(), $ctx->toEnd()]);

        $ctx->applyBranch($query);
        // A journal is filed under no ward; its LINES are. It stays in view
        // when any line belongs to the reader's departments.
        $ctx->applyDepartmentVia($query, 'health_journal_lines', 'health_journals.id', 'health_journal_id');
        $ctx->applySubject($query, ['posted_by', 'reversed_by']);

        $rows = $query->orderBy('reversed_at')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->reversed_at),
            'branch_id' => $row->branch_id,
            'subject_user_id' => $row->reversed_by,
            'subject_name' => self::userName($row->reversed_by),
            'entity_type' => 'health_journals',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->journal_no ?: $row->id),
            'amount' => $row->total_debit,
            'severity' => ($row->reversal_reason === null || $row->reversal_reason === '') ? 'critical' : 'warning',
            'params' => [
                'journal' => (string) ($row->journal_no ?: $row->id),
                'amount' => self::money($row->total_debit),
                'by' => self::userName($row->reversed_by) ?? '—',
            ],
            'evidence' => [
                'journal' => [
                    'id' => (int) $row->id,
                    'journal_no' => $row->journal_no,
                    'journal_date' => self::dateOnly($row->journal_date),
                    'type' => $row->type,
                    'source_type' => $row->source_type,
                    'memo' => $row->memo,
                    'total_debit' => self::money($row->total_debit),
                    'reversed_at' => $row->reversed_at,
                    'reversed_by' => self::userName($row->reversed_by),
                    'reversal_reason' => $row->reversal_reason ?: null,
                ],
                'link' => self::link('health.accounts.journal', ['id' => (int) $row->id], 'accounts.view'),
            ],
        ], $rows->all());
    }

    /** A posted journal whose two sides do not match. */
    public static function journalUnbalanced(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_journals')) {
            return [];
        }

        $query = DB::table('health_journals')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('journal_date', [$ctx->from, $ctx->to])
            ->where('status', 'posted')
            ->whereRaw('ABS(COALESCE(total_debit,0) - COALESCE(total_credit,0)) > ' . self::num(0.005));

        $ctx->applyBranch($query);
        $ctx->applyDepartmentVia($query, 'health_journal_lines', 'health_journals.id', 'health_journal_id');
        $ctx->applySubject($query, ['posted_by']);

        $rows = $query->orderBy('journal_date')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(function ($row) {
            $variance = round((float) $row->total_debit - (float) $row->total_credit, 2);

            return [
                'occurred_on' => self::dateOnly($row->journal_date),
                'branch_id' => $row->branch_id,
                'subject_user_id' => $row->posted_by,
                'subject_name' => self::userName($row->posted_by),
                'entity_type' => 'health_journals',
                'entity_id' => (int) $row->id,
                'entity_label' => (string) ($row->journal_no ?: $row->id),
                'variance' => $variance,
                'params' => [
                    'journal' => (string) ($row->journal_no ?: $row->id),
                    'debit' => self::money($row->total_debit),
                    'credit' => self::money($row->total_credit),
                    'variance' => self::money($variance),
                ],
                'evidence' => [
                    'journal' => [
                        'id' => (int) $row->id,
                        'journal_no' => $row->journal_no,
                        'journal_date' => self::dateOnly($row->journal_date),
                        'total_debit' => self::money($row->total_debit),
                        'total_credit' => self::money($row->total_credit),
                        'difference' => self::money($variance),
                        'posted_by' => self::userName($row->posted_by),
                    ],
                    'link' => self::link('health.accounts.journal', ['id' => (int) $row->id], 'accounts.view'),
                ],
            ];
        }, $rows->all());
    }

    /** Money out of the hospital, then taken back. */
    public static function expenseReversed(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_expenses')) {
            return [];
        }

        $query = DB::table('health_expenses')
            ->where('company_id', $ctx->companyId)
            ->whereNotNull('reversed_at')
            ->whereBetween('reversed_at', [$ctx->fromStart(), $ctx->toEnd()]);

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
            'entity_type' => 'health_expenses',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->expense_no ?: $row->id),
            'amount' => $row->total_amount,
            'params' => [
                'expense' => (string) ($row->expense_no ?: $row->id),
                'amount' => self::money($row->total_amount),
                'by' => self::userName($row->reversed_by) ?? '—',
            ],
            'evidence' => [
                'expense' => [
                    'id' => (int) $row->id,
                    'expense_no' => $row->expense_no,
                    'expense_date' => self::dateOnly($row->expense_date),
                    'payee' => $row->payee,
                    'total_amount' => self::money($row->total_amount),
                    'reversed_at' => $row->reversed_at,
                    'reversed_by' => self::userName($row->reversed_by),
                    'reversal_reason' => $row->reversal_reason ?: null,
                ],
                'link' => self::link('health.accounts.expenses', [], 'accounts.view'),
            ],
        ], $rows->all());
    }
}
