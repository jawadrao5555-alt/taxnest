<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HealthDepartment;
use App\Services\HealthAccessService;
use App\Services\HealthPlatformService;
use App\Services\HealthScopeService;
use App\Support\HealthPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Departments — the healthcare-specific boundary that sits inside a branch.
 *
 * Reachable only with `departments.manage`, which the path map already enforces
 * for the whole /health/departments prefix. Every write re-checks the branch is
 * one this person may touch, so an administrator of Branch A cannot file a
 * department under Branch B by editing the form.
 */
class HealthDepartmentController extends Controller
{
    private function user()
    {
        return Auth::guard(HealthPanel::GUARD)->user();
    }

    private function company(): ?Company
    {
        return Company::find(app('currentCompanyId'));
    }

    public function index()
    {
        $user = $this->user();
        $company = $this->company();

        $query = HealthDepartment::with('branch')->orderBy('name');
        HealthScopeService::applyBranchScope($query, $user);

        $limit = HealthPlatformService::departmentLimit($company);

        return view('health.departments', [
            'company' => $company,
            'departments' => $query->get(),
            'branches' => HealthPlatformService::accessibleBranches(),
            'types' => HealthDepartment::TYPES,
            'departmentLimit' => $limit,
            'departmentCount' => HealthDepartment::query()->count(),
        ]);
    }

    public function store(Request $request)
    {
        $company = $this->company();
        $user = $this->user();

        $data = $this->validated($request, null);

        // Package limit (-1 = unlimited). Checked here rather than in the view
        // alone: the button can be hidden, the POST can still arrive.
        $limit = HealthPlatformService::departmentLimit($company);
        if ($limit >= 0 && HealthDepartment::query()->count() >= $limit) {
            return back()->withInput()->with('error', __('health.dept_limit_reached', ['limit' => $limit]));
        }

        if (!HealthScopeService::canAccessBranch($user, $data['branch_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }

        HealthDepartment::create($data + ['company_id' => $company->id]);

        return redirect()->route('health.departments')->with('success', __('health.dept_created'));
    }

    public function update(Request $request, $id)
    {
        $user = $this->user();
        $department = HealthDepartment::findOrFail($id);

        if (!HealthScopeService::canAccessBranch($user, $department->branch_id)) {
            abort(403, __('health.dept_branch_not_yours'));
        }

        $data = $this->validated($request, (int) $department->id);

        if (!HealthScopeService::canAccessBranch($user, $data['branch_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }

        $department->fill($data)->save();

        return redirect()->route('health.departments')->with('success', __('health.dept_updated'));
    }

    /**
     * Deactivate rather than delete.
     *
     * A department is what other healthcare records are filed under. Removing
     * the row would orphan every future encounter, dispense and admission that
     * points at it, so the switch is `is_active` — the same "archive, never
     * destroy" rule the POS day-close follows. Staff postings are released so
     * nobody stays assigned to a department that is no longer in use.
     */
    public function deactivate($id)
    {
        $user = $this->user();
        $department = HealthDepartment::findOrFail($id);

        if (!HealthScopeService::canAccessBranch($user, $department->branch_id)) {
            abort(403, __('health.dept_branch_not_yours'));
        }

        DB::transaction(function () use ($department) {
            $department->is_active = false;
            $department->save();

            if (Schema::hasTable('health_department_user')) {
                DB::table('health_department_user')
                    ->where('health_department_id', $department->id)
                    ->delete();
            }
            if (Schema::hasColumn('users', 'health_department_id')) {
                DB::table('users')
                    ->where('company_id', $department->company_id)
                    ->where('health_department_id', $department->id)
                    ->update(['health_department_id' => null]);
            }
        });

        HealthScopeService::forget();

        return redirect()->route('health.departments')->with('success', __('health.dept_deactivated'));
    }

    public function reactivate($id)
    {
        $user = $this->user();
        $department = HealthDepartment::findOrFail($id);

        if (!HealthScopeService::canAccessBranch($user, $department->branch_id)) {
            abort(403, __('health.dept_branch_not_yours'));
        }

        $department->is_active = true;
        $department->save();

        return redirect()->route('health.departments')->with('success', __('health.dept_reactivated'));
    }

    private function validated(Request $request, ?int $ignoreId): array
    {
        $companyId = app('currentCompanyId');

        $rules = [
            'name' => 'required|string|max:255',
            'code' => [
                'nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\-]+$/',
                Rule::unique('health_departments', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($ignoreId),
            ],
            'type' => 'required|in:' . implode(',', HealthDepartment::TYPES),
            'description' => 'nullable|string|max:500',
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'is_active' => 'nullable|boolean',
        ];

        $data = $request->validate($rules);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['branch_id'] = $data['branch_id'] ?? null;
        $data['code'] = $data['code'] ?: null;

        return $data;
    }
}
