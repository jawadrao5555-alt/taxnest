<?php

namespace App\Services;

use App\Models\HealthBill;
use App\Models\HealthCashierShift;
use App\Models\HealthPayment;
use App\Models\HealthTaxCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Receipts, shifts and day-close reconciliation (Task 1551, step 5).
 *
 * Everything here is derived from the SAME persisted rows the bills and
 * receipts are printed from — health_bills, health_bill_lines and
 * health_payments. Nothing keeps its own running tally.
 *
 * That is the whole design point. A hospital's day-close, its payment-method
 * breakdown, its cashier's drawer and the patient's own statement have to agree
 * to the rupee, and the only reliable way to guarantee that is to compute all
 * of them from one set of rows every time rather than to maintain four counters
 * and hope they stay in step.
 */
class HealthBillingReportService
{
    /**
     * Open a shift for a cashier.
     *
     * A cashier has at most one open shift; asking again returns the one they
     * already have rather than opening a second, because two open drawers for
     * one person means every receipt afterwards is attributable to neither.
     */
    public static function openShift(int $companyId, $actor, float $openingFloat = 0, ?int $branchId = null): ?HealthCashierShift
    {
        if (!$actor || !Schema::hasTable('health_cashier_shifts')) {
            return null;
        }

        $open = HealthBillingService::openShiftFor($companyId, $actor);
        if ($open) {
            return $open;
        }

        return HealthCashierShift::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'user_id' => $actor->id,
            'opened_at' => now(),
            'opened_by' => $actor->id,
            'opening_float' => round(max(0, $openingFloat), 2),
            'status' => HealthCashierShift::STATUS_OPEN,
            'business_date' => now()->toDateString(),
        ]);
    }

    /**
     * Close a shift against a counted drawer.
     *
     * `counted` NULL means nobody counted. It is stored as NULL and the variance
     * stays NULL with it — recording an uncounted drawer as a perfect zero
     * variance is how a missing count becomes an invisible one.
     *
     * @return array{ok:bool,reason?:string,shift?:HealthCashierShift}
     */
    public static function closeShift(HealthCashierShift $shift, ?float $counted, $actor = null, string $note = ''): array
    {
        if (!$shift->isOpen()) {
            return ['ok' => false, 'reason' => 'already_closed'];
        }

        $totals = self::shiftTotals($shift);
        $expected = round((float) $shift->opening_float + $totals['cash_net'], 2);

        $countedValue = $counted === null ? null : round($counted, 2);
        $variance = $countedValue === null ? null : round($countedValue - $expected, 2);

        $shift->forceFill([
            'closed_at' => now(),
            'closed_by' => $actor->id ?? null,
            'counted_cash' => $countedValue,
            'expected_cash' => $expected,
            'variance' => $variance,
            'totals' => $totals,
            'status' => HealthCashierShift::STATUS_CLOSED,
            'note' => $note ? mb_substr($note, 0, 300) : null,
        ])->save();

        return ['ok' => true, 'shift' => $shift->fresh()];
    }

    /**
     * Live totals for one shift, straight from its receipts.
     *
     * Cash is reported three ways — in, out and net — because a drawer that took
     * 50,000 and refunded 8,000 is not the same story as one that took 42,000,
     * even though both hold the same notes at close.
     */
    public static function shiftTotals(HealthCashierShift $shift): array
    {
        $payments = Schema::hasTable('health_payments')
            ? HealthPayment::withoutGlobalScopes()
                ->where('company_id', $shift->company_id)
                ->where('health_cashier_shift_id', $shift->id)
                ->whereNull('reversed_at')
                ->get()
            : collect();

        return self::summarisePayments($payments);
    }

    /**
     * Break a set of receipts down by method and by kind.
     *
     * @param  iterable<HealthPayment>  $payments
     */
    public static function summarisePayments(iterable $payments): array
    {
        $byMethod = [];
        foreach (HealthPayment::METHODS as $m) {
            $byMethod[$m] = ['in' => 0.0, 'out' => 0.0, 'net' => 0.0, 'count' => 0];
        }

        $byKind = [];
        foreach (HealthPayment::KINDS as $k) {
            $byKind[$k] = ['amount' => 0.0, 'count' => 0];
        }

        $in = 0.0;
        $out = 0.0;
        $count = 0;

        foreach ($payments as $p) {
            $method = in_array($p->method, HealthPayment::METHODS, true) ? $p->method : 'other';
            $kind = in_array($p->kind, HealthPayment::KINDS, true) ? $p->kind : HealthPayment::KIND_PAYMENT;
            $amount = round((float) $p->amount, 2);

            if ($p->isInflow()) {
                $byMethod[$method]['in'] = round($byMethod[$method]['in'] + $amount, 2);
                $in = round($in + $amount, 2);
            } else {
                $byMethod[$method]['out'] = round($byMethod[$method]['out'] + $amount, 2);
                $out = round($out + $amount, 2);
            }

            $byMethod[$method]['net'] = round($byMethod[$method]['in'] - $byMethod[$method]['out'], 2);
            $byMethod[$method]['count']++;

            $byKind[$kind]['amount'] = round($byKind[$kind]['amount'] + $amount, 2);
            $byKind[$kind]['count']++;
            $count++;
        }

        $cashIn = 0.0;
        $cashOut = 0.0;
        foreach (HealthPayment::CASH_METHODS as $m) {
            $cashIn = round($cashIn + ($byMethod[$m]['in'] ?? 0), 2);
            $cashOut = round($cashOut + ($byMethod[$m]['out'] ?? 0), 2);
        }

        return [
            'by_method' => $byMethod,
            'by_kind' => $byKind,
            'in' => $in,
            'out' => $out,
            'net' => round($in - $out, 2),
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'cash_net' => round($cashIn - $cashOut, 2),
            'count' => $count,
        ];
    }

    /**
     * The day's reconciliation for a branch (or the whole organisation).
     *
     * Billed, collected and the regulatory split all come out of the same query
     * set, so the three can be shown side by side and genuinely add up.
     */
    public static function daySummary(int $companyId, ?string $date = null, ?int $branchId = null): array
    {
        $date = $date ?: now()->toDateString();

        $bills = Schema::hasTable('health_bills')
            ? HealthBill::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('doc_type', HealthBill::TYPE_INVOICE)
                ->whereIn('status', HealthBill::LIVE_STATUSES)
                ->whereDate('bill_date', $date)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->get()
            : collect();

        $payments = Schema::hasTable('health_payments')
            ? HealthPayment::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNull('reversed_at')
                ->whereDate('business_date', $date)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->get()
            : collect();

        $treatment = [
            HealthTaxCategory::TREATMENT_LOCAL => 0.0,
            HealthTaxCategory::TREATMENT_EXEMPT => 0.0,
            HealthTaxCategory::TREATMENT_FBR => 0.0,
        ];
        foreach ($bills as $bill) {
            foreach (($bill->treatment_totals ?: []) as $k => $v) {
                if (array_key_exists($k, $treatment)) {
                    $treatment[$k] = round($treatment[$k] + (float) $v, 2);
                }
            }
        }

        $fbrCounts = [
            'filed' => $bills->filter(fn ($b) => (bool) $b->fbr_invoice_number)->count(),
            'eligible' => $bills->filter(fn ($b) => $b->fbr_eligible)->count(),
            'pending' => $bills->filter(fn ($b) => $b->fbr_eligible && !$b->fbr_invoice_number
                && in_array($b->fbr_status, [HealthBill::FBR_PENDING, null], true))->count(),
            'failed' => $bills->filter(fn ($b) => in_array($b->fbr_status, [HealthBill::FBR_FAILED, HealthBill::FBR_CONFIG_ERROR], true)
                && !$b->fbr_invoice_number)->count(),
        ];

        $shifts = Schema::hasTable('health_cashier_shifts')
            ? HealthCashierShift::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereDate('business_date', $date)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->orderBy('id')
                ->get()
            : collect();

        return [
            'date' => $date,
            'bills' => $bills,
            'bill_count' => $bills->count(),
            'billed' => round($bills->sum(fn ($b) => (float) $b->total_amount), 2),
            'patient_payable' => round($bills->sum(fn ($b) => (float) $b->patient_payable), 2),
            'concession' => round($bills->sum(fn ($b) => (float) $b->concession_amount), 2),
            'tax' => round($bills->sum(fn ($b) => (float) $b->tax_amount), 2),
            'outstanding' => round($bills->sum(fn ($b) => (float) $b->outstanding_amount), 2),
            'third_party' => round($bills->sum(fn ($b) => (float) $b->insurance_amount + (float) $b->corporate_amount), 2),
            'treatment' => $treatment,
            'fbr' => $fbrCounts,
            'payments' => self::summarisePayments($payments),
            'shifts' => $shifts,
        ];
    }

    /**
     * Department-wise billing for a date range.
     *
     * Read off the FROZEN bill lines rather than the live ledger: a department's
     * month has to keep saying the same number after a charge is later credited,
     * or every reconciliation done before that credit becomes a lie.
     */
    public static function departmentBreakdown(int $companyId, string $from, string $to, ?int $branchId = null): array
    {
        if (!Schema::hasTable('health_bill_lines') || !Schema::hasTable('health_bills')) {
            return [];
        }

        $rows = DB::table('health_bill_lines as l')
            ->join('health_bills as b', 'b.id', '=', 'l.health_bill_id')
            ->leftJoin('health_departments as d', 'd.id', '=', 'l.health_department_id')
            ->where('l.company_id', $companyId)
            ->where('b.doc_type', HealthBill::TYPE_INVOICE)
            ->whereIn('b.status', HealthBill::LIVE_STATUSES)
            ->whereDate('b.bill_date', '>=', $from)
            ->whereDate('b.bill_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('b.branch_id', $branchId))
            ->groupBy('l.health_department_id', 'd.name')
            ->selectRaw('l.health_department_id as department_id, d.name as department_name,
                COUNT(*) as line_count,
                SUM(l.gross_amount) as gross,
                SUM(l.concession_amount) as concession,
                SUM(l.net_amount) as net,
                SUM(l.tax_amount) as tax,
                SUM(l.total_amount) as total')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($r) => [
            'department_id' => $r->department_id,
            'department_name' => $r->department_name,
            'line_count' => (int) $r->line_count,
            'gross' => round((float) $r->gross, 2),
            'concession' => round((float) $r->concession, 2),
            'net' => round((float) $r->net, 2),
            'tax' => round((float) $r->tax, 2),
            'total' => round((float) $r->total, 2),
        ])->all();
    }

    /**
     * Category-wise billing for a date range — the same frozen-line rule.
     */
    public static function categoryBreakdown(int $companyId, string $from, string $to, ?int $branchId = null): array
    {
        if (!Schema::hasTable('health_bill_lines') || !Schema::hasTable('health_bills')) {
            return [];
        }

        $rows = DB::table('health_bill_lines as l')
            ->join('health_bills as b', 'b.id', '=', 'l.health_bill_id')
            ->where('l.company_id', $companyId)
            ->where('b.doc_type', HealthBill::TYPE_INVOICE)
            ->whereIn('b.status', HealthBill::LIVE_STATUSES)
            ->whereDate('b.bill_date', '>=', $from)
            ->whereDate('b.bill_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('b.branch_id', $branchId))
            ->groupBy('l.category', 'l.tax_treatment')
            ->selectRaw('l.category as category, l.tax_treatment as treatment,
                SUM(l.net_amount) as net, SUM(l.tax_amount) as tax, SUM(l.total_amount) as total')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($r) => [
            'category' => $r->category,
            'treatment' => $r->treatment,
            'net' => round((float) $r->net, 2),
            'tax' => round((float) $r->tax, 2),
            'total' => round((float) $r->total, 2),
        ])->all();
    }
}
