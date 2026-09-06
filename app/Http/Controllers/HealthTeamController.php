<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HealthDepartment;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthAudit\HealthAuditRecorder;
use App\Services\HealthModuleService;
use App\Services\HealthPlatformService;
use App\Services\HealthScopeService;
use App\Services\PlanLimitService;
use App\Support\HealthPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

/**
 * Healthcare staff accounts: role, branch posting, department posting and the
 * owner's optional per-member capability delegation.
 *
 * Two rules that are enforced here and nowhere else:
 *  - Only the OWNER may delegate capabilities or change somebody's role. An
 *    administrator can create and deactivate staff, but cannot promote itself
 *    or hand out clinical/financial access it does not hold.
 *  - Nobody can be created as an owner. The owner is the account that bought
 *    the product; a second one would silently split responsibility for the
 *    organisation's medical and financial data.
 */
class HealthTeamController extends Controller
{
    private function user()
    {
        return Auth::guard(HealthPanel::GUARD)->user();
    }

    private function company(): ?Company
    {
        return Company::find(app('currentCompanyId'));
    }

    /** Roles a team form may assign (everything except the owner). */
    private function assignableRoles(): array
    {
        return array_values(array_filter(
            HealthAccessService::ROLES,
            fn (string $role) => $role !== HealthAccessService::ROLE_OWNER
        ));
    }

    public function index()
    {
        $company = $this->company();
        $user = $this->user();

        $staff = HealthPlatformService::staff($company);

        // preventLazyLoading is on in production: everything the view reads
        // must be resolved here.
        $departments = HealthDepartment::query()->orderBy('name')->get();
        $branches = HealthPlatformService::accessibleBranches();

        $extraDepartments = [];
        if (Schema::hasTable('health_department_user') && $staff->isNotEmpty()) {
            $extraDepartments = DB::table('health_department_user')
                ->whereIn('user_id', $staff->pluck('id'))
                ->get()
                ->groupBy('user_id')
                ->map(fn ($rows) => $rows->pluck('health_department_id')->map(fn ($id) => (int) $id)->all())
                ->all();
        }

        $branchAssignments = [];
        if (Schema::hasTable('branch_user') && $staff->isNotEmpty()) {
            $branchAssignments = DB::table('branch_user')
                ->whereIn('user_id', $staff->pluck('id'))
                ->get()
                ->groupBy('user_id')
                ->map(fn ($rows) => $rows->pluck('branch_id')->map(fn ($id) => (int) $id)->all())
                ->all();
        }

        return view('health.team', [
            'company' => $company,
            'staff' => $staff,
            'roles' => $this->assignableRoles(),
            'departments' => $departments,
            'branches' => $branches,
            'extraDepartments' => $extraDepartments,
            'branchAssignments' => $branchAssignments,
            'isOwner' => HealthAccessService::isOwner($user),
            'customizableRoles' => HealthAccessService::CUSTOMIZABLE_ROLES,
            'delegatable' => HealthAccessService::delegatableCapabilities($company),
            'roleDefaults' => HealthAccessService::ROLE_CAPABILITIES,
            'enabledModules' => HealthModuleService::enabled($company),
        ]);
    }

    public function store(Request $request)
    {
        $company = $this->company();
        $actor = $this->user();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', \App\Support\IdentityScope::uniqueEmail(\App\Support\ProductCatalog::ERPS)],
            'phone' => 'nullable|string|max:20',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'health_role' => 'required|in:' . implode(',', $this->assignableRoles()),
            'health_department_id' => [
                'nullable',
                Rule::exists('health_departments', 'id')->where(fn ($q) => $q->where('company_id', $company->id)),
            ],
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => [
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $company->id)),
            ],
        ]);

        if (!HealthScopeService::canAccessDepartment($actor, $data['health_department_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.team_department_not_yours'));
        }
        foreach ($data['branch_ids'] ?? [] as $branchId) {
            if (!HealthScopeService::canAccessBranch($actor, $branchId)) {
                return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
            }
        }

        // Package staff quota. Checked on the server, not just by hiding the
        // form: the healthcare packages advertise a staff count and the panel
        // must actually hold that line. Same shared predicate every other
        // product uses, so an admin override or blanket grant still applies.
        $allowance = PlanLimitService::canAddUser((int) $company->id);
        if (($allowance['allowed'] ?? false) !== true) {
            return back()->withInput()->with(
                'error',
                $allowance['reason'] ?? __('health.team_limit_reached')
            );
        }

        $attributes = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'company_id' => $company->id,
            'role' => 'employee',
            'is_active' => true,
        ];
        // New columns must be $fillable or Eloquent drops them silently.
        if (Schema::hasColumn('users', 'health_role')) {
            $attributes['health_role'] = $data['health_role'];
        }
        if (Schema::hasColumn('users', 'health_department_id')) {
            $attributes['health_department_id'] = $data['health_department_id'] ?? null;
        }

        $member = User::create($attributes);

        $this->syncBranches($member, $data['branch_ids'] ?? []);
        HealthScopeService::forget($member->id);

        return redirect()->route('health.team')->with('success', __('health.team_created'));
    }

    public function update(Request $request, $id)
    {
        $company = $this->company();
        $actor = $this->user();
        $member = $this->findMember($id, $company);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($member->id)],
            'phone' => 'nullable|string|max:20',
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'health_role' => 'nullable|in:' . implode(',', $this->assignableRoles()),
            'health_department_id' => [
                'nullable',
                Rule::exists('health_departments', 'id')->where(fn ($q) => $q->where('company_id', $company->id)),
            ],
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => [
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $company->id)),
            ],
        ]);

        if (!HealthScopeService::canAccessDepartment($actor, $data['health_department_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.team_department_not_yours'));
        }

        $member->name = $data['name'];
        $member->email = $data['email'];
        $member->phone = $data['phone'] ?? null;
        if (!empty($data['password'])) {
            $member->password = Hash::make($data['password']);
        }

        // Role changes are the owner's alone: an administrator must not be able
        // to hand anybody (including itself) clinical or financial access.
        if (!empty($data['health_role']) && HealthAccessService::isOwner($actor)
            && Schema::hasColumn('users', 'health_role')) {
            if ($member->health_role !== $data['health_role']) {
                $member->health_role = $data['health_role'];
                // A role change invalidates the old delegated set — it was ticked
                // against a different job. Back to that role's least-privilege
                // defaults until the owner deliberately delegates again.
                if (Schema::hasColumn('users', 'health_permissions')) {
                    $member->health_permissions = null;
                }
            }
        }

        if (Schema::hasColumn('users', 'health_department_id')) {
            $member->health_department_id = $data['health_department_id'] ?? null;
        }

        $member->save();

        $this->syncBranches($member, $data['branch_ids'] ?? []);
        HealthScopeService::forget($member->id);

        return redirect()->route('health.team')->with('success', __('health.team_updated'));
    }

    /**
     * Owner-only capability delegation.
     *
     * Posting `use_defaults` clears the set and returns the member to their
     * role's least-privilege defaults. Anything outside the enabled modules is
     * dropped by the service rather than stored.
     */
    public function permissions(Request $request, $id)
    {
        $company = $this->company();
        $actor = $this->user();

        if (!HealthAccessService::isOwner($actor)) {
            abort(403, __('health.team_owner_only'));
        }

        $member = $this->findMember($id, $company);

        $request->validate([
            'use_defaults' => 'nullable|boolean',
            'capabilities' => 'nullable|array',
            'capabilities.*' => 'string',
        ]);

        // What the member could do BEFORE the change. Captured here rather than
        // after, because "who widened whose access" is only answerable if the
        // old set survives the save.
        $before = HealthAccessService::customSet($member) ?? [];

        if ($request->boolean('use_defaults')) {
            HealthAccessService::setCustomSet($member, null, $company);

            HealthAuditRecorder::record('access.permissions.reset', [
                'company_id' => $company?->id,
                'category' => 'access',
                'action' => 'revoked',
                'entity_type' => 'users',
                'entity_id' => $member->id,
                'entity_label' => $member->name ?: $member->email,
                'old' => ['capabilities' => implode(', ', $before)],
                'new' => ['capabilities' => __('health.audit_role_defaults')],
            ]);

            return redirect()->route('health.team')->with('success', __('health.team_permissions_reset'));
        }

        $saved = HealthAccessService::setCustomSet($member, $request->input('capabilities', []), $company);

        if ($saved === null) {
            return redirect()->route('health.team')->with('error', __('health.team_role_not_customizable'));
        }

        HealthAuditRecorder::record('access.permissions.changed', [
            'company_id' => $company?->id,
            'category' => 'access',
            'action' => array_diff($saved, $before) ? 'granted' : 'revoked',
            'entity_type' => 'users',
            'entity_id' => $member->id,
            'entity_label' => $member->name ?: $member->email,
            'old' => ['capabilities' => implode(', ', $before)],
            'new' => ['capabilities' => implode(', ', $saved)],
            'meta' => [
                'added' => implode(', ', array_values(array_diff($saved, $before))),
                'removed' => implode(', ', array_values(array_diff($before, $saved))),
            ],
        ]);

        return redirect()->route('health.team')
            ->with('success', __('health.team_permissions_saved', ['count' => count($saved)]));
    }

    /**
     * Deactivate a staff account rather than delete it.
     *
     * HealthAuth logs an inactive account out on its very next request, so this
     * takes effect immediately even for someone already signed in. The row
     * stays because healthcare records must keep naming who recorded them.
     */
    public function toggleActive($id)
    {
        $company = $this->company();
        $actor = $this->user();
        $member = $this->findMember($id, $company);

        if ($member->id === $actor->id) {
            return back()->with('error', __('health.team_no_self_deactivate'));
        }
        if (HealthAccessService::isOwner($member)) {
            return back()->with('error', __('health.team_no_owner_deactivate'));
        }

        // Reactivation is a second way to add an active account, so it has to
        // pass the same quota as creation — otherwise a clinic could park
        // members as inactive and switch them back on past the package.
        if (!$member->is_active) {
            $allowance = PlanLimitService::canAddUser((int) $company->id);
            if (($allowance['allowed'] ?? false) !== true) {
                return back()->with('error', $allowance['reason'] ?? __('health.team_limit_reached'));
            }
        }

        $member->is_active = !$member->is_active;
        $member->save();

        return redirect()->route('health.team')->with(
            'success',
            $member->is_active ? __('health.team_activated') : __('health.team_deactivated')
        );
    }

    /** Extra departments a member covers, beyond their primary posting. */
    public function syncDepartments(Request $request, $id)
    {
        $company = $this->company();
        $member = $this->findMember($id, $company);

        $request->validate([
            'department_ids' => 'nullable|array',
            'department_ids.*' => [
                Rule::exists('health_departments', 'id')->where(fn ($q) => $q->where('company_id', $company->id)),
            ],
        ]);

        if (!Schema::hasTable('health_department_user')) {
            return back()->with('error', __('health.team_departments_unavailable'));
        }

        $ids = array_values(array_unique(array_map('intval', $request->input('department_ids', []))));

        DB::transaction(function () use ($member, $ids) {
            DB::table('health_department_user')->where('user_id', $member->id)->delete();
            foreach ($ids as $departmentId) {
                DB::table('health_department_user')->insert([
                    'user_id' => $member->id,
                    'health_department_id' => $departmentId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        HealthScopeService::forget($member->id);

        return redirect()->route('health.team')->with('success', __('health.team_departments_saved'));
    }

    /** Branch postings ride the platform's own branch_user pivot. */
    private function syncBranches(User $member, array $branchIds): void
    {
        if (!Schema::hasTable('branch_user')) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $branchIds)));

        DB::transaction(function () use ($member, $ids) {
            DB::table('branch_user')->where('user_id', $member->id)->delete();
            foreach ($ids as $branchId) {
                DB::table('branch_user')->insert([
                    'user_id' => $member->id,
                    'branch_id' => $branchId,
                    'access_level' => 'view',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /** Always company-scoped: a stray id must never reach another tenant. */
    private function findMember($id, ?Company $company): User
    {
        return User::where('company_id', $company?->id)->findOrFail($id);
    }
}
