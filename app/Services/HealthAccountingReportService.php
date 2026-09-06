<?php

namespace App\Services;

use App\Models\HealthAccount;
use App\Models\HealthBill;
use App\Models\HealthJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The books, read back (Task 1552).
 *
 * Every report here is derived from `health_journal_lines` and nothing else.
 * There is no cached total, no "current balance" column, no summary table kept
 * in step by a write path that might forget. The consequence is that a report
 * can be slow but can never be WRONG, and for a hospital's books that is the
 * only acceptable direction for that trade.
 *
 * Two exceptions, both deliberate and both labelled where they are used:
 * receivables and payables ageing read the source documents rather than the
 * ledger, because "who owes what since when" is a property of the invoice, not
 * of the summarised control account it rolls into.
 */
class HealthAccountingReportService
{
    /** Ageing buckets, in days. */
    public const AGING_BUCKETS = [30, 60, 90];

    // ═══════════════════════════════════════════════════════════════════
    // TRIAL BALANCE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Every account with movement, and the proof that the books balance.
     *
     * `balanced` is the headline. An accountant should be able to answer "are
     * the books sound?" from the top of one screen, not by adding two columns
     * themselves.
     */
    public static function trialBalance(int $companyId, ?string $from, ?string $to, array $filters = []): array
    {
        $accounts = self::accounts($companyId);
        $opening = $from ? HealthLedgerService::balances($companyId, null, self::dayBefore($from), $filters) : [];
        $period = HealthLedgerService::balances($companyId, $from, $to, $filters);

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($accounts as $account) {
            $open = $opening[$account->id] ?? ['debit' => 0, 'credit' => 0];
            $move = $period[$account->id] ?? ['debit' => 0, 'credit' => 0];

            $openSigned = $account->signedBalance($open['debit'], $open['credit']);
            $closeSigned = $account->signedBalance(
                $open['debit'] + $move['debit'],
                $open['credit'] + $move['credit']
            );

            if (abs($openSigned) <= 0.005 && abs($move['debit']) <= 0.005 && abs($move['credit']) <= 0.005) {
                continue; // an account nobody used is noise, not information
            }

            $totalDebit = round($totalDebit + $move['debit'], 2);
            $totalCredit = round($totalCredit + $move['credit'], 2);

            $rows[] = [
                'id' => $account->id,
                // Same value under the name the period-close snapshot reads it
                // by, so a frozen snapshot stays readable on its own terms.
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->displayName(),
                'type' => $account->type,
                'group' => $account->subtype,
                'opening' => round($openSigned, 2),
                'debit' => round($move['debit'], 2),
                'credit' => round($move['credit'], 2),
                'closing' => round($closeSigned, 2),
            ];
        }

        return [
            'rows' => $rows,
            'totals' => ['debit' => $totalDebit, 'credit' => $totalCredit],
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'difference' => round($totalDebit - $totalCredit, 2),
            'balanced' => abs($totalDebit - $totalCredit) <= 0.005,
            'from' => $from,
            'to' => $to,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // GENERAL LEDGER
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Every line that touched one account, with a running balance.
     *
     * The running balance starts from the true opening rather than zero, so a
     * mid-year page shows the account as it actually stood — a ledger that
     * restarts at zero on every filter is how a real overdraft gets missed.
     */
    public static function generalLedger(
        int $companyId,
        int $accountId,
        ?string $from,
        ?string $to,
        array $filters = [],
        int $limit = 500
    ): array {
        $account = HealthAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find($accountId);

        if (!$account || !Schema::hasTable('health_journal_lines')) {
            return ['account' => null, 'rows' => [], 'opening' => 0.0, 'closing' => 0.0];
        }

        $openTotals = $from
            ? (HealthLedgerService::balances($companyId, null, self::dayBefore($from), $filters)[$accountId] ?? ['debit' => 0, 'credit' => 0])
            : ['debit' => 0, 'credit' => 0];

        $opening = $account->signedBalance($openTotals['debit'], $openTotals['credit']);

        $lines = DB::table('health_journal_lines as l')
            ->join('health_journals as j', 'j.id', '=', 'l.health_journal_id')
            ->where('l.company_id', $companyId)
            ->where('l.health_account_id', $accountId)
            ->whereIn('j.status', HealthJournal::COUNTED_STATUSES)
            ->when($from, fn ($q) => $q->whereDate('j.journal_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('j.journal_date', '<=', $to))
            ->when($filters['doctor_id'] ?? null, fn ($q, $v) => $q->where('l.health_doctor_id', $v))
            ->tap(fn ($q) => HealthLedgerService::applyBranchFilter($q, $filters, 'l.branch_id'))
            ->tap(fn ($q) => HealthLedgerService::applyDepartmentFilter($q, $filters, 'l.health_department_id'))
            ->orderBy('j.journal_date')
            ->orderBy('j.id')
            ->orderBy('l.line_no')
            ->limit($limit)
            ->select(
                'l.id', 'l.debit', 'l.credit', 'l.memo as line_memo',
                'j.id as journal_id', 'j.journal_no', 'j.journal_date', 'j.memo',
                'j.source_type', 'j.source_id', 'j.source_reference', 'j.type'
            )
            ->get();

        $running = $opening;
        $rows = [];
        $debit = 0.0;
        $credit = 0.0;

        foreach ($lines as $line) {
            $running = round($running + $account->signedBalance((float) $line->debit, (float) $line->credit), 2);
            $debit = round($debit + (float) $line->debit, 2);
            $credit = round($credit + (float) $line->credit, 2);
            $rows[] = [
                'id' => (int) $line->id,
                'journal_id' => (int) $line->journal_id,
                'journal_no' => $line->journal_no,
                'date' => $line->journal_date,
                'memo' => $line->line_memo ?: $line->memo,
                'type' => $line->type,
                'source_type' => $line->source_type,
                'source_id' => $line->source_id ? (int) $line->source_id : null,
                'source_reference' => $line->source_reference,
                'debit' => round((float) $line->debit, 2),
                'credit' => round((float) $line->credit, 2),
                'balance' => $running,
            ];
        }

        return [
            'account' => $account,
            'rows' => $rows,
            'opening' => round($opening, 2),
            'debit' => $debit,
            'credit' => $credit,
            'closing' => $running,
            'truncated' => count($rows) >= $limit,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // PROFIT AND LOSS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * What the organisation earned and what it cost.
     *
     * Concessions appear as a NEGATIVE income line rather than a cost. A
     * hospital that discounted 200,000 did not spend 200,000 — it earned that
     * much less, and burying it in expenses makes both revenue and cost control
     * look better than they are.
     */
    public static function profitAndLoss(int $companyId, string $from, string $to, array $filters = []): array
    {
        $accounts = self::accounts($companyId);
        $movement = HealthLedgerService::balances($companyId, $from, $to, $filters);

        $income = [];
        $costOfSales = [];
        $expenses = [];
        $incomeTotal = 0.0;
        $cosTotal = 0.0;
        $expenseTotal = 0.0;

        foreach ($accounts as $account) {
            $move = $movement[$account->id] ?? null;
            if (!$move) {
                continue;
            }

            $amount = round($account->signedBalance($move['debit'], $move['credit']), 2);
            if (abs($amount) <= 0.005) {
                continue;
            }

            $row = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->displayName(),
                'group' => $account->subtype,
                'amount' => $amount,
            ];

            if ($account->type === HealthAccount::TYPE_INCOME) {
                // A contra-income account carries a debit balance, which
                // signedBalance already returns as negative. It subtracts by
                // arithmetic, not by a special case somebody can forget.
                $income[] = $row;
                $incomeTotal = round($incomeTotal + $amount, 2);
            } elseif ($account->type === HealthAccount::TYPE_EXPENSE) {
                if ($account->subtype === 'cost_of_sales' || $account->subtype === 'direct_cost') {
                    $costOfSales[] = $row;
                    $cosTotal = round($cosTotal + $amount, 2);
                } else {
                    $expenses[] = $row;
                    $expenseTotal = round($expenseTotal + $amount, 2);
                }
            }
        }

        $gross = round($incomeTotal - $cosTotal, 2);

        return [
            'income' => $income,
            'cost_of_sales' => $costOfSales,
            'expenses' => $expenses,
            'income_total' => $incomeTotal,
            'cost_of_sales_total' => $cosTotal,
            'gross_profit' => $gross,
            'expense_total' => $expenseTotal,
            'net_profit' => round($gross - $expenseTotal, 2),
            'margin' => $incomeTotal > 0 ? round((($gross - $expenseTotal) / $incomeTotal) * 100, 1) : 0.0,
            'from' => $from,
            'to' => $to,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // BALANCE SHEET
    // ═══════════════════════════════════════════════════════════════════

    /**
     * What the organisation owns, owes and is worth, as at a date.
     *
     * Profit for the period is folded into equity as an unposted line rather
     * than requiring a year-end closing entry first. Without it the sheet would
     * refuse to balance for eleven months of every year, and an accountant would
     * learn to ignore the one number that is supposed to prove the books.
     */
    public static function balanceSheet(int $companyId, string $asAt, array $filters = []): array
    {
        $accounts = self::accounts($companyId);
        $totals = HealthLedgerService::balances($companyId, null, $asAt, $filters);

        $assets = [];
        $liabilities = [];
        $equity = [];
        $assetTotal = 0.0;
        $liabilityTotal = 0.0;
        $equityTotal = 0.0;
        $earnings = 0.0;

        foreach ($accounts as $account) {
            $move = $totals[$account->id] ?? null;
            if (!$move) {
                continue;
            }
            $amount = round($account->signedBalance($move['debit'], $move['credit']), 2);
            if (abs($amount) <= 0.005) {
                continue;
            }

            $row = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->displayName(),
                'group' => $account->subtype,
                'amount' => $amount,
            ];

            switch ($account->type) {
                case HealthAccount::TYPE_ASSET:
                    $assets[] = $row;
                    $assetTotal = round($assetTotal + $amount, 2);
                    break;
                case HealthAccount::TYPE_LIABILITY:
                    $liabilities[] = $row;
                    $liabilityTotal = round($liabilityTotal + $amount, 2);
                    break;
                case HealthAccount::TYPE_EQUITY:
                    $equity[] = $row;
                    $equityTotal = round($equityTotal + $amount, 2);
                    break;
                case HealthAccount::TYPE_INCOME:
                    $earnings = round($earnings + $amount, 2);
                    break;
                case HealthAccount::TYPE_EXPENSE:
                    $earnings = round($earnings - $amount, 2);
                    break;
            }
        }

        $equityTotal = round($equityTotal + $earnings, 2);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'current_earnings' => $earnings,
            'asset_total' => $assetTotal,
            'liability_total' => $liabilityTotal,
            'equity_total' => $equityTotal,
            'difference' => round($assetTotal - ($liabilityTotal + $equityTotal), 2),
            'balanced' => abs($assetTotal - ($liabilityTotal + $equityTotal)) <= 0.005,
            'as_at' => $asAt,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // CASH FLOW
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Where the money actually went.
     *
     * Built the DIRECT way: every journal that touched a cash-like account, and
     * what sat on the other side of it. An indirect statement derived from
     * profit would be technically respectable and would not answer the question
     * an owner is really asking, which is "who did I pay".
     */
    public static function cashFlow(int $companyId, string $from, string $to, array $filters = []): array
    {
        if (!Schema::hasTable('health_journal_lines')) {
            return ['opening' => 0.0, 'closing' => 0.0, 'rows' => [], 'in' => 0.0, 'out' => 0.0];
        }

        $cashAccounts = HealthAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('is_cash', true)->orWhere('is_bank', true);
            })
            ->pluck('id')
            ->all();

        if (!$cashAccounts) {
            return ['opening' => 0.0, 'closing' => 0.0, 'rows' => [], 'in' => 0.0, 'out' => 0.0];
        }

        $sumFor = function (?string $until) use ($companyId, $cashAccounts, $filters) {
            $balances = HealthLedgerService::balances($companyId, null, $until, $filters);
            $total = 0.0;
            foreach ($cashAccounts as $id) {
                $b = $balances[$id] ?? null;
                if ($b) {
                    $total = round($total + $b['debit'] - $b['credit'], 2);
                }
            }

            return $total;
        };

        $opening = $from ? $sumFor(self::dayBefore($from)) : 0.0;
        $closing = $sumFor($to);

        // The other side of every cash movement, grouped.
        $journalIds = DB::table('health_journal_lines as l')
            ->join('health_journals as j', 'j.id', '=', 'l.health_journal_id')
            ->where('l.company_id', $companyId)
            ->whereIn('j.status', HealthJournal::COUNTED_STATUSES)
            ->whereIn('l.health_account_id', $cashAccounts)
            ->whereDate('j.journal_date', '>=', $from)
            ->whereDate('j.journal_date', '<=', $to)
            // The opening and closing figures above already respect the reader's
            // branch boundary. The movement rows must respect the SAME one, or a
            // statement whose totals are one branch itemises the whole company.
            ->tap(fn ($q) => HealthLedgerService::applyBranchFilter($q, $filters, 'l.branch_id'))
            ->tap(fn ($q) => HealthLedgerService::applyDepartmentFilter($q, $filters, 'l.health_department_id'))
            ->distinct()
            ->pluck('j.id');

        $rows = [];
        $in = 0.0;
        $out = 0.0;

        if ($journalIds->isNotEmpty()) {
            $counter = DB::table('health_journal_lines as l')
                ->join('health_accounts as a', 'a.id', '=', 'l.health_account_id')
                ->whereIn('l.health_journal_id', $journalIds)
                ->whereNotIn('l.health_account_id', $cashAccounts)
                ->tap(fn ($q) => HealthLedgerService::applyBranchFilter($q, $filters, 'l.branch_id'))
                ->tap(fn ($q) => HealthLedgerService::applyDepartmentFilter($q, $filters, 'l.health_department_id'))
                ->groupBy('a.id', 'a.code', 'a.name', 'a.system_key', 'a.type', 'a.cash_flow')
                ->select(
                    'a.id', 'a.code', 'a.name', 'a.system_key', 'a.type', 'a.cash_flow',
                    DB::raw('SUM(l.debit) as d'),
                    DB::raw('SUM(l.credit) as c')
                )
                ->get();

            foreach ($counter as $row) {
                // A credit on the far side means cash came IN.
                $net = round((float) $row->c - (float) $row->d, 2);
                if (abs($net) <= 0.005) {
                    continue;
                }
                if ($net > 0) {
                    $in = round($in + $net, 2);
                } else {
                    $out = round($out - $net, 2);
                }
                $rows[] = [
                    'id' => (int) $row->id,
                    'code' => $row->code,
                    'name' => $row->system_key ? __('health.acc_' . $row->system_key) : $row->name,
                    'section' => $row->cash_flow ?: 'operating',
                    'amount' => $net,
                ];
            }
        }

        usort($rows, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'opening' => $opening,
            'closing' => $closing,
            'in' => $in,
            'out' => $out,
            'net' => round($in - $out, 2),
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // AGEING
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Who owes money, and for how long.
     *
     * Read from the BILLS, not from the receivable control account. The ledger
     * knows the hospital is owed 4.2 million; only the invoices know that 900,000
     * of it is from one corporate panel and 140 days old — which is the part
     * somebody has to act on.
     */
    public static function receivablesAging(int $companyId, ?string $asAt = null, array $filters = []): array
    {
        $asAt = $asAt ?: now()->toDateString();

        if (!Schema::hasTable('health_bills')) {
            return ['rows' => [], 'buckets' => [], 'total' => 0.0];
        }

        $bills = HealthBill::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('doc_type', HealthBill::TYPE_INVOICE)
            ->whereIn('status', HealthBill::LIVE_STATUSES)
            ->whereDate('bill_date', '<=', $asAt)
            ->where('outstanding_amount', '>', 0.005)
            ->tap(fn ($q) => HealthLedgerService::applyBranchFilter($q, $filters, 'health_bills.branch_id'))
            // Names and MRNs travel on these rows, so the department fence
            // matters more here than anywhere else on the finance side.
            ->tap(fn ($q) => HealthLedgerService::applyDepartmentFilter($q, $filters, 'health_bills.health_department_id'))
            ->with('patient:id,mrn,name')
            ->orderBy('bill_date')
            ->limit(1000)
            ->get();

        $buckets = self::emptyBuckets();
        $rows = [];
        $total = 0.0;

        foreach ($bills as $bill) {
            $days = (int) \Carbon\Carbon::parse($asAt)->diffInDays(\Carbon\Carbon::parse($bill->bill_date), true);
            $bucket = self::bucketFor($days);
            $amount = round((float) $bill->outstanding_amount, 2);

            $buckets[$bucket] = round($buckets[$bucket] + $amount, 2);
            $total = round($total + $amount, 2);

            $rows[] = [
                'bill_id' => $bill->id,
                'bill_no' => $bill->bill_no,
                'date' => $bill->bill_date ? $bill->bill_date->toDateString() : null,
                'patient' => $bill->patient?->name,
                'mrn' => $bill->patient?->mrn,
                'days' => $days,
                'bucket' => $bucket,
                'amount' => $amount,
            ];
        }

        return ['rows' => $rows, 'buckets' => $buckets, 'total' => $total, 'as_at' => $asAt];
    }

    /**
     * What the organisation owes its suppliers, per supplier.
     *
     * Derived by pairing received purchase orders against supplier payments,
     * because that is where the invoice dates live. The supplier control account
     * is the check total, shown alongside.
     */
    public static function payablesAging(int $companyId, ?string $asAt = null, array $filters = []): array
    {
        $asAt = $asAt ?: now()->toDateString();

        if (!Schema::hasTable('purchase_orders')) {
            return ['rows' => [], 'buckets' => [], 'total' => 0.0];
        }

        $purchases = DB::table('purchase_orders')
            ->where('company_id', $companyId)
            ->whereIn('status', ['received', 'partial'])
            ->whereDate(DB::raw('COALESCE(received_date, order_date)'), '<=', $asAt)
            ->tap(fn ($q) => HealthLedgerService::applyBranchFilter($q, $filters, 'branch_id'))
            ->select('supplier_id', 'po_number', 'total_amount', 'id')
            ->selectRaw('COALESCE(received_date, order_date) as on_date')
            ->orderBy('id')
            ->limit(2000)
            ->get();

        $paidBySupplier = Schema::hasTable('health_supplier_payments')
            ? DB::table('health_supplier_payments')
                ->where('company_id', $companyId)
                ->whereDate('paid_on', '<=', $asAt)
                // Scoped the same way as the purchases above. Filtering one side
                // and not the other credits a branch with payments it never made
                // and reports the supplier as settled.
                ->tap(fn ($q) => HealthLedgerService::applyBranchFilter($q, $filters, 'branch_id'))
                ->groupBy('supplier_id')
                ->select('supplier_id', DB::raw('SUM(amount) as paid'))
                ->pluck('paid', 'supplier_id')
            : collect();

        $names = Schema::hasTable('suppliers')
            ? DB::table('suppliers')->where('company_id', $companyId)->pluck('name', 'id')
            : collect();

        // Oldest purchase first: a payment settles the oldest invoice, which is
        // what a supplier's own statement assumes.
        $bySupplier = [];
        foreach ($purchases as $row) {
            $bySupplier[(int) $row->supplier_id][] = $row;
        }

        $buckets = self::emptyBuckets();
        $rows = [];
        $total = 0.0;

        foreach ($bySupplier as $supplierId => $list) {
            $credit = round((float) ($paidBySupplier[$supplierId] ?? 0), 2);
            $outstanding = 0.0;
            $supplierBuckets = self::emptyBuckets();

            foreach ($list as $row) {
                $amount = round((float) $row->total_amount, 2);
                if ($credit >= $amount) {
                    $credit = round($credit - $amount, 2);
                    continue;
                }
                $open = round($amount - $credit, 2);
                $credit = 0.0;

                $days = (int) \Carbon\Carbon::parse($asAt)->diffInDays(\Carbon\Carbon::parse($row->on_date), true);
                $bucket = self::bucketFor($days);
                $supplierBuckets[$bucket] = round($supplierBuckets[$bucket] + $open, 2);
                $buckets[$bucket] = round($buckets[$bucket] + $open, 2);
                $outstanding = round($outstanding + $open, 2);
            }

            if ($outstanding <= 0.005 && $credit <= 0.005) {
                continue;
            }

            $total = round($total + $outstanding, 2);
            $rows[] = [
                'supplier_id' => $supplierId,
                'supplier' => $names[$supplierId] ?? ('#' . $supplierId),
                'outstanding' => $outstanding,
                'advance' => $credit,
                'buckets' => $supplierBuckets,
            ];
        }

        usort($rows, fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']);

        $control = HealthChartOfAccountsService::id($companyId, HealthChartOfAccountsService::SUPPLIER_PAYABLE);

        return [
            'rows' => $rows,
            'buckets' => $buckets,
            'total' => $total,
            'as_at' => $asAt,
            'control_balance' => $control
                ? round(HealthLedgerService::accountBalance($companyId, $control, $asAt, $filters), 2)
                : null,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // PROFITABILITY BY DIMENSION
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Income and direct cost per department, branch or doctor.
     *
     * Only lines that CARRY the dimension are counted, and what is left over is
     * reported as "unallocated" rather than spread across the others. A hospital
     * told that radiology made 3.1 million must be able to trust the number; an
     * apportioned guess dressed up as a fact is worse than an honest gap.
     */
    public static function dimensionProfit(int $companyId, string $dimension, string $from, string $to, array $filters = []): array
    {
        $column = match ($dimension) {
            'branch' => 'l.branch_id',
            'doctor' => 'l.health_doctor_id',
            default => 'l.health_department_id',
        };

        if (!Schema::hasTable('health_journal_lines')) {
            return ['rows' => [], 'unallocated' => ['income' => 0.0, 'cost' => 0.0]];
        }

        $rows = DB::table('health_journal_lines as l')
            ->join('health_journals as j', 'j.id', '=', 'l.health_journal_id')
            ->join('health_accounts as a', 'a.id', '=', 'l.health_account_id')
            ->where('l.company_id', $companyId)
            ->whereIn('j.status', HealthJournal::COUNTED_STATUSES)
            ->whereDate('j.journal_date', '>=', $from)
            ->whereDate('j.journal_date', '<=', $to)
            ->whereIn('a.type', [HealthAccount::TYPE_INCOME, HealthAccount::TYPE_EXPENSE])
            ->when($filters['doctor_id'] ?? null, fn ($q, $v) => $q->where('l.health_doctor_id', $v))
            ->tap(fn ($q) => HealthLedgerService::applyBranchFilter($q, $filters, 'l.branch_id'))
            ->tap(fn ($q) => HealthLedgerService::applyDepartmentFilter($q, $filters, 'l.health_department_id'))
            ->groupBy(DB::raw($column), 'a.type')
            ->select(DB::raw($column . ' as dim'), 'a.type')
            ->selectRaw('SUM(l.debit) as d')
            ->selectRaw('SUM(l.credit) as c')
            ->get();

        $byDim = [];
        $unallocated = ['income' => 0.0, 'cost' => 0.0];

        foreach ($rows as $row) {
            $income = $row->type === HealthAccount::TYPE_INCOME;
            $amount = $income
                ? round((float) $row->c - (float) $row->d, 2)
                : round((float) $row->d - (float) $row->c, 2);

            if ($row->dim === null) {
                $unallocated[$income ? 'income' : 'cost'] = round(
                    $unallocated[$income ? 'income' : 'cost'] + $amount,
                    2
                );
                continue;
            }

            $key = (int) $row->dim;
            $byDim[$key] ??= ['id' => $key, 'income' => 0.0, 'cost' => 0.0];
            $byDim[$key][$income ? 'income' : 'cost'] = round($byDim[$key][$income ? 'income' : 'cost'] + $amount, 2);
        }

        $names = self::dimensionNames($companyId, $dimension, array_keys($byDim));

        $out = [];
        foreach ($byDim as $key => $row) {
            $row['name'] = $names[$key] ?? ('#' . $key);
            $row['profit'] = round($row['income'] - $row['cost'], 2);
            $row['margin'] = $row['income'] > 0 ? round(($row['profit'] / $row['income']) * 100, 1) : 0.0;
            $out[] = $row;
        }

        usort($out, fn ($a, $b) => $b['profit'] <=> $a['profit']);

        return ['rows' => $out, 'unallocated' => $unallocated, 'dimension' => $dimension];
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════

    /** Every account, ordered the way a chart of accounts is read. */
    public static function accounts(int $companyId)
    {
        if (!Schema::hasTable('health_accounts')) {
            return collect();
        }

        return HealthAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('code')
            ->get();
    }

    protected static function dimensionNames(int $companyId, string $dimension, array $ids): array
    {
        if (!$ids) {
            return [];
        }

        [$table, $column] = match ($dimension) {
            'branch' => ['branches', 'name'],
            'doctor' => ['health_doctors', 'name'],
            default => ['health_departments', 'name'],
        };

        if (!Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->whereIn('id', $ids)
            ->pluck($column, 'id')
            ->all();
    }

    protected static function emptyBuckets(): array
    {
        $out = ['current' => 0.0];
        foreach (self::AGING_BUCKETS as $days) {
            $out['d' . $days] = 0.0;
        }
        $out['older'] = 0.0;

        return $out;
    }

    protected static function bucketFor(int $days): string
    {
        if ($days <= 0) {
            return 'current';
        }
        foreach (self::AGING_BUCKETS as $edge) {
            if ($days <= $edge) {
                return 'd' . $edge;
            }
        }

        return 'older';
    }

    protected static function dayBefore(string $date): string
    {
        return \Carbon\Carbon::parse($date)->subDay()->toDateString();
    }

    // ═══════════════════════════════════════════════════════════════════
    // CSV
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Stream a report as CSV.
     *
     * Streamed, never assembled in memory or written to disk: the live host
     * writes at a crawl, and a year of ledger lines held in an array is how a
     * report screen turns into a 500.
     */
    public static function csv(string $filename, array $header, iterable $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $handle = fopen('php://output', 'w');
            // Excel opens a BOM-less UTF-8 file as Latin-1 and mangles every
            // Urdu name in it.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $header);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
