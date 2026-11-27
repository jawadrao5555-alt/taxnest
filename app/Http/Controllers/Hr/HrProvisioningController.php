<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\HrOrganization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HrProvisioningController extends Controller
{
    public function index()
    {
        AdminUser::requireSuperAdministrator();
        $workspaces = HrOrganization::query()->withCount(['employees', 'memberships'])->latest('id')->paginate(25);

        return view('hr.provisioning', compact('workspaces'));
    }

    public function create(Request $request)
    {
        $admin = AdminUser::requireSuperAdministrator();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'timezone' => ['required', 'timezone'],
            'owner_id' => ['required', 'integer', 'min:1'],
        ]);
        $workspace = DB::transaction(function () use ($data, $admin) {
            $this->activeUser($data['owner_id'], 'owner_id');
            $workspace = HrOrganization::create(['name' => $data['name'], 'timezone' => $data['timezone'], 'status' => 'active']);
            $workspace->memberships()->create(['user_id' => $data['owner_id'], 'role' => 'owner', 'active' => true]);
            AdminAuditLog::log($admin->id, 'hr_workspace_created', 'HrOrganization', $workspace->id, ['owner_id' => $data['owner_id']]);

            return $workspace;
        }, 3);

        return redirect()->route('admin.hr.index')->with('status', 'HR workspace '.$workspace->id.' created.');
    }

    public function addMember(Request $request, int $organization)
    {
        $admin = AdminUser::requireSuperAdministrator();
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'role' => ['required', 'in:hr_admin,manager,employee'],
        ]);
        DB::transaction(function () use ($organization, $data, $admin) {
            $workspace = HrOrganization::query()->where('status', 'active')->lockForUpdate()->findOrFail($organization);
            $this->activeUser($data['user_id']);
            $membership = $workspace->memberships()->where('user_id', $data['user_id'])->first();
            if ($membership && ($membership->role !== $data['role'] || !$membership->active)) {
                throw ValidationException::withMessages(['user_id' => 'This account already has a workspace membership.']);
            }
            if (!$membership) {
                $membership = $workspace->memberships()->create($data + ['active' => true]);
                AdminAuditLog::log($admin->id, 'hr_membership_granted', 'HrOrganizationMembership', $membership->id,
                    ['organization_id' => $organization, 'user_id' => $membership->user_id, 'role' => $membership->role]);
            }
        }, 3);

        return redirect()->route('admin.hr.index')->with('status', 'Workspace membership granted.');
    }

    public function members(int $organization)
    {
        AdminUser::requireSuperAdministrator();
        $workspace = HrOrganization::query()->findOrFail($organization);
        $members = $workspace->memberships()->with('user:id,name,email,is_active')->orderBy('id')->paginate(25);

        return view('hr.members', compact('workspace', 'members'));
    }

    public function changeMembership(Request $request, int $organization, int $member)
    {
        $admin = AdminUser::requireSuperAdministrator();
        $data = $request->validate(['active' => ['required', 'boolean']]);
        DB::transaction(function () use ($organization, $member, $data, $admin) {
            $workspace = HrOrganization::query()->where('status', 'active')->lockForUpdate()->findOrFail($organization);
            $membership = $workspace->memberships()->findOrFail($member);
            abort_if($membership->role === 'owner', 403);
            $active = (bool) $data['active'];
            if ($membership->active === $active) {
                return;
            }
            if ($active) {
                $this->activeUser($membership->user_id);
            }
            $membership->update(['active' => $active]);
            AdminAuditLog::log($admin->id, $active ? 'hr_membership_reactivated' : 'hr_membership_revoked', 'HrOrganizationMembership', $member,
                ['organization_id' => $organization, 'user_id' => $membership->user_id, 'role' => $membership->role]);
        }, 3);

        return redirect()->route('admin.hr.members.index', compact('organization'))->with('status', 'Workspace membership updated.');
    }

    private function activeUser(int $id, string $field = 'user_id'): void
    {
        if (!User::query()->whereKey($id)->where('is_active', true)->lockForUpdate()->first()) {
            throw ValidationException::withMessages([$field => 'Choose an existing active TaxNest account.']);
        }
    }
}
