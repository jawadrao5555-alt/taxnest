<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthCharge;
use App\Models\HealthDoctor;
use App\Models\HealthDoctorSettlement;
use App\Models\HealthDoctorShare;
use App\Models\HealthDoctorShareRule;
use App\Services\HealthAccountingReportService;
use App\Services\HealthChartOfAccountsService;
use App\Services\HealthDoctorShareService;
use App\Services\HealthFiscalPeriodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Doctor compensation (Task 1552).
 *
 * A consultant's share of what they earned for the hospital, from the rule that
 * defines it through to the money leaving the drawer. Four stages, deliberately
 * separate:
 *
 *   RULE       what this doctor gets, on which kind of charge
 *   ACCRUAL    one row per charge, with the rule FROZEN onto it
 *   SETTLEMENT a month's accruals gathered into one payout, reviewed
 *   PAYMENT    approved, then paid
 *
 * The freeze is the important part. Changing a percentage next month must not
 * silently rewrite what the doctor was owed last month — so the accrual keeps
 * its own basis, rate and base amount, and a rule edit only affects work done
 * after it. A doctor who was told 40% and later sees 35% on old work has been
 * given a reason to distrust every figure the hospital shows them.
 *
 * Nothing on these screens carries a diagnosis or a clinical note. Doctor
 * compensation is a finance question that happens to be about clinicians.
 */
class HealthDoctorShareController extends HealthPanelController
{
    // ═══════════════════════════════════════════════════════════════════
    // RULES
    // ═══════════════════════════════════════════════════════════════════

    public function rules(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        $rules = Schema::hasTable('health_doctor_share_rules')
            ? HealthDoctorShareRule::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->with(['doctor:id,name', 'department:id,name'])
                ->tap(fn ($q) => \App\Services\HealthScopeService::applyBranchScope($q, $this->user()))
                ->tap(fn ($q) => \App\Services\HealthScopeService::applyDepartmentScope($q, $this->user()))
                ->orderByDesc('is_active')
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
            : collect();

        return view('health.accounts.shares.rules', [
            'rules' => $rules,
            'doctors' => $this->selectableDoctors(),
            'departments' => \App\Services\HealthScopeService::selectableDepartments($this->user()),
            'branches' => $this->branches(),
            'categories' => HealthCharge::CATEGORIES,
            'bases' => HealthDoctorShareRule::BASES,
            'baseAmounts' => HealthDoctorShareRule::BASE_AMOUNTS,
            'settings' => HealthFiscalPeriodService::settings($companyId),
            'canManage' => $this->can('accounts.manage'),
        ]);
    }

    public function storeRule(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $data = $this->validateRule($request);

        HealthDoctorShareRule::withoutGlobalScopes()->create($data + [
            'company_id' => $companyId,
            'is_active' => true,
            'created_by' => $this->user()?->id,
        ]);

        return back()->with('success', __('health.dsh_rule_saved'));
    }

    public function updateRule(Request $request, int $id)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $rule = HealthDoctorShareRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->requireOwnRule($rule);

        $rule->forceFill($this->validateRule($request))->save();

        return back()->with('success', __('health.dsh_rule_saved'));
    }

    public function toggleRule(int $id)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $rule = HealthDoctorShareRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->requireOwnRule($rule);

        $rule->forceFill(['is_active' => !$rule->is_active])->save();

        return back()->with('success', __('health.dsh_rule_saved'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // ACCRUALS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Everything owed, per doctor, before anybody is paid.
     *
     * This is the review screen: an accrual can be taken out here with a reason
     * — a charge the doctor did not actually perform, a bill that was later
     * cancelled — and the row STAYS, marked excluded. A share that vanishes is a
     * share the doctor will ask about and nobody will be able to explain.
     */
    public function accruals(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        $query = HealthDoctorShare::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['doctor:id,name', 'settlement:id,settlement_no,status'])
            ->whereDate('accrual_date', '>=', $from)
            ->whereDate('accrual_date', '<=', $to)
            ->orderByDesc('accrual_date')
            ->orderByDesc('id');

        \App\Services\HealthScopeService::applyBranchScope($query, $this->user());
        \App\Services\HealthScopeService::applyDepartmentScope($query, $this->user());

        if ($doctorId = (int) $request->query('doctor_id')) {
            $this->requireDoctor($companyId, $doctorId);
            $query->where('health_doctor_id', $doctorId);
        }
        if (($status = $request->query('status')) && in_array($status, HealthDoctorShare::STATUSES, true)) {
            $query->where('status', $status);
        }

        $summary = HealthDoctorShareService::summary(
            $companyId,
            $from,
            $to,
            $this->viewBranchId(),
            $this->branchBoundary(),
            $this->departmentBoundary()
        );

        if ($request->query('export') === 'csv') {
            $rows = $query->limit(5000)->get()->map(fn ($s) => [
                $s->accrual_date instanceof \DateTimeInterface ? $s->accrual_date->toDateString() : $s->accrual_date,
                $s->doctor?->name,
                $s->charge_category,
                $s->description,
                $s->base_amount,
                $s->rate,
                $s->share_amount,
                __($s->statusLabelKey()),
            ]);

            return HealthAccountingReportService::csv(
                'doctor-shares-' . $from . '-' . $to . '.csv',
                [__('health.acc_date'), __('health.doctor'), __('health.acc_category'), __('health.acc_memo'),
                    __('health.dsh_base'), __('health.dsh_rate'), __('health.dsh_share'), __('health.status')],
                $rows
            );
        }

        return view('health.accounts.shares.accruals', [
            'shares' => $query->paginate(60)->withQueryString(),
            'summary' => $summary,
            'doctors' => $this->selectableDoctors(),
            'statuses' => HealthDoctorShare::STATUSES,
            'from' => $from,
            'to' => $to,
            'canManage' => $this->can('accounts.manage'),
        ]);
    }

    public function excludeShare(Request $request, int $id)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $share = HealthDoctorShare::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->requireBranch($share->branch_id);

        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        HealthDoctorShareService::exclude($share, $data['reason'], $this->user());

        return back()->with('success', __('health.dsh_excluded'));
    }

    public function restoreShare(int $id)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $share = HealthDoctorShare::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->requireBranch($share->branch_id);

        HealthDoctorShareService::restore($share, $this->user());

        return back()->with('success', __('health.dsh_restored'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // SETTLEMENTS
    // ═══════════════════════════════════════════════════════════════════

    public function settlements(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        $query = HealthDoctorSettlement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with('doctor:id,name')
            ->tap(fn ($q) => \App\Services\HealthScopeService::applyBranchScope($q, $this->user()))
            ->orderByDesc('period_to')
            ->orderByDesc('id');

        $reachable = $this->reachableDoctorIds();
        if (is_array($reachable)) {
            $query->whereIn('health_doctor_id', $reachable ?: [0]);
        }

        if ($doctorId = (int) $request->query('doctor_id')) {
            $this->requireDoctor($companyId, $doctorId);
            $query->where('health_doctor_id', $doctorId);
        }
        if (($status = $request->query('status')) && in_array($status, HealthDoctorSettlement::STATUSES, true)) {
            $query->where('status', $status);
        }

        return view('health.accounts.shares.settlements', [
            'settlements' => $query->paginate(40)->withQueryString(),
            'doctors' => $this->selectableDoctors(),
            'statuses' => HealthDoctorSettlement::STATUSES,
            'canManage' => $this->can('accounts.manage'),
            'canApprove' => $this->can('accounts.approve'),
        ]);
    }

    public function buildSettlement(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $data = $request->validate([
            'health_doctor_id' => ['required', 'integer'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
        ]);

        $doctorId = $this->ownDoctorId($companyId, $data['health_doctor_id']);
        if (!$doctorId) {
            return back()->withInput()->withErrors(['health_doctor_id' => __('health.dsh_doctor_missing')]);
        }

        $settlement = HealthDoctorShareService::buildSettlement(
            $companyId,
            $doctorId,
            $data['period_from'],
            $data['period_to'],
            $this->user(),
            $this->viewBranchId(),
            $this->branchBoundary()
        );

        return redirect()
            ->route('health.accounts.settlement', $settlement->id)
            ->with('success', __('health.dset_built', ['no' => $settlement->settlement_no]));
    }

    public function settlement(int $id)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        $settlement = HealthDoctorSettlement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['doctor:id,name', 'shares', 'payFrom'])
            ->findOrFail($id);

        $this->requireBranch($settlement->branch_id);
        $this->requireDoctor($companyId, $settlement->health_doctor_id);

        return view('health.accounts.shares.settlement', [
            'settlement' => $settlement,
            'fundAccounts' => $this->reachableFundAccounts($companyId),
            'canManage' => $this->can('accounts.manage'),
            'canApprove' => $this->can('accounts.approve'),
        ]);
    }

    /**
     * A deduction against a payout — an advance the doctor took, a shortfall.
     *
     * Editable only while the payout is a draft, and always with a reason. Once
     * it is approved the amount is a posted liability and changing it silently
     * would put the ledger and the payout slip out of step.
     */
    public function updateSettlement(Request $request, int $id)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $settlement = HealthDoctorSettlement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->requireBranch($settlement->branch_id);

        if (!$settlement->isDraft()) {
            return back()->withErrors(['settlement' => __('health.dset_locked')]);
        }

        $data = $request->validate([
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_reason' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $deduction = round((float) ($data['deduction_amount'] ?? 0), 2);

        if ($deduction > 0 && trim((string) ($data['deduction_reason'] ?? '')) === '') {
            return back()->withErrors(['deduction_reason' => __('health.dset_deduction_reason')]);
        }

        if ($deduction > round((float) $settlement->gross_amount, 2)) {
            return back()->withErrors(['deduction_amount' => __('health.dset_deduction_too_big')]);
        }

        $settlement->forceFill([
            'deduction_amount' => $deduction,
            'deduction_reason' => $data['deduction_reason'] ?? null,
            'notes' => $data['notes'] ?? null,
        ])->save();

        HealthDoctorShareService::recompute($settlement);

        return back()->with('success', __('health.dset_saved'));
    }

    public function detachShare(int $id, int $shareId)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $settlement = HealthDoctorSettlement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->requireBranch($settlement->branch_id);

        $share = HealthDoctorShare::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('health_doctor_settlement_id', $settlement->id)
            ->findOrFail($shareId);

        HealthDoctorShareService::detach($settlement, $share);

        return back()->with('success', __('health.dset_detached'));
    }

    /**
     * Sign it off. Needs the approver's right, not the preparer's.
     *
     * This is the moment the hospital admits it owes the money: expense and
     * payable post here, not at accrual. Posting at accrual would put an
     * unreviewed estimate into a ledger the owner reads as fact.
     */
    public function approveSettlement(int $id)
    {
        $this->require('accounts.approve');
        $companyId = (int) $this->company()->id;

        $settlement = HealthDoctorSettlement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->requireBranch($settlement->branch_id);

        HealthDoctorShareService::approve($settlement, $this->user());

        return back()->with('success', __('health.dset_approved'));
    }

    public function paySettlement(Request $request, int $id)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        $settlement = HealthDoctorSettlement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->requireBranch($settlement->branch_id);

        $data = $request->validate([
            'pay_method' => ['required', Rule::in(['cash', 'bank'])],
            'paid_from_account_id' => ['nullable', 'integer'],
            'pay_reference' => ['nullable', 'string', 'max:120'],
        ]);

        /*
         * A named account is a decision, so it is honoured or REFUSED — never
         * quietly swapped. Dropping an unreachable id to null hands the payout
         * to the generic cash line: the slip says "paid from the bank", the
         * ledger says the drawer, and nobody is told. The same goes for naming
         * a bank account on a cash payment.
         */
        $accountId = null;
        if (!empty($data['paid_from_account_id'])) {
            $account = $this->ownFundAccount($companyId, $data['paid_from_account_id']);

            if (!$account) {
                return back()->withInput()->withErrors(['paid_from_account_id' => __('health.acc_account_missing')]);
            }

            $fits = $data['pay_method'] === 'bank' ? (bool) $account->is_bank : (bool) $account->is_cash;
            if (!$fits) {
                return back()->withInput()->withErrors(['paid_from_account_id' => __('health.acc_fund_method_mismatch')]);
            }

            $accountId = (int) $account->id;
        }

        HealthDoctorShareService::pay(
            $settlement,
            $data['pay_method'],
            $accountId,
            $data['pay_reference'] ?? null,
            $this->user()
        );

        return back()->with('success', __('health.dset_paid'));
    }

    public function reverseSettlement(Request $request, int $id)
    {
        $this->require('accounts.approve');
        $companyId = (int) $this->company()->id;

        $settlement = HealthDoctorSettlement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->requireBranch($settlement->branch_id);

        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        HealthDoctorShareService::reverse($settlement, $data['reason'], $this->user());

        return back()->with('success', __('health.dset_reversed'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // STATEMENTS
    // ═══════════════════════════════════════════════════════════════════

    /** The finance desk's view of one doctor's earnings. */
    public function statement(Request $request, int $id)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        $doctorId = $this->ownDoctorId($companyId, $id);
        abort_unless($doctorId, 404);

        return $this->renderStatement(
            $request,
            $companyId,
            $doctorId,
            'health.accounts.shares.statement',
            $this->branchBoundary()
        );
    }

    /**
     * A doctor's own earnings screen.
     *
     * Rides `dashboard.view` rather than an accounts right, and resolves the
     * doctor from the SIGNED-IN account's linked profile — never from the URL.
     * A consultant may see what they earned; they may not see what the
     * consultant next door earned, and no id they can type changes that.
     */
    public function myEarnings(Request $request)
    {
        $this->require('dashboard.view');
        $companyId = (int) $this->company()->id;

        $own = $this->ownDoctorIds();
        if (!$own) {
            abort(403, __('health.denied_no_permission'));
        }

        return $this->renderStatement($request, $companyId, (int) $own[0], 'health.accounts.shares.my-earnings');
    }

    /**
     * @param array|null $branchIds branch boundary for a FINANCE reader; a
     *                              doctor reading their own earnings passes
     *                              null, because their money is theirs wherever
     *                              in the organisation they earned it.
     */
    protected function renderStatement(
        Request $request,
        int $companyId,
        int $doctorId,
        string $view,
        ?array $branchIds = null
    ) {
        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        $statement = HealthDoctorShareService::statement($companyId, $doctorId, $from, $to, $branchIds);
        $doctor = HealthDoctor::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find($doctorId);

        if ($request->query('export') === 'csv') {
            return HealthAccountingReportService::csv(
                'doctor-statement-' . $doctorId . '-' . $from . '-' . $to . '.csv',
                [__('health.acc_date'), __('health.acc_category'), __('health.acc_memo'),
                    __('health.dsh_base'), __('health.dsh_share'), __('health.status')],
                $statement['shares']->map(fn ($s) => [
                    $s->accrual_date instanceof \DateTimeInterface ? $s->accrual_date->toDateString() : $s->accrual_date,
                    $s->charge_category,
                    $s->description,
                    $s->base_amount,
                    $s->share_amount,
                    __($s->statusLabelKey()),
                ])
            );
        }

        return view($view, [
            'doctor' => $doctor,
            'statement' => $statement,
            'from' => $from,
            'to' => $to,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════

    protected function validateRule(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'health_doctor_id' => ['nullable', 'integer'],
            'health_department_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'charge_category' => ['nullable', 'string', 'max:32'],
            'basis' => ['required', Rule::in(HealthDoctorShareRule::BASES)],
            'value' => ['required', 'numeric', 'min:0'],
            'base' => ['required', Rule::in(HealthDoctorShareRule::BASE_AMOUNTS)],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:999'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        /*
         * A rule with NO branch applies to EVERY branch's charges, so it is an
         * organisation-wide instrument and not something a branch-confined
         * accountant may write. They must name one of their own branches; the
         * picker already offers nothing else, and this is what stops the same
         * request being sent by hand with the field blanked.
         */
        $branchId = !empty($data['branch_id']) ? (int) $data['branch_id'] : null;
        $boundary = $this->branchBoundary();

        if ($branchId) {
            $this->requireBranch($branchId);
        } elseif (is_array($boundary)) {
            // Somebody posted to one branch gets that branch filled in for them
            // — the form has no picker and their intent is not in doubt. With
            // several branches in reach and none named, the rule would be
            // organisation-wide, so it has to be said out loud.
            $branchId = count($boundary) === 1 ? (int) $boundary[0] : null;

            if (!$branchId) {
                throw ValidationException::withMessages([
                    'branch_id' => [__('health.dsh_branch_required')],
                ]);
            }
        }

        // Same for who the rule pays and which department it covers: a named
        // doctor or department out of reach is refused outright, never dropped
        // to null — a null here WIDENS the rule to everybody.
        $doctorId = null;
        if (!empty($data['health_doctor_id'])) {
            $doctorId = $this->ownDoctorId((int) $this->company()->id, $data['health_doctor_id']);
            if (!$doctorId) {
                throw ValidationException::withMessages([
                    'health_doctor_id' => [__('health.dsh_doctor_missing')],
                ]);
            }
        }

        $departmentId = !empty($data['health_department_id']) ? (int) $data['health_department_id'] : null;
        if ($departmentId && !\App\Services\HealthScopeService::canAccessDepartment($this->user(), $departmentId)) {
            abort(403, __('health.denied_no_permission'));
        }

        $category = $data['charge_category'] ?? HealthDoctorShareRule::CATEGORY_ALL;
        if ($category !== HealthDoctorShareRule::CATEGORY_ALL
            && !in_array($category, HealthCharge::CATEGORIES, true)) {
            $category = HealthDoctorShareRule::CATEGORY_ALL;
        }

        // A percentage above 100 is always a typo — 40 typed as 400 hands the
        // doctor four times what the hospital collected.
        $value = round((float) $data['value'], 2);
        if ($data['basis'] === HealthDoctorShareRule::BASIS_PERCENT) {
            $value = min($value, 100);
        }

        return [
            'name' => $data['name'],
            'health_doctor_id' => $doctorId,
            'health_department_id' => $departmentId,
            'branch_id' => $branchId,
            'charge_category' => $category,
            'basis' => $data['basis'],
            'value' => $value,
            'base' => $data['base'],
            'min_amount' => isset($data['min_amount']) ? round((float) $data['min_amount'], 2) : null,
            'max_amount' => isset($data['max_amount']) ? round((float) $data['max_amount'], 2) : null,
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'priority' => (int) ($data['priority'] ?? 0),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * An existing rule this person may actually change.
     *
     * A rule with no branch governs the whole organisation, so it belongs to
     * whoever can see the whole organisation. Letting a branch-confined
     * accountant edit or switch one off changes what every other branch's
     * doctors are paid.
     */
    protected function requireOwnRule(HealthDoctorShareRule $rule): void
    {
        if (!$rule->branch_id && is_array($this->branchBoundary())) {
            abort(403, __('health.denied_no_permission'));
        }

        $this->requireBranch($rule->branch_id);
    }

    /** Never trust a doctor id that arrived on a form or in a URL. */
    protected function ownDoctorId(int $companyId, $id): ?int
    {
        $id = (int) $id;
        if (!$id) {
            return null;
        }

        $doctor = HealthDoctor::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find($id);

        if (!$doctor) {
            return null;
        }

        // Branch AND department: a consultant in another ward is out of reach
        // even when the whole hospital is one branch.
        $reachable = \App\Services\HealthScopeService::canAccessBranch($this->user(), $doctor->branch_id)
            && \App\Services\HealthScopeService::canAccessDepartment($this->user(), $doctor->health_department_id);

        return $reachable ? $id : null;
    }

    /**
     * The account a payout may actually be credited to.
     *
     * Two tests, not one: it has to be a fund account (cash or bank, never an
     * income line), and it has to be one this person may reach — a branch's own
     * bank account belongs to that branch.
     */
    protected function ownFundAccount(int $companyId, $id)
    {
        $id = (int) $id;
        if (!$id || !$this->reachableAccountId($companyId, $id)) {
            return null;
        }

        return HealthChartOfAccountsService::fundAccounts($companyId)->firstWhere('id', $id);
    }

    protected function ownFundAccountId(int $companyId, $id): ?int
    {
        $account = $this->ownFundAccount($companyId, $id);

        return $account ? (int) $account->id : null;
    }

    protected function viewBranchId(): ?int
    {
        $ids = \App\Services\HealthScopeService::branchIdsFor($this->user());

        return (is_array($ids) && count($ids) === 1) ? (int) $ids[0] : null;
    }
}
