<?php

namespace App\Services\HealthAudit\Checks;

use App\Services\HealthAudit\HealthAuditContext;
use App\Services\HealthAudit\HealthAuditRules;
use Illuminate\Support\Facades\DB;

/**
 * Cash-counter checks (Task 1554).
 *
 * The counter is where a hospital's money is most exposed and least witnessed:
 * one person, one drawer, one receipt book. These rules do not look for theft —
 * they look for the four situations in which theft would be indistinguishable
 * from an ordinary mistake, which is a different and far more useful thing.
 */
class PaymentChecks extends BaseChecks
{
    /** Receipts undone, with a reason on the record. */
    public static function paymentReversed(HealthAuditContext $ctx): array
    {
        return self::reversedPayments($ctx, false);
    }

    /** Receipts undone with nothing written down. */
    public static function paymentReversedNoReason(HealthAuditContext $ctx): array
    {
        return self::reversedPayments($ctx, true);
    }

    protected static function reversedPayments(HealthAuditContext $ctx, bool $onlyWithoutReason): array
    {
        if (self::tableMissing('health_payments')) {
            return [];
        }

        $query = DB::table('health_payments')
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
        $ctx->applyDepartmentVia($query, 'health_bills', 'health_payments.health_bill_id');
        $ctx->applySubject($query, ['received_by', 'reversed_by']);

        $rows = $query->orderBy('reversed_at')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->reversed_at),
            'branch_id' => $row->branch_id,
            'subject_user_id' => $row->reversed_by,
            'subject_name' => self::userName($row->reversed_by),
            'entity_type' => 'health_payments',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->receipt_no ?: $row->id),
            'amount' => $row->amount,
            'params' => [
                'receipt' => (string) ($row->receipt_no ?: $row->id),
                'amount' => self::money($row->amount),
                'by' => self::userName($row->reversed_by) ?? '—',
                'date' => self::dateOnly($row->reversed_at),
            ],
            'evidence' => [
                'payment' => [
                    'id' => (int) $row->id,
                    'receipt_no' => $row->receipt_no,
                    'kind' => $row->kind,
                    'method' => $row->method,
                    'amount' => self::money($row->amount),
                    'received_at' => $row->received_at,
                    'received_by' => self::userName($row->received_by),
                    'reversed_at' => $row->reversed_at,
                    'reversed_by' => self::userName($row->reversed_by),
                    'reversal_reason' => $row->reversal_reason ?: null,
                    'health_bill_id' => $row->health_bill_id,
                    'health_cashier_shift_id' => $row->health_cashier_shift_id,
                ],
                'link' => $row->health_bill_id
                    ? self::link('health.billing.bill', ['id' => (int) $row->health_bill_id], 'billing.view')
                    : null,
            ],
        ], $rows->all());
    }

    /**
     * The drawer did not match what the system expected.
     *
     * The variance is already computed at close time; this rule only decides
     * which of them the owner should be shown. Under a rupee is rounding.
     * Above CASH_VARIANCE_CRITICAL it stops being a bad day.
     */
    public static function shiftCashVariance(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_cashier_shifts')) {
            return [];
        }

        $min = HealthAuditRules::CASH_VARIANCE_MIN;
        $critical = HealthAuditRules::CASH_VARIANCE_CRITICAL;

        $query = DB::table('health_cashier_shifts')
            ->where('company_id', $ctx->companyId)
            ->whereNotNull('closed_at')
            ->whereBetween('business_date', [$ctx->from, $ctx->to])
            ->whereRaw('ABS(COALESCE(variance,0)) >= ' . self::num($min));

        $ctx->applyBranch($query);
        $ctx->applyDepartmentVia($query, 'users', 'health_cashier_shifts.user_id');
        $ctx->applySubject($query, ['user_id', 'opened_by', 'closed_by']);

        $rows = $query->orderByDesc(DB::raw('ABS(COALESCE(variance,0))'))->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(function ($row) use ($min, $critical) {
            $variance = round((float) $row->variance, 2);

            return [
                'occurred_on' => self::dateOnly($row->business_date),
                'branch_id' => $row->branch_id,
                'subject_user_id' => $row->user_id,
                'subject_name' => self::userName($row->user_id),
                'entity_type' => 'health_cashier_shifts',
                'entity_id' => (int) $row->id,
                'entity_label' => '#' . $row->id,
                'amount' => $row->counted_cash,
                'variance' => $variance,
                'severity' => abs($variance) >= $critical ? 'critical' : 'warning',
                'params' => [
                    'cashier' => self::userName($row->user_id) ?? '—',
                    'variance' => self::money(abs($variance)),
                    'direction' => $variance > 0 ? 'over' : 'short',
                    'date' => self::dateOnly($row->business_date),
                ],
                'evidence' => [
                    'shift' => [
                        'id' => (int) $row->id,
                        'business_date' => self::dateOnly($row->business_date),
                        'cashier' => self::userName($row->user_id),
                        'opened_at' => $row->opened_at,
                        'closed_at' => $row->closed_at,
                        'opening_float' => self::money($row->opening_float),
                        'expected_cash' => self::money($row->expected_cash),
                        'counted_cash' => self::money($row->counted_cash),
                        'variance' => self::money($variance),
                        'note' => $row->note,
                        'closed_by' => self::userName($row->closed_by),
                    ],
                    'threshold' => ['warn_at' => $min, 'critical_at' => $critical],
                    'link' => self::link('health.billing.shifts', [], 'billing.view'),
                ],
            ];
        }, $rows->all());
    }

    /**
     * A counter session that was never closed.
     *
     * An open shift has no counted cash, so its drawer was never checked
     * against anything at all — which makes it the one shift a variance could
     * never be found in.
     */
    public static function shiftLeftOpen(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_cashier_shifts')) {
            return [];
        }

        $query = DB::table('health_cashier_shifts')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('business_date', [$ctx->from, $ctx->to])
            ->whereNull('closed_at');

        $ctx->applyBranch($query);
        $ctx->applyDepartmentVia($query, 'users', 'health_cashier_shifts.user_id');
        $ctx->applySubject($query, ['user_id', 'opened_by']);

        $rows = $query->orderBy('business_date')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->business_date),
            'branch_id' => $row->branch_id,
            'subject_user_id' => $row->user_id,
            'subject_name' => self::userName($row->user_id),
            'entity_type' => 'health_cashier_shifts',
            'entity_id' => (int) $row->id,
            'entity_label' => '#' . $row->id,
            'params' => [
                'cashier' => self::userName($row->user_id) ?? '—',
                'date' => self::dateOnly($row->business_date),
            ],
            'evidence' => [
                'shift' => [
                    'id' => (int) $row->id,
                    'business_date' => self::dateOnly($row->business_date),
                    'cashier' => self::userName($row->user_id),
                    'opened_at' => $row->opened_at,
                    'opening_float' => self::money($row->opening_float),
                    'closed_at' => null,
                    'status' => $row->status,
                ],
                'link' => self::link('health.billing.shifts', [], 'billing.view'),
            ],
        ], $rows->all());
    }

    /**
     * Cash taken with no counter session behind it.
     *
     * Informational: an advance received at the ward desk legitimately has no
     * shift. But cash that belongs to no drawer also belongs to no count, so
     * the owner gets the number rather than a silence.
     */
    public static function cashOutsideShift(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_payments')) {
            return [];
        }

        $query = DB::table('health_payments')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('received_at', [$ctx->fromStart(), $ctx->toEnd()])
            ->where('method', 'cash')
            ->whereNull('reversed_at')
            ->whereNull('health_cashier_shift_id')
            ->where('amount', '>', 0);

        $ctx->applyBranch($query);
        $ctx->applyDepartmentVia($query, 'health_bills', 'health_payments.health_bill_id');
        $ctx->applySubject($query, ['received_by']);

        $rows = $query->orderBy('received_at')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->received_at),
            'branch_id' => $row->branch_id,
            'subject_user_id' => $row->received_by,
            'subject_name' => self::userName($row->received_by),
            'entity_type' => 'health_payments',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->receipt_no ?: $row->id),
            'amount' => $row->amount,
            'params' => [
                'receipt' => (string) ($row->receipt_no ?: $row->id),
                'amount' => self::money($row->amount),
                'by' => self::userName($row->received_by) ?? '—',
                'date' => self::dateOnly($row->received_at),
            ],
            'evidence' => [
                'payment' => [
                    'id' => (int) $row->id,
                    'receipt_no' => $row->receipt_no,
                    'amount' => self::money($row->amount),
                    'method' => $row->method,
                    'kind' => $row->kind,
                    'received_at' => $row->received_at,
                    'received_by' => self::userName($row->received_by),
                    'health_cashier_shift_id' => null,
                ],
                'link' => $row->health_bill_id
                    ? self::link('health.billing.bill', ['id' => (int) $row->health_bill_id], 'billing.view')
                    : null,
            ],
        ], $rows->all());
    }
}
