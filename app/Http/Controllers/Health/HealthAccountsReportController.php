<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthAccount;
use App\Services\HealthAccountingReportService;
use App\Services\HealthChartOfAccountsService;
use App\Services\HealthScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The financial reports (Task 1552).
 *
 * Every figure on every screen here is computed from the journal lines at read
 * time, and every screen offers the same CSV of exactly what is shown. The two
 * go together on purpose: a report an accountant cannot export is a report they
 * will rebuild in a spreadsheet from raw data, and then the spreadsheet becomes
 * the truth instead of the books.
 *
 * Drill-down is the second half of the same promise. A trial balance line opens
 * the general ledger, and a ledger line names the bill, receipt, purchase or
 * expense it came from — so "why is this number what it is" is always one click
 * away rather than an email to whoever posted it.
 */
class HealthAccountsReportController extends HealthPanelController
{
    public function index(Request $request)
    {
        $this->require('accounts.view');

        return view('health.accounts.reports', [
            'window' => $this->window($request),
            'branches' => $this->branches(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TRIAL BALANCE
    // ═══════════════════════════════════════════════════════════════════

    public function trialBalance(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;
        [$from, $to] = $this->window($request);
        $filters = $this->filters($request);

        $report = HealthAccountingReportService::trialBalance($companyId, $from, $to, $filters);

        if ($request->query('export') === 'csv') {
            return HealthAccountingReportService::csv(
                'trial-balance-' . $from . '-' . $to . '.csv',
                [__('health.acc_code'), __('health.acc_account'), __('health.acc_type'),
                    __('health.acc_opening'), __('health.acc_debit'), __('health.acc_credit'), __('health.acc_closing')],
                array_map(fn ($r) => [
                    $r['code'], $r['name'], __('health.acc_type_' . $r['type']),
                    $r['opening'], $r['debit'], $r['credit'], $r['closing'],
                ], $report['rows'])
            );
        }

        return view('health.accounts.reports.trial-balance', [
            'report' => $report,
            'from' => $from,
            'to' => $to,
            'branches' => $this->branches(),
            'filters' => $filters,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // GENERAL LEDGER
    // ═══════════════════════════════════════════════════════════════════

    public function ledger(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;
        [$from, $to] = $this->window($request);
        $filters = $this->filters($request);

        $accounts = HealthAccountingReportService::accounts($companyId);
        $accountId = (int) ($request->query('account_id') ?: (optional($accounts->first())->id ?? 0));

        $report = $accountId
            ? HealthAccountingReportService::generalLedger($companyId, $accountId, $from, $to, $filters)
            : ['account' => null, 'rows' => [], 'opening' => 0.0, 'closing' => 0.0, 'debit' => 0.0, 'credit' => 0.0];

        if ($request->query('export') === 'csv' && $report['account']) {
            return HealthAccountingReportService::csv(
                'ledger-' . $report['account']->code . '-' . $from . '-' . $to . '.csv',
                [__('health.acc_date'), __('health.acc_journal_no'), __('health.acc_memo'),
                    __('health.acc_source'), __('health.acc_debit'), __('health.acc_credit'), __('health.acc_balance')],
                array_map(fn ($r) => [
                    $r['date'], $r['journal_no'], $r['memo'],
                    trim(($r['source_type'] ?? '') . ' ' . ($r['source_reference'] ?? '')),
                    $r['debit'], $r['credit'], $r['balance'],
                ], $report['rows'])
            );
        }

        return view('health.accounts.reports.ledger', [
            'report' => $report,
            'accounts' => $accounts,
            'accountId' => $accountId,
            'from' => $from,
            'to' => $to,
            'branches' => $this->branches(),
            'filters' => $filters,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // PROFIT AND LOSS
    // ═══════════════════════════════════════════════════════════════════

    public function profitAndLoss(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;
        [$from, $to] = $this->window($request);
        $filters = $this->filters($request);

        $report = HealthAccountingReportService::profitAndLoss($companyId, $from, $to, $filters);

        if ($request->query('export') === 'csv') {
            $rows = [];
            foreach (['income' => 'acc_income', 'cost_of_sales' => 'acc_cost_of_sales', 'expenses' => 'acc_expenses'] as $bucket => $label) {
                foreach ($report[$bucket] as $r) {
                    $rows[] = [__('health.' . $label), $r['code'], $r['name'], $r['amount']];
                }
            }
            $rows[] = ['', '', __('health.acc_net_profit'), $report['net_profit']];

            return HealthAccountingReportService::csv(
                'profit-and-loss-' . $from . '-' . $to . '.csv',
                [__('health.acc_section'), __('health.acc_code'), __('health.acc_account'), __('health.acc_amount')],
                $rows
            );
        }

        return view('health.accounts.reports.profit-loss', [
            'report' => $report,
            'from' => $from,
            'to' => $to,
            'branches' => $this->branches(),
            'filters' => $filters,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // BALANCE SHEET
    // ═══════════════════════════════════════════════════════════════════

    public function balanceSheet(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;
        $asAt = $request->query('as_at') ?: now()->toDateString();
        $filters = $this->filters($request);

        $report = HealthAccountingReportService::balanceSheet($companyId, $asAt, $filters);

        if ($request->query('export') === 'csv') {
            $rows = [];
            foreach (['assets' => 'acc_type_asset', 'liabilities' => 'acc_type_liability', 'equity' => 'acc_type_equity'] as $bucket => $label) {
                foreach ($report[$bucket] as $r) {
                    $rows[] = [__('health.' . $label), $r['code'], $r['name'], $r['amount']];
                }
            }
            $rows[] = [__('health.acc_type_equity'), '', __('health.acc_current_earnings'), $report['current_earnings']];

            return HealthAccountingReportService::csv(
                'balance-sheet-' . $asAt . '.csv',
                [__('health.acc_section'), __('health.acc_code'), __('health.acc_account'), __('health.acc_amount')],
                $rows
            );
        }

        return view('health.accounts.reports.balance-sheet', [
            'report' => $report,
            'asAt' => $asAt,
            'branches' => $this->branches(),
            'filters' => $filters,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // CASH FLOW
    // ═══════════════════════════════════════════════════════════════════

    public function cashFlow(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;
        [$from, $to] = $this->window($request);
        $filters = $this->filters($request);

        $report = HealthAccountingReportService::cashFlow($companyId, $from, $to, $filters);

        if ($request->query('export') === 'csv') {
            return HealthAccountingReportService::csv(
                'cash-flow-' . $from . '-' . $to . '.csv',
                [__('health.acc_section'), __('health.acc_code'), __('health.acc_account'), __('health.acc_amount')],
                array_map(fn ($r) => [
                    __('health.acc_flow_' . $r['section']), $r['code'], $r['name'], $r['amount'],
                ], $report['rows'])
            );
        }

        return view('health.accounts.reports.cash-flow', [
            'report' => $report,
            'from' => $from,
            'to' => $to,
            'branches' => $this->branches(),
            'filters' => $filters,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // AGEING
    // ═══════════════════════════════════════════════════════════════════

    public function receivables(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;
        $asAt = $request->query('as_at') ?: now()->toDateString();

        $report = HealthAccountingReportService::receivablesAging($companyId, $asAt, $this->filters($request));

        if ($request->query('export') === 'csv') {
            return HealthAccountingReportService::csv(
                'receivables-' . $asAt . '.csv',
                [__('health.acc_date'), __('health.bill_no'), __('health.patient'), __('health.mrn'),
                    __('health.acc_days'), __('health.acc_amount')],
                array_map(fn ($r) => [
                    $r['date'], $r['bill_no'], $r['patient'], $r['mrn'], $r['days'], $r['amount'],
                ], $report['rows'])
            );
        }

        return view('health.accounts.reports.receivables', [
            'report' => $report,
            'asAt' => $asAt,
            'branches' => $this->branches(),
        ]);
    }

    public function payables(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;
        $asAt = $request->query('as_at') ?: now()->toDateString();

        $report = HealthAccountingReportService::payablesAging($companyId, $asAt, $this->filters($request));

        if ($request->query('export') === 'csv') {
            return HealthAccountingReportService::csv(
                'payables-' . $asAt . '.csv',
                [__('health.supplier'), __('health.acc_outstanding'), __('health.acc_advance')],
                array_map(fn ($r) => [$r['supplier'], $r['outstanding'], $r['advance']], $report['rows'])
            );
        }

        return view('health.accounts.reports.payables', [
            'report' => $report,
            'asAt' => $asAt,
        ]);
    }

    /**
     * One supplier's statement: what was bought, what was paid, what is left.
     *
     * Built from purchases and payments side by side rather than from a stored
     * balance, so it reconciles with what the supplier's own ledger will say —
     * which is the only test a supplier statement ever has to pass.
     */
    public function supplier(Request $request, int $id)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;
        [$from, $to] = $this->window($request);
        $filters = $this->filters($request);
        $branchScope = fn ($q) => \App\Services\HealthLedgerService::applyBranchFilter($q, $filters, 'branch_id');

        $supplier = Schema::hasTable('suppliers')
            ? DB::table('suppliers')->where('company_id', $companyId)->where('id', $id)->first()
            : null;

        abort_unless($supplier, 404);

        $purchases = Schema::hasTable('purchase_orders')
            ? DB::table('purchase_orders')
                ->where('company_id', $companyId)
                ->where('supplier_id', $id)
                ->whereIn('status', ['received', 'partial'])
                ->whereDate(DB::raw('COALESCE(received_date, order_date)'), '>=', $from)
                ->whereDate(DB::raw('COALESCE(received_date, order_date)'), '<=', $to)
                ->tap($branchScope)
                ->orderBy('id')
                ->get(['id', 'po_number', 'order_date', 'received_date', 'total_amount', 'status'])
            : collect();

        $payments = Schema::hasTable('health_supplier_payments')
            ? DB::table('health_supplier_payments')
                ->where('company_id', $companyId)
                ->where('supplier_id', $id)
                ->whereDate('paid_on', '>=', $from)
                ->whereDate('paid_on', '<=', $to)
                ->tap($branchScope)
                ->orderBy('paid_on')
                ->get()
            : collect();

        $returns = Schema::hasTable('health_pharmacy_returns') && Schema::hasColumn('health_pharmacy_returns', 'supplier_id')
            ? DB::table('health_pharmacy_returns')
                ->where('company_id', $companyId)
                ->where('supplier_id', $id)
                ->whereDate('created_at', '>=', $from)
                ->whereDate('created_at', '<=', $to)
                ->sum('refund_amount')
            : 0;

        $billed = round((float) $purchases->sum('total_amount'), 2);
        $paid = round((float) $payments->sum('amount'), 2);

        // The rows on this page cover the window; the closing balance must not.
        // A statement whose "balance due" silently ignored last year's unpaid
        // invoice would be a statement that always disagrees with the supplier.
        $lifetimeBilled = Schema::hasTable('purchase_orders')
            ? round((float) DB::table('purchase_orders')
                ->where('company_id', $companyId)
                ->where('supplier_id', $id)
                ->whereIn('status', ['received', 'partial'])
                ->whereDate(DB::raw('COALESCE(received_date, order_date)'), '<=', $to)
                ->tap($branchScope)
                ->sum('total_amount'), 2)
            : 0.0;

        $lifetimePaid = Schema::hasTable('health_supplier_payments')
            ? round((float) DB::table('health_supplier_payments')
                ->where('company_id', $companyId)
                ->where('supplier_id', $id)
                ->whereDate('paid_on', '<=', $to)
                ->tap($branchScope)
                ->sum('amount'), 2)
            : 0.0;

        if ($request->query('export') === 'csv') {
            $rows = [];
            foreach ($purchases as $p) {
                $rows[] = [$p->received_date ?: $p->order_date, __('health.acc_purchase'), $p->po_number, $p->total_amount, ''];
            }
            foreach ($payments as $p) {
                $rows[] = [$p->paid_on, __('health.acc_payment'), $p->reference ?? '', '', $p->amount];
            }
            usort($rows, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));

            return HealthAccountingReportService::csv(
                'supplier-' . $id . '-' . $from . '-' . $to . '.csv',
                [__('health.acc_date'), __('health.acc_kind'), __('health.acc_reference'),
                    __('health.acc_debit'), __('health.acc_credit')],
                $rows
            );
        }

        return view('health.accounts.reports.supplier', [
            'supplier' => $supplier,
            'purchases' => $purchases,
            'payments' => $payments,
            'returns' => round((float) $returns, 2),
            'billed' => $billed,
            'paid' => $paid,
            'balance' => round($lifetimeBilled - $lifetimePaid, 2),
            'from' => $from,
            'to' => $to,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // PROFITABILITY
    // ═══════════════════════════════════════════════════════════════════

    public function profitability(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;
        [$from, $to] = $this->window($request);

        $dimension = in_array($request->query('dimension'), ['department', 'branch', 'doctor'], true)
            ? $request->query('dimension')
            : 'department';

        $report = HealthAccountingReportService::dimensionProfit(
            $companyId,
            $dimension,
            $from,
            $to,
            $this->filters($request)
        );

        if ($request->query('export') === 'csv') {
            return HealthAccountingReportService::csv(
                $dimension . '-profitability-' . $from . '-' . $to . '.csv',
                [__('health.acc_dimension_' . $dimension), __('health.acc_income'),
                    __('health.acc_cost'), __('health.acc_profit'), __('health.acc_margin')],
                array_map(fn ($r) => [$r['name'], $r['income'], $r['cost'], $r['profit'], $r['margin']], $report['rows'])
            );
        }

        return view('health.accounts.reports.profitability', [
            'report' => $report,
            'dimension' => $dimension,
            'from' => $from,
            'to' => $to,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The reporting window.
     *
     * Defaults to THIS MONTH rather than all time. A report that opens on every
     * transaction the hospital has ever recorded is slow, unreadable, and — on
     * the day somebody actually needs it — the reason they gave up and asked
     * for a spreadsheet instead.
     */
    protected function window(Request $request): array
    {
        return [
            $request->query('from') ?: now()->startOfMonth()->toDateString(),
            $request->query('to') ?: now()->toDateString(),
        ];
    }

    /**
     * The filters every report on this controller reads by.
     *
     * `branch_id` is the branch the reader PICKED. `branch_ids` is the boundary
     * their account cannot cross, and it travels with every query — including
     * the reports that have no branch picker at all, which is exactly where a
     * boundary is easiest to forget. Picking a branch outside the boundary is
     * refused outright: an emptied report would read as "that branch earned
     * nothing" rather than "you may not look".
     */
    protected function filters(Request $request): array
    {
        $companyId = (int) $this->company()->id;

        $ids = HealthScopeService::branchIdsFor($this->user());
        $confined = (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : null;

        $requested = (int) $request->query('branch_id');
        if ($requested) {
            $this->requireBranch($requested);
        }

        // A confined user's own branch always wins over whatever the query
        // string asks for.
        $branchId = $confined ?: ($requested ?: null);

        /*
         * Departments are the second fence, and the one these reports make it
         * easiest to walk around: most of them have no department picker at
         * all, so a reader posted to one ward simply omits the field and reads
         * the whole hospital's income, receivables and profitability. Naming
         * somebody else's ward is refused; naming none no longer means "all".
         */
        $departmentIds = HealthScopeService::departmentIdsFor($this->user());

        $requestedDepartment = (int) $request->query('department_id');
        if ($requestedDepartment) {
            $this->requireDepartment($requestedDepartment);
        }

        $requestedDoctor = (int) $request->query('doctor_id');
        if ($requestedDoctor) {
            $this->requireDoctor($companyId, $requestedDoctor);
        }

        $filters = array_filter([
            'branch_id' => $branchId,
            'department_id' => $requestedDepartment ?: null,
            'doctor_id' => $requestedDoctor ?: null,
        ]);

        if (is_array($ids)) {
            $filters['branch_ids'] = $ids;
        }

        if (is_array($departmentIds)) {
            $filters['department_ids'] = $departmentIds;
        }

        return $filters;
    }
}
