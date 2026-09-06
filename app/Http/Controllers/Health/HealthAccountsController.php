<?php

namespace App\Http\Controllers\Health;

use App\Exceptions\LedgerReversalRefused;
use App\Exceptions\OpeningBalanceRefused;
use App\Models\HealthAccount;
use App\Models\HealthAccountReconciliation;
use App\Models\HealthBankAccount;
use App\Models\HealthExpense;
use App\Models\HealthExpenseCategory;
use App\Models\HealthFiscalPeriod;
use App\Models\HealthFundTransfer;
use App\Models\HealthJournal;
use App\Models\HealthJournalLine;
use App\Services\HealthAccountingReportService;
use App\Services\HealthChartOfAccountsService;
use App\Services\HealthDoctorShareService;
use App\Services\HealthFiscalPeriodService;
use App\Services\HealthLedgerService;
use App\Services\HealthNumberService;
use App\Services\HealthPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * The accountant's workspace (Task 1552).
 *
 * Everything a hospital's finance desk does that is NOT the cash counter:
 * the chart of accounts, manual and adjustment journals, expenses, cash and
 * bank movement, account reconciliation, and closing the month.
 *
 * Three rights meet here and are kept apart on purpose:
 *
 *   accounts.view      read the books
 *   accounts.manage    post to them
 *   accounts.approve   close a period, sign off a doctor payout
 *
 * The accountant holds the first two. They do NOT hold the third, because a
 * person who can both write a figure and bless it is not a control. And none
 * of the three carries clinical.view — a finance account reaches the money on
 * a stay without ever reaching the diagnosis behind it, which is the whole
 * reason this panel exists as its own workspace instead of a tab on billing.
 */
class HealthAccountsController extends HealthPanelController
{
    // ═══════════════════════════════════════════════════════════════════
    // DASHBOARD
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The state of the books on one screen.
     *
     * Deliberately opens with what is WRONG — unposted sources, an unbalanced
     * trial balance, a suspense balance — before it shows what is comfortable.
     * A finance dashboard that leads with the cash figure is a dashboard whose
     * warnings nobody reads.
     */
    public function index(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        // First visit seeds the chart. Nobody should have to press a button to
        // be allowed to have books.
        if ($this->can('accounts.manage')) {
            HealthChartOfAccountsService::seed($companyId, $this->user());
        }

        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $filters = $this->filters($request);

        $funds = [];
        foreach ($this->reachableFundAccounts($companyId) as $account) {
            $funds[] = [
                'account' => $account,
                'balance' => HealthLedgerService::accountBalance($companyId, $account->id, $today, $filters),
            ];
        }

        $balanceOf = function (string $key) use ($companyId, $today, $filters) {
            $id = HealthChartOfAccountsService::id($companyId, $key);

            return $id ? HealthLedgerService::accountBalance($companyId, $id, $today, $filters) : 0.0;
        };

        $pnl = HealthAccountingReportService::profitAndLoss($companyId, $monthStart, $today, $filters);
        $trial = HealthAccountingReportService::trialBalance($companyId, $monthStart, $today, $filters);

        return view('health.accounts.index', [
            'funds' => $funds,
            'receivable' => round(
                $balanceOf(HealthChartOfAccountsService::PATIENT_RECEIVABLE)
                + $balanceOf(HealthChartOfAccountsService::INSURANCE_RECEIVABLE)
                + $balanceOf(HealthChartOfAccountsService::CORPORATE_RECEIVABLE),
                2
            ),
            'payable' => $balanceOf(HealthChartOfAccountsService::SUPPLIER_PAYABLE),
            'advances' => $balanceOf(HealthChartOfAccountsService::PATIENT_ADVANCE),
            'doctor_payable' => $balanceOf(HealthChartOfAccountsService::DOCTOR_SHARE_PAYABLE),
            'suspense' => $balanceOf(HealthChartOfAccountsService::SUSPENSE),
            'tax_payable' => $balanceOf(HealthChartOfAccountsService::TAX_PAYABLE),
            'pnl' => $pnl,
            'trial' => $trial,
            'pending' => HealthPostingService::pendingCounts($companyId),
            'period' => HealthFiscalPeriodService::currentOpen($companyId),
            'settings' => HealthFiscalPeriodService::settings($companyId),
            'recentJournals' => $this->journalQuery($companyId)->limit(10)->get(),
            'branches' => $this->branches(),
            'filters' => $filters,
            'from' => $monthStart,
            'to' => $today,
        ]);
    }

    /**
     * Post everything that should already be in the books but is not.
     *
     * Auto-posting can fail — a chart account deleted, a source row saved while
     * the ledger table was mid-migration, a company that switched the books on
     * after months of trading. Without a catch-up the only remedy would be to
     * re-save every bill by hand.
     */
    public function sweep(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $counts = HealthPostingService::sweep(
            $companyId,
            $data['from'] ?? null,
            $data['to'] ?? null,
            $this->user()
        );

        $total = array_sum(array_diff_key($counts, ['failed' => 0]));

        return back()->with('success', __('health.acc_sweep_done', [
            'count' => $total,
            'failed' => $counts['failed'] ?? 0,
        ]));
    }

    // ═══════════════════════════════════════════════════════════════════
    // CHART OF ACCOUNTS
    // ═══════════════════════════════════════════════════════════════════

    public function chart(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        if ($this->can('accounts.manage')) {
            HealthChartOfAccountsService::seed($companyId, $this->user());
        }

        $accounts = HealthAccountingReportService::accounts($companyId);
        $asAt = $request->query('as_at') ?: now()->toDateString();
        $balances = HealthLedgerService::balances($companyId, null, $asAt, $this->filters($request));

        $rows = $accounts->map(function ($account) use ($balances) {
            $totals = $balances[$account->id] ?? ['debit' => 0, 'credit' => 0];

            return [
                'account' => $account,
                'balance' => $account->signedBalance($totals['debit'], $totals['credit']),
                'used' => ($totals['debit'] + $totals['credit']) > 0.005,
            ];
        });

        return view('health.accounts.chart', [
            'rows' => $rows,
            'types' => HealthAccount::TYPES,
            'asAt' => $asAt,
            'canManage' => $this->can('accounts.manage'),
        ]);
    }

    public function storeAccount(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', Rule::in(HealthAccount::TYPES)],
            'code' => ['nullable', 'string', 'max:20'],
            'subtype' => ['nullable', 'string', 'max:32'],
            'parent_id' => ['nullable', 'integer'],
            'is_cash' => ['nullable', 'boolean'],
            'is_bank' => ['nullable', 'boolean'],
            'opening_balance' => ['nullable', 'numeric'],
            'opening_balance_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $code = HealthChartOfAccountsService::suggestCode($companyId, $data['type']);
        }

        $clash = HealthAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->exists();

        if ($clash) {
            return back()->withInput()->withErrors(['code' => __('health.acc_code_taken', ['code' => $code])]);
        }

        try {
            DB::transaction(function () use ($companyId, $data, $code) {
                $account = HealthAccount::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'parent_id' => $this->ownAccountId($companyId, $data['parent_id'] ?? null),
                    'code' => $code,
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'subtype' => $data['subtype'] ?? null,
                    'is_system' => false,
                    'is_cash' => (bool) ($data['is_cash'] ?? false),
                    'is_bank' => (bool) ($data['is_bank'] ?? false),
                    'opening_balance' => round((float) ($data['opening_balance'] ?? 0), 2),
                    'opening_balance_date' => $data['opening_balance_date'] ?? null,
                    'is_active' => true,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $this->user()?->id,
                ]);

                $this->postOpening($companyId, $account);
            });
        } catch (OpeningBalanceRefused $e) {
            HealthChartOfAccountsService::flush();

            return back()->withInput()->withErrors(['opening_balance' => $e->getMessage()]);
        }

        HealthChartOfAccountsService::flush();

        return back()->with('success', __('health.acc_account_saved'));
    }

    /**
     * Stamp something reversed only once the books agree it is.
     *
     * The ledger comes out first and the row is stamped second, both inside one
     * transaction. Stamping first and posting afterwards leaves the only state
     * nobody can explain: the screen says the expense was reversed and the
     * trial balance still carries it.
     */
    protected function undo(callable $ledger, callable $stamp): void
    {
        DB::transaction(function () use ($ledger, $stamp) {
            $result = $ledger();

            if (!($result['ok'] ?? false)) {
                throw new LedgerReversalRefused(__('health.acc_reverse_failed', [
                    'reason' => $this->reasonLabel((string) ($result['reason'] ?? '')),
                ]));
            }

            $stamp();
        });
    }

    /**
     * Put the opening balance in the books, or refuse the whole edit.
     *
     * Re-stating an opening balance REVERSES the previous entry before posting
     * the new one. If the replacement is then refused — the date sits in a
     * closed period, the equity account is missing — and the account row has
     * already been saved, the screen shows a figure the ledger does not have
     * and the old entry is gone as well. So the save and the posting live in one
     * transaction and a refusal takes the save down with it.
     */
    protected function postOpening(int $companyId, HealthAccount $account, bool $always = false): void
    {
        if (!$always && abs((float) $account->opening_balance) <= 0.005) {
            return;
        }

        $result = HealthLedgerService::postOpeningBalance($companyId, $account, $this->user());

        if (!($result['ok'] ?? false)) {
            throw new OpeningBalanceRefused(__('health.acc_opening_failed', [
                'reason' => (string) ($result['reason'] ?? ''),
            ]));
        }
    }

    public function updateAccount(Request $request, int $id)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $account = HealthAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'subtype' => ['nullable', 'string', 'max:32'],
            'opening_balance' => ['nullable', 'numeric'],
            'opening_balance_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $openingChanged = round((float) ($data['opening_balance'] ?? 0), 2) !== round((float) $account->opening_balance, 2)
            || ($data['opening_balance_date'] ?? null) != optional($account->opening_balance_date)->toDateString();

        try {
            DB::transaction(function () use ($account, $data, $companyId, $openingChanged) {
                // A default account's type, code and system key are load-bearing:
                // posting resolves accounts by system key, so renaming is safe
                // and retyping is not. Only the label and the opening figure are
                // editable.
                $account->forceFill([
                    'name' => $data['name'],
                    'subtype' => $data['subtype'] ?? $account->subtype,
                    'opening_balance' => round((float) ($data['opening_balance'] ?? 0), 2),
                    'opening_balance_date' => $data['opening_balance_date'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ])->save();

                if ($openingChanged) {
                    $this->postOpening($companyId, $account->fresh(), true);
                }
            });
        } catch (OpeningBalanceRefused $e) {
            HealthChartOfAccountsService::flush();

            return back()->withInput()->withErrors(['opening_balance' => $e->getMessage()]);
        }

        HealthChartOfAccountsService::flush();

        return back()->with('success', __('health.acc_account_saved'));
    }

    /**
     * Retire an account without deleting it.
     *
     * Deleting one would orphan every journal line that ever used it. An
     * inactive account keeps its history and stops appearing on pickers, which
     * is what "we do not use that any more" actually means.
     */
    public function toggleAccount(int $id)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $account = HealthAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        if ($account->is_system && $account->is_active) {
            return back()->withErrors(['account' => __('health.acc_system_locked')]);
        }

        $account->forceFill(['is_active' => !$account->is_active])->save();
        HealthChartOfAccountsService::flush();

        return back()->with('success', __('health.acc_account_saved'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // JOURNALS
    // ═══════════════════════════════════════════════════════════════════

    public function journals(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        $query = $this->journalQuery($companyId);

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($source = $request->query('source_type')) {
            $query->where('source_type', $source);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('journal_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('journal_date', '<=', $to);
        }
        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('journal_no', 'like', '%' . $search . '%')
                    ->orWhere('memo', 'like', '%' . $search . '%')
                    ->orWhere('source_reference', 'like', '%' . $search . '%');
            });
        }

        return view('health.accounts.journals', [
            'journals' => $query->paginate(50)->withQueryString(),
            'accounts' => $this->postableAccounts($companyId),
            'branches' => $this->branches(),
            'types' => HealthJournal::TYPES,
            'sources' => HealthJournal::SOURCES,
            'canManage' => $this->can('accounts.manage'),
            'filters' => $request->only(['type', 'source_type', 'from', 'to', 'q']),
        ]);
    }

    public function journal(int $id)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        $journal = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $companyId)
            // Live throws on a lazy relation, and this page reads the account
            // behind every line.
            ->with(['lines.account', 'lines.department', 'lines.doctor'])
            ->findOrFail($id);

        $this->requireBranch($journal->branch_id);
        $this->requireJournalDepartments($journal);

        return view('health.accounts.journal', [
            'journal' => $journal,
            'canManage' => $this->can('accounts.manage'),
            'reversal' => $journal->reverses_journal_id
                ? HealthJournal::withoutGlobalScopes()->where('company_id', $companyId)->find($journal->reverses_journal_id)
                : null,
        ]);
    }

    /**
     * A hand-written journal.
     *
     * Two lines minimum, debits must equal credits, and the date must fall in
     * an open period — the same three rules every automatic posting obeys. A
     * manual entry that could break them would be a hole straight through
     * everything else on this screen.
     */
    public function storeJournal(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $data = $request->validate([
            'journal_date' => ['required', 'date'],
            'type' => ['required', Rule::in([HealthJournal::TYPE_MANUAL, HealthJournal::TYPE_ADJUSTMENT])],
            'memo' => ['required', 'string', 'max:300'],
            'branch_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:200'],
            'lines.*.health_department_id' => ['nullable', 'integer'],
            'lines.*.health_doctor_id' => ['nullable', 'integer'],
        ]);

        if (!empty($data['branch_id'])) {
            $this->requireBranch($data['branch_id']);
        }

        $valid = $this->postableAccounts($companyId)->pluck('id')->all();
        $lines = [];
        foreach ($data['lines'] as $line) {
            $accountId = (int) $line['account_id'];
            if (!in_array($accountId, $valid, true)) {
                continue;
            }

            // A department or doctor on a line is an attribution, and an
            // attribution is money on somebody's statement. Refused outright,
            // because dropping the field instead would file the amount as
            // organisation-wide — a quieter wrong answer, not a safer one.
            $this->requireDepartment($line['health_department_id'] ?? null);
            $this->requireDoctor($companyId, $line['health_doctor_id'] ?? null);

            $lines[] = [
                'account_id' => $accountId,
                'debit' => round((float) ($line['debit'] ?? 0), 2),
                'credit' => round((float) ($line['credit'] ?? 0), 2),
                'memo' => $line['memo'] ?? null,
                'health_department_id' => $line['health_department_id'] ?? null,
                'health_doctor_id' => $line['health_doctor_id'] ?? null,
            ];
        }

        $result = HealthLedgerService::post($companyId, [
            'date' => $data['journal_date'],
            'type' => $data['type'],
            'branch_id' => $data['branch_id'] ?? $this->viewBranchId(),
            'memo' => $data['memo'],
            'source_type' => HealthJournal::SRC_MANUAL,
            'lines' => $lines,
        ], $this->user());

        if (!($result['ok'] ?? false)) {
            return back()->withInput()->withErrors([
                'lines' => __('health.acc_post_failed', ['reason' => $this->reasonLabel($result['reason'] ?? '')]),
            ]);
        }

        return redirect()
            ->route('health.accounts.journal', $result['journal']->id)
            ->with('success', __('health.acc_journal_posted', ['no' => $result['journal']->journal_no]));
    }

    /**
     * Reverse a journal rather than edit it.
     *
     * There is no edit and there never will be. A ledger where a posted entry
     * can change afterwards is a ledger no auditor can rely on; the correction
     * is a second entry that says so out loud.
     */
    public function reverseJournal(Request $request, int $id)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $journal = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with('lines')
            ->findOrFail($id);

        $this->requireBranch($journal->branch_id);

        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        $result = HealthLedgerService::reverse($journal, $this->user(), $data['reason']);

        if (!($result['ok'] ?? false)) {
            return back()->withErrors([
                'journal' => __('health.acc_post_failed', ['reason' => $this->reasonLabel($result['reason'] ?? '')]),
            ]);
        }

        return back()->with('success', __('health.acc_journal_reversed'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // EXPENSES
    // ═══════════════════════════════════════════════════════════════════

    public function expenses(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        $query = HealthExpense::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['category', 'department:id,name'])
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        \App\Services\HealthScopeService::applyBranchScope($query, $this->user());
        \App\Services\HealthScopeService::applyDepartmentScope($query, $this->user());

        if ($branchId = $this->viewBranchId()) {
            $query->where('branch_id', $branchId);
        }
        if ($categoryId = $request->query('category_id')) {
            $query->where('health_expense_category_id', $categoryId);
        }

        $expenses = $query->paginate(50)->withQueryString();

        $total = HealthExpense::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', HealthExpense::STATUS_POSTED)
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->when($this->viewBranchId(), fn ($q, $v) => $q->where('branch_id', $v))
            ->tap(fn ($q) => \App\Services\HealthScopeService::applyBranchScope($q, $this->user()))
            ->tap(fn ($q) => \App\Services\HealthScopeService::applyDepartmentScope($q, $this->user()))
            ->sum('total_amount');

        return view('health.accounts.expenses', [
            'expenses' => $expenses,
            'categories' => $this->expenseCategories($companyId),
            'fundAccounts' => $this->reachableFundAccounts($companyId),
            'branches' => $this->branches(),
            'departments' => $this->departments($companyId),
            'from' => $from,
            'to' => $to,
            'total' => round((float) $total, 2),
            'canManage' => $this->can('accounts.manage'),
        ]);
    }

    public function storeExpenseCategory(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'health_account_id' => ['nullable', 'integer'],
        ]);

        HealthExpenseCategory::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'health_account_id' => $this->ownAccountId($companyId, $data['health_account_id'] ?? null),
            'is_active' => true,
        ]);

        return back()->with('success', __('health.acc_category_saved'));
    }

    public function toggleExpenseCategory(int $id)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $category = HealthExpenseCategory::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $category->forceFill(['is_active' => !$category->is_active])->save();

        return back()->with('success', __('health.acc_category_saved'));
    }

    /**
     * Record an expense, and post it in the same breath.
     *
     * A cash expense that exists as a row but not as a ledger entry is how the
     * drawer and the books drift apart, so the posting is not a later step and
     * cannot be skipped: if it fails, the expense is rolled back with it.
     */
    public function storeExpense(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $data = $request->validate([
            'expense_date' => ['required', 'date'],
            'health_expense_category_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'pay_mode' => ['required', Rule::in(HealthExpense::PAY_MODES)],
            'paid_from_account_id' => ['nullable', 'integer'],
            'payee' => ['nullable', 'string', 'max:190'],
            'supplier_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer'],
            'health_department_id' => ['nullable', 'integer'],
        ]);

        if (!empty($data['branch_id'])) {
            $this->requireBranch($data['branch_id']);
        }

        $this->requireDepartment($data['health_department_id'] ?? null);

        if ($blocked = $this->refuseClosedPeriod($companyId, $data['expense_date'])) {
            return $blocked;
        }

        $category = HealthExpenseCategory::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find($data['health_expense_category_id']);

        if (!$category) {
            return back()->withInput()->withErrors(['health_expense_category_id' => __('health.acc_category_missing')]);
        }

        $amount = round((float) $data['amount'], 2);
        $tax = round((float) ($data['tax_amount'] ?? 0), 2);

        /*
         * A named fund out of reach is refused, not quietly swapped for cash:
         * "paid from City branch's bank" silently becoming "paid from the
         * drawer" is a wrong book entry that nobody is told about, and the
         * person who typed it goes on believing the bank balance moved.
         */
        $paidFromId = $this->ownAccountId($companyId, $data['paid_from_account_id'] ?? null);
        if (!empty($data['paid_from_account_id']) && !$paidFromId) {
            return back()->withInput()->withErrors(['paid_from_account_id' => __('health.acc_account_missing')]);
        }

        $expense = null;

        DB::transaction(function () use (&$expense, $companyId, $data, $amount, $tax, $paidFromId) {
            $expense = HealthExpense::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? $this->viewBranchId(),
                'health_department_id' => $data['health_department_id'] ?? null,
                'health_expense_category_id' => $data['health_expense_category_id'],
                'expense_no' => HealthNumberService::expenseNumber($companyId),
                'expense_date' => $data['expense_date'],
                'payee' => $data['payee'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'amount' => $amount,
                'tax_amount' => $tax,
                'total_amount' => round($amount + $tax, 2),
                'pay_mode' => $data['pay_mode'],
                'paid_from_account_id' => $paidFromId,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => HealthExpense::STATUS_POSTED,
                'created_by' => $this->user()?->id,
            ]);

            $posted = HealthPostingService::postExpense($expense, $this->user());
            if (!($posted['ok'] ?? false)) {
                throw new \RuntimeException($this->reasonLabel($posted['reason'] ?? ''));
            }
        });

        return back()->with('success', __('health.acc_expense_saved', ['no' => $expense->expense_no]));
    }

    public function reverseExpense(Request $request, int $id)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $expense = HealthExpense::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->requireBranch($expense->branch_id);

        if ($expense->status === HealthExpense::STATUS_REVERSED) {
            return back()->withErrors(['expense' => __('health.acc_already_reversed')]);
        }

        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        try {
            $this->undo(
                fn () => HealthLedgerService::reverseByDedupe($companyId, 'exp:' . $expense->id, $this->user(), $data['reason']),
                fn () => $expense->forceFill([
                    'status' => HealthExpense::STATUS_REVERSED,
                    'reversed_at' => now(),
                    'reversed_by' => $this->user()?->id,
                    'reversal_reason' => $data['reason'],
                ])->save()
            );
        } catch (LedgerReversalRefused $e) {
            return back()->withErrors(['expense' => $e->getMessage()]);
        }

        return back()->with('success', __('health.acc_expense_reversed'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // CASH / BANK MOVEMENT
    // ═══════════════════════════════════════════════════════════════════

    public function transfers(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        $transfers = HealthFundTransfer::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['fromAccount', 'toAccount'])
            ->whereDate('transfer_date', '>=', $from)
            ->whereDate('transfer_date', '<=', $to)
            ->when($this->viewBranchId(), fn ($q, $v) => $q->where('branch_id', $v))
            ->tap(fn ($q) => \App\Services\HealthScopeService::applyBranchScope($q, $this->user()))
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('health.accounts.transfers', [
            'transfers' => $transfers,
            'fundAccounts' => $this->reachableFundAccounts($companyId),
            'bankAccounts' => $this->bankAccounts($companyId),
            'kinds' => HealthFundTransfer::KINDS,
            'branches' => $this->branches(),
            'from' => $from,
            'to' => $to,
            'canManage' => $this->can('accounts.manage'),
        ]);
    }

    /**
     * Move money between the organisation's own pots.
     *
     * A bank deposit, a card settlement landing, petty cash topped up — all the
     * same movement, so all one form. Modelling "deposit" separately from
     * "transfer" would give two screens that must be kept in step and one of
     * them would eventually stop posting.
     */
    public function storeTransfer(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $data = $request->validate([
            'transfer_date' => ['required', 'date'],
            'kind' => ['required', Rule::in(HealthFundTransfer::KINDS)],
            'from_account_id' => ['required', 'integer', 'different:to_account_id'],
            'to_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:300'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        if (!empty($data['branch_id'])) {
            $this->requireBranch($data['branch_id']);
        }

        if ($blocked = $this->refuseClosedPeriod($companyId, $data['transfer_date'])) {
            return $blocked;
        }

        $fromId = $this->ownAccountId($companyId, $data['from_account_id']);
        $toId = $this->ownAccountId($companyId, $data['to_account_id']);

        if (!$fromId || !$toId) {
            return back()->withInput()->withErrors(['from_account_id' => __('health.acc_account_missing')]);
        }

        $transfer = null;

        DB::transaction(function () use (&$transfer, $companyId, $data, $fromId, $toId) {
            $transfer = HealthFundTransfer::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? $this->viewBranchId(),
                'transfer_no' => HealthNumberService::transferNumber($companyId),
                'transfer_date' => $data['transfer_date'],
                'kind' => $data['kind'],
                'from_account_id' => $fromId,
                'to_account_id' => $toId,
                'amount' => round((float) $data['amount'], 2),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => HealthFundTransfer::STATUS_POSTED,
                'created_by' => $this->user()?->id,
            ]);

            $posted = HealthPostingService::postTransfer($transfer, $this->user());
            if (!($posted['ok'] ?? false)) {
                throw new \RuntimeException($this->reasonLabel($posted['reason'] ?? ''));
            }
        });

        return back()->with('success', __('health.acc_transfer_saved', ['no' => $transfer->transfer_no]));
    }

    public function reverseTransfer(Request $request, int $id)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $transfer = HealthFundTransfer::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->requireBranch($transfer->branch_id);

        if ($transfer->status === HealthFundTransfer::STATUS_REVERSED) {
            return back()->withErrors(['transfer' => __('health.acc_already_reversed')]);
        }

        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        try {
            $this->undo(
                fn () => HealthLedgerService::reverseByDedupe($companyId, 'xfer:' . $transfer->id, $this->user(), $data['reason']),
                fn () => $transfer->forceFill([
                    'status' => HealthFundTransfer::STATUS_REVERSED,
                    'reversed_at' => now(),
                    'reversed_by' => $this->user()?->id,
                    'reversal_reason' => $data['reason'],
                ])->save()
            );
        } catch (LedgerReversalRefused $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return back()->with('success', __('health.acc_transfer_reversed'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // BANK ACCOUNTS
    // ═══════════════════════════════════════════════════════════════════

    public function storeBankAccount(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'account_no' => ['nullable', 'string', 'max:60'],
            'iban' => ['nullable', 'string', 'max:40'],
            'branch_name' => ['nullable', 'string', 'max:150'],
            'opening_balance' => ['nullable', 'numeric'],
            'opening_balance_date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        if (!empty($data['branch_id'])) {
            $this->requireBranch($data['branch_id']);
        }

        try {
            DB::transaction(function () use ($companyId, $data) {
                // Each bank account gets its own ledger account. Pooling three
                // banks into one "Bank" line makes every reconciliation
                // impossible, and the statement is per account whether the books
                // like it or not.
                $account = HealthAccount::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'code' => HealthChartOfAccountsService::suggestCode($companyId, HealthAccount::TYPE_ASSET),
                    'name' => $data['title'],
                    'type' => HealthAccount::TYPE_ASSET,
                    'subtype' => 'bank',
                    'cash_flow' => HealthAccount::FLOW_OPERATING,
                    'is_bank' => true,
                    'is_active' => true,
                    'opening_balance' => round((float) ($data['opening_balance'] ?? 0), 2),
                    'opening_balance_date' => $data['opening_balance_date'] ?? null,
                    'created_by' => $this->user()?->id,
                ]);

                HealthBankAccount::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'branch_id' => $data['branch_id'] ?? $this->viewBranchId(),
                    'health_account_id' => $account->id,
                    'title' => $data['title'],
                    'bank_name' => $data['bank_name'] ?? null,
                    'account_no' => $data['account_no'] ?? null,
                    'iban' => $data['iban'] ?? null,
                    'branch_name' => $data['branch_name'] ?? null,
                    'opening_balance' => round((float) ($data['opening_balance'] ?? 0), 2),
                    'opening_balance_date' => $data['opening_balance_date'] ?? null,
                    'is_active' => true,
                ]);

                $this->postOpening($companyId, $account);
            });
        } catch (OpeningBalanceRefused $e) {
            HealthChartOfAccountsService::flush();

            return back()->withInput()->withErrors(['opening_balance' => $e->getMessage()]);
        }

        HealthChartOfAccountsService::flush();

        return back()->with('success', __('health.acc_bank_saved'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // RECONCILIATION
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Tie an account's book balance to what the outside world says it is.
     *
     * The same screen serves a bank statement, a card acquirer's settlement
     * report and a cashier's counted drawer, because all three are the same
     * question: the books say X, reality says Y, and the difference has to be
     * named before it can be written off.
     */
    public function reconciliations(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        $accounts = $this->reachableFundAccounts($companyId);
        $selected = (int) ($request->query('account_id') ?: (optional($accounts->first())->id ?? 0));
        $asAt = $request->query('as_at') ?: now()->toDateString();

        $bookBalance = $selected
            ? HealthLedgerService::accountBalance($companyId, $selected, $asAt, $this->filters($request))
            : 0.0;

        $rows = Schema::hasTable('health_account_reconciliations')
            ? HealthAccountReconciliation::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->with('account')
                ->tap(fn ($q) => \App\Services\HealthScopeService::applyBranchScope($q, $this->user()))
                ->orderByDesc('statement_date')
                ->orderByDesc('id')
                ->limit(60)
                ->get()
            : collect();

        return view('health.accounts.reconciliations', [
            'accounts' => $accounts,
            'selected' => $selected,
            'asAt' => $asAt,
            'bookBalance' => $bookBalance,
            'rows' => $rows,
            'suspense' => HealthChartOfAccountsService::resolve($companyId, HealthChartOfAccountsService::SUSPENSE),
            'canManage' => $this->can('accounts.manage'),
        ]);
    }

    public function storeReconciliation(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $data = $request->validate([
            'health_account_id' => ['required', 'integer'],
            'statement_date' => ['required', 'date'],
            'period_from' => ['nullable', 'date'],
            'statement_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $accountId = $this->ownAccountId($companyId, $data['health_account_id']);
        if (!$accountId) {
            return back()->withInput()->withErrors(['health_account_id' => __('health.acc_account_missing')]);
        }

        $book = HealthLedgerService::accountBalance(
            $companyId,
            $accountId,
            $data['statement_date'],
            $this->filters($request)
        );
        $statement = round((float) $data['statement_balance'], 2);

        HealthAccountReconciliation::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'branch_id' => $this->viewBranchId(),
            'health_account_id' => $accountId,
            'statement_date' => $data['statement_date'],
            'period_from' => $data['period_from'] ?? null,
            'book_balance' => $book,
            'statement_balance' => $statement,
            'difference' => round($statement - $book, 2),
            'status' => HealthAccountReconciliation::STATUS_OPEN,
            'notes' => $data['notes'] ?? null,
            'created_by' => $this->user()?->id,
        ]);

        return back()->with('success', __('health.acc_recon_saved'));
    }

    /**
     * Close a reconciliation, optionally parking the unexplained difference.
     *
     * The adjustment goes to SUSPENSE, never straight to an income or expense
     * account. A difference nobody understands must stay visible as a
     * difference; burying it in "miscellaneous expense" is how a till shortage
     * becomes permanent.
     *
     * Parking the difference and shutting the reconciliation are ONE write. The
     * two failure modes of doing them separately both end with the same money
     * parked twice: two people pressing Close at once, and a run that posts the
     * adjustment and then dies before the row is stamped. So the row is locked
     * and re-read first, and the adjustment carries a key derived from the
     * reconciliation itself — one reconciliation, one adjustment, enforced by
     * the ledger's own unique index even if the lock is never reached.
     */
    public function closeReconciliation(Request $request, int $id)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $recon = HealthAccountReconciliation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->requireBranch($recon->branch_id);

        if ($recon->status === HealthAccountReconciliation::STATUS_CLOSED) {
            return back()->withErrors(['reconciliation' => __('health.acc_recon_already_closed')]);
        }

        $data = $request->validate([
            'adjust' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $this->user();

        $outcome = DB::transaction(function () use ($companyId, $recon, $data, $user) {
            $locked = HealthAccountReconciliation::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('id', $recon->id)
                ->lockForUpdate()
                ->first();

            if (!$locked || $locked->status === HealthAccountReconciliation::STATUS_CLOSED) {
                return ['ok' => false, 'error' => 'already_closed'];
            }

            $difference = round((float) $locked->difference, 2);
            $journalId = null;

            if (!empty($data['adjust']) && abs($difference) > 0.005) {
                $suspenseId = HealthChartOfAccountsService::id($companyId, HealthChartOfAccountsService::SUSPENSE);
                if (!$suspenseId) {
                    return ['ok' => false, 'error' => 'no_account'];
                }

                $result = HealthLedgerService::post($companyId, [
                    'date' => $locked->statement_date,
                    'type' => HealthJournal::TYPE_ADJUSTMENT,
                    'branch_id' => $locked->branch_id,
                    'memo' => __('health.jrn_memo_reconcile', ['date' => (string) $locked->statement_date]),
                    'source_type' => HealthJournal::SRC_MANUAL,
                    'source_id' => $locked->id,
                    'dedupe_key' => 'recadj:' . $locked->id,
                    'lines' => [
                        [
                            'account_id' => $locked->health_account_id,
                            'debit' => $difference > 0 ? abs($difference) : 0,
                            'credit' => $difference < 0 ? abs($difference) : 0,
                        ],
                        [
                            'account_id' => $suspenseId,
                            'debit' => $difference < 0 ? abs($difference) : 0,
                            'credit' => $difference > 0 ? abs($difference) : 0,
                        ],
                    ],
                ], $user);

                if (!($result['ok'] ?? false)) {
                    return ['ok' => false, 'error' => 'post_failed', 'reason' => $result['reason'] ?? ''];
                }

                $journalId = $result['journal']->id ?? null;
            }

            $locked->forceFill([
                'status' => HealthAccountReconciliation::STATUS_CLOSED,
                'adjustment_journal_id' => $journalId,
                'notes' => $data['notes'] ?? $locked->notes,
                'closed_at' => now(),
                'closed_by' => $user?->id,
            ])->save();

            return ['ok' => true];
        });

        if (!($outcome['ok'] ?? false)) {
            if (($outcome['error'] ?? '') === 'already_closed') {
                return back()->withErrors(['reconciliation' => __('health.acc_recon_already_closed')]);
            }

            if (($outcome['error'] ?? '') === 'no_account') {
                return back()->withErrors(['reconciliation' => __('health.acc_account_missing')]);
            }

            return back()->withErrors([
                'reconciliation' => __('health.acc_post_failed', ['reason' => $this->reasonLabel($outcome['reason'] ?? '')]),
            ]);
        }

        return back()->with('success', __('health.acc_recon_closed_ok'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // PERIODS AND SETTINGS
    // ═══════════════════════════════════════════════════════════════════

    public function periods()
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        return view('health.accounts.periods', [
            'periods' => HealthFiscalPeriodService::recent($companyId),
            'settings' => HealthFiscalPeriodService::settings($companyId),
            'current' => HealthFiscalPeriodService::currentOpen($companyId),
            'pending' => HealthPostingService::pendingCounts($companyId),
            'canApprove' => $this->can('accounts.approve'),
            'canManage' => $this->can('accounts.manage'),
        ]);
    }

    /**
     * Close a month. One way — there is no reopen.
     *
     * A reopen button turns "closed" into a suggestion, and the whole value of
     * a closed period is that the figure an owner saw last month is still the
     * figure they see today. Anything that must change afterwards goes in as a
     * dated adjustment journal, which is visible as a correction rather than
     * disguised as history.
     */
    public function closePeriod(Request $request, int $id)
    {
        $this->require('accounts.approve');
        $companyId = (int) $this->company()->id;

        $period = HealthFiscalPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $result = HealthFiscalPeriodService::close($period, $this->user(), $data['note'] ?? '');

        if (!($result['ok'] ?? false)) {
            $reason = $result['reason'] ?? '';
            $message = $reason === 'earlier_period_open'
                ? __('health.acc_period_earlier_open', ['name' => $result['period']->name ?? ''])
                : __('health.acc_period_already_closed');

            return back()->withErrors(['period' => $message]);
        }

        return back()->with('success', __('health.acc_period_closed_ok', ['name' => $period->name]));
    }

    public function ensurePeriod(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $data = $request->validate(['date' => ['required', 'date']]);
        HealthFiscalPeriodService::ensureFor($companyId, $data['date']);

        return back()->with('success', __('health.acc_period_created'));
    }

    public function settings()
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        return view('health.accounts.settings', [
            'settings' => HealthFiscalPeriodService::settings($companyId),
            'canManage' => $this->can('accounts.manage'),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $data = $request->validate([
            'fiscal_year_start_month' => ['required', 'integer', 'min:1', 'max:12'],
            'auto_post_enabled' => ['nullable', 'boolean'],
            'doctor_shares_enabled' => ['nullable', 'boolean'],
            'doctor_share_basis' => ['required', Rule::in(\App\Models\HealthAccountingSetting::BASES)],
            'books_start_date' => ['nullable', 'date'],
        ]);

        $settings = HealthFiscalPeriodService::settings($companyId);
        if (!$settings) {
            return back()->withErrors(['settings' => __('health.acc_settings_missing')]);
        }

        $settings->forceFill([
            'fiscal_year_start_month' => (int) $data['fiscal_year_start_month'],
            'auto_post_enabled' => (bool) ($data['auto_post_enabled'] ?? false),
            'doctor_shares_enabled' => (bool) ($data['doctor_shares_enabled'] ?? false),
            'doctor_share_basis' => $data['doctor_share_basis'],
            'books_start_date' => $data['books_start_date'] ?? null,
        ])->save();

        return back()->with('success', __('health.acc_settings_saved'));
    }

    /** Catch up doctor accruals on demand, from the accounts workspace. */
    public function accrueShares(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $result = HealthDoctorShareService::accrue(
            $companyId,
            $data['from'] ?? null,
            $data['to'] ?? null,
            $this->user()
        );

        return back()->with('success', __('health.dsh_accrued', [
            'count' => $result['created'] ?? 0,
            'skipped' => ($result['no_doctor'] ?? 0) + ($result['no_rule'] ?? 0),
        ]));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Shared helpers
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The single branch this person is confined to, or null for all of them.
     *
     * A finance user posted to one branch must not be able to file that
     * branch's expense against another one, and must not see the other's books.
     * Somebody with organisation-wide access gets null, which means "no branch
     * filter" everywhere it is used.
     */
    protected function viewBranchId(): ?int
    {
        $ids = \App\Services\HealthScopeService::branchIdsFor($this->user());

        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : null;
    }

    protected function journalQuery(int $companyId)
    {
        $query = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderByDesc('journal_date')
            ->orderByDesc('id');

        \App\Services\HealthScopeService::applyBranchScope($query, $this->user());

        return $this->applyJournalDepartmentBoundary($query);
    }

    /**
     * Hide entries that reach into a department this reader may not.
     *
     * A journal carries no department of its own — its LINES do, and an entry
     * can straddle two wards. "Show it if any line is mine" would hand over the
     * other ward's line sitting in the same entry, and the whole point of the
     * fence is that those amounts stay unseen. So the test is the strict one:
     * an entry that touches a department out of reach is not this reader's
     * entry at all. Entries with no department are organisation-wide and stay.
     */
    protected function applyJournalDepartmentBoundary($query)
    {
        $ids = $this->departmentBoundary();
        if ($ids === null) {
            return $query;
        }

        return $query->whereNotExists(function ($q) use ($ids) {
            $q->select(DB::raw(1))
                ->from('health_journal_lines as scope_l')
                ->whereColumn('scope_l.health_journal_id', 'health_journals.id')
                ->whereNotNull('scope_l.health_department_id');

            if ($ids) {
                $q->whereNotIn('scope_l.health_department_id', $ids);
            }
        });
    }

    /** The same fence, for a single entry opened by id. */
    protected function requireJournalDepartments(HealthJournal $journal): void
    {
        $ids = $this->departmentBoundary();
        if ($ids === null) {
            return;
        }

        $outside = HealthJournalLine::withoutGlobalScopes()
            ->where('health_journal_id', $journal->id)
            ->whereNotNull('health_department_id')
            ->when($ids, fn ($q) => $q->whereNotIn('health_department_id', $ids))
            ->exists();

        if ($outside) {
            abort(403, __('health.denied_no_permission'));
        }
    }

    /** Accounts a human may pick on a form — active, and this company's. */
    protected function postableAccounts(int $companyId)
    {
        $active = HealthAccountingReportService::accounts($companyId)->where('is_active', true)->values();

        if (!Schema::hasTable('health_bank_accounts')) {
            return $active;
        }

        /*
         * The manual journal is the widest door in the workspace — it can debit
         * or credit anything on the chart. A branch's own bank account is not
         * "anything": crediting City's bank from another branch's screen moves
         * their money on paper just as surely as an expense would.
         */
        $registered = HealthBankAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get(['health_account_id', 'branch_id'])
            ->groupBy('health_account_id');

        return $active->filter(function ($account) use ($registered) {
            $rows = $registered->get($account->id);

            if (!$rows || $rows->isEmpty()) {
                return true;
            }

            return $rows->contains(fn ($r) => \App\Services\HealthScopeService::canAccessBranch($this->user(), $r->branch_id));
        })->values();
    }

    /**
     * Resolve an id that arrived on a form to an account this company owns.
     *
     * Never trust the posted id: a form field is the easiest place in the whole
     * panel to point one hospital's expense at another hospital's account.
     */
    protected function ownAccountId(int $companyId, $id): ?int
    {
        return $this->reachableAccountId($companyId, $id);
    }

    protected function expenseCategories(int $companyId)
    {
        if (!Schema::hasTable('health_expense_categories')) {
            return collect();
        }

        return HealthExpenseCategory::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();
    }

    protected function bankAccounts(int $companyId)
    {
        if (!Schema::hasTable('health_bank_accounts')) {
            return collect();
        }

        return HealthBankAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with('account')
            ->tap(fn ($q) => \App\Services\HealthScopeService::applyBranchScope($q, $this->user()))
            ->orderBy('title')
            ->get();
    }

    protected function departments(int $companyId)
    {
        if (!Schema::hasTable('health_departments')) {
            return collect();
        }

        // Only the ones this account may reach. Offering the rest would put an
        // id on screen that every write path then has to refuse.
        return \App\Services\HealthScopeService::selectableDepartments($this->user())
            ->map(fn ($d) => (object) ['id' => (int) $d->id, 'name' => $d->name])
            ->values();
    }

    /**
     * Branch/department filters, applied to every ledger read on the screen.
     *
     * Two different things travel in here. `branch_id` is what the reader ASKED
     * for and can be changed at will; `branch_ids` is the boundary their account
     * cannot cross and rides along whether they asked for it or not. Asking for
     * a branch outside the boundary is refused rather than quietly ignored — a
     * silently-emptied report reads as "this branch earned nothing".
     */
    protected function filters(Request $request): array
    {
        $companyId = (int) $this->company()->id;

        $requested = (int) $request->query('branch_id');
        if ($requested) {
            $this->requireBranch($requested);
        }

        $requestedDepartment = (int) $request->query('department_id');
        if ($requestedDepartment) {
            $this->requireDepartment($requestedDepartment);
        }

        $requestedDoctor = (int) $request->query('doctor_id');
        if ($requestedDoctor) {
            $this->requireDoctor($companyId, $requestedDoctor);
        }

        $filters = array_filter([
            'branch_id' => $this->viewBranchId() ?: ($requested ?: null),
            'department_id' => $requestedDepartment ?: null,
            'doctor_id' => $requestedDoctor ?: null,
        ]);

        $boundary = $this->branchBoundary();
        if (is_array($boundary)) {
            $filters['branch_ids'] = $boundary;
        }

        $departments = $this->departmentBoundary();
        if (is_array($departments)) {
            $filters['department_ids'] = $departments;
        }

        return $filters;
    }

    /**
     * Refuse a write into a closed month, before anything is created.
     *
     * Checked here as well as in the ledger because a rejected posting inside a
     * transaction shows the accountant a rollback, not a reason.
     */
    protected function refuseClosedPeriod(int $companyId, string $date)
    {
        if (HealthFiscalPeriodService::isClosed($companyId, $date)) {
            return back()->withInput()->withErrors([
                'date' => __('health.acc_period_is_closed', ['date' => $date]),
            ]);
        }

        return null;
    }

    /** Turn a service's machine reason into something a human can act on. */
    protected function reasonLabel(string $reason): string
    {
        $key = 'health.acc_reason_' . $reason;
        $text = __($key);

        return $text === $key ? ($reason ?: '—') : $text;
    }
}
