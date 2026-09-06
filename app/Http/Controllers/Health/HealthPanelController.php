<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\HealthAccount;
use App\Models\HealthBankAccount;
use App\Models\HealthDoctor;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthChartOfAccountsService;
use App\Services\HealthPlatformService;
use App\Services\HealthScopeService;
use App\Support\HealthPanel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Shared plumbing for the clinical controllers.
 *
 * Nothing here decides anything — it only makes the four things every OPD
 * screen needs (the signed-in user, their company, their capability check and
 * their branch/department boundary) impossible to spell differently in five
 * different controllers. The moment one screen forgets a boundary, that screen
 * leaks another ward's patients.
 */
abstract class HealthPanelController extends Controller
{
    protected function user(): ?User
    {
        return Auth::guard(HealthPanel::GUARD)->user();
    }

    protected function company(): ?Company
    {
        return Company::find(app()->bound('currentCompanyId') ? app('currentCompanyId') : null);
    }

    protected function can(string $capability): bool
    {
        return HealthAccessService::can($this->user(), $capability, $this->company());
    }

    /** Refuse with the panel's own wording rather than a bare 403 page. */
    protected function require(string $capability): void
    {
        if (!$this->can($capability)) {
            abort(403, __('health.denied_no_permission'));
        }
    }

    /** Branch + department boundary, applied together, on any healthcare query. */
    protected function scope($query, string $branchColumn = 'branch_id', string $departmentColumn = 'health_department_id')
    {
        return HealthScopeService::apply($query, $this->user(), $branchColumn, $departmentColumn);
    }

    /**
     * The branch boundary as a list of ids, or NULL for the whole organisation.
     *
     * Different from viewBranchId(): that answers "which single branch is this
     * screen looking at", which is a display choice. This answers "which
     * branches may this account reach at all", which is not negotiable and must
     * be applied to every finance read, including the ones with no picker.
     */
    protected function branchBoundary(): ?array
    {
        return HealthScopeService::branchIdsFor($this->user());
    }

    /**
     * Refuse a record, or a form field, belonging to a branch out of reach.
     *
     * Listing screens hide other branches; this is what stops somebody typing
     * the id straight into the URL or the form and reaching the record anyway.
     * A record with NO branch is organisation-wide and is allowed through, the
     * same way the branch scope on every list keeps unbranched rows visible.
     */
    protected function requireBranch($branchId): void
    {
        if (!HealthScopeService::canAccessBranch($this->user(), $branchId)) {
            abort(403, __('health.denied_no_permission'));
        }
    }

    /**
     * The department boundary as a list of ids, or NULL for every department.
     *
     * The department twin of branchBoundary(). Departments and branches are not
     * the same fence: a hospital's accountant posted to Radiology sits in the
     * same building as everybody else, so the branch check lets them through and
     * only this one keeps the other wards' money — and the patient names behind
     * it — out of their reports.
     */
    protected function departmentBoundary(): ?array
    {
        return HealthScopeService::departmentIdsFor($this->user());
    }

    /**
     * Refuse a record, or a form field, belonging to a department out of reach.
     *
     * Refused, not silently dropped: dropping a department from a read empties
     * the report, which reads as "that ward earned nothing", and dropping it
     * from a WRITE widens the entry to the whole organisation instead of
     * narrowing it. A record with NO department is organisation-wide and passes,
     * matching how unbranched rows stay visible everywhere else.
     */
    protected function requireDepartment($departmentId): void
    {
        if (!HealthScopeService::canAccessDepartment($this->user(), $departmentId)) {
            abort(403, __('health.denied_no_permission'));
        }
    }

    /**
     * Refuse a doctor out of reach, by branch OR by department.
     *
     * A doctor id on a financial line is not decoration: it decides whose
     * earnings statement the amount lands on. Somebody who may not read another
     * ward's books must not be able to write into them either.
     */
    protected function requireDoctor(int $companyId, $doctorId): void
    {
        if (!$doctorId) {
            return;
        }

        $doctor = HealthDoctor::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find((int) $doctorId);

        if (!$doctor
            || !HealthScopeService::canAccessBranch($this->user(), $doctor->branch_id)
            || !HealthScopeService::canAccessDepartment($this->user(), $doctor->health_department_id)) {
            abort(403, __('health.denied_no_permission'));
        }
    }

    /**
     * Resolve an account id that arrived on a form to one this person may
     * actually move money through.
     *
     * Never trust the posted id twice over. It may belong to another hospital
     * altogether, and — the subtler one — a chart account can BE a named bank
     * account, and a bank account is posted to a branch. Cash and the shared
     * accounts belong to everybody, but paying out of, depositing into or
     * crediting City branch's bank from another branch's screen is the finance
     * version of reaching into their drawer. Only the bank register knows which
     * branch an account belongs to, so it is asked here rather than trusted
     * from the form.
     *
     * Returns null when the account is out of reach; every caller must REFUSE
     * on null rather than fall back to a default, because a payout that quietly
     * lands on the wrong account is a wrong ledger nobody was told about.
     */
    protected function reachableAccountId(int $companyId, $id): ?int
    {
        $id = (int) $id;
        if (!$id) {
            return null;
        }

        $exists = HealthAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->exists();

        if (!$exists) {
            return null;
        }

        if (Schema::hasTable('health_bank_accounts')) {
            $branches = HealthBankAccount::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('health_account_id', $id)
                ->pluck('branch_id');

            if ($branches->isNotEmpty()
                && !$branches->contains(fn ($b) => HealthScopeService::canAccessBranch($this->user(), $b))) {
                return null;
            }
        }

        return $id;
    }

    /**
     * Cash and bank accounts this person may pay out of — the picker version of
     * the check above, so an out-of-reach account is never offered in the first
     * place.
     */
    protected function reachableFundAccounts(int $companyId)
    {
        $accounts = HealthChartOfAccountsService::fundAccounts($companyId);

        if (!Schema::hasTable('health_bank_accounts') || $accounts->isEmpty()) {
            return $accounts;
        }

        $registered = HealthBankAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('health_account_id', $accounts->pluck('id'))
            ->get(['health_account_id', 'branch_id'])
            ->groupBy('health_account_id');

        return $accounts->filter(function ($account) use ($registered) {
            $rows = $registered->get($account->id);

            // Not in the bank register at all = a shared account (the cash
            // drawer, the generic bank line), reachable from every branch.
            if (!$rows || $rows->isEmpty()) {
                return true;
            }

            return $rows->contains(fn ($r) => HealthScopeService::canAccessBranch($this->user(), $r->branch_id));
        })->values();
    }

    /** Branches this person may file a record against. */
    protected function branches()
    {
        return HealthPlatformService::accessibleBranches();
    }

    /**
     * The practitioner profile(s) linked to the signed-in account.
     *
     * A doctor's own queue is derived from this, not from a role check: the
     * role says "you are a doctor", the link says "you are THIS doctor".
     */
    protected function ownDoctorIds(): array
    {
        $user = $this->user();
        if (!$user) {
            return [];
        }

        return HealthDoctor::query()
            ->where('user_id', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Doctors this person may pick on a form — active, inside their branch, and
     * inside their department posting.
     */
    protected function selectableDoctors()
    {
        $query = HealthDoctor::query()->where('is_active', true)->orderBy('name');

        return $this->scope($query)->get();
    }

    /**
     * Doctor ids in reach, or NULL when every doctor in the company is.
     *
     * For the finance lists that are keyed by doctor rather than by branch or
     * department — a payout register has no department column of its own, but
     * the consultant on each row belongs to one.
     */
    protected function reachableDoctorIds(): ?array
    {
        if ($this->branchBoundary() === null && $this->departmentBoundary() === null) {
            return null;
        }

        return $this->selectableDoctors()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
