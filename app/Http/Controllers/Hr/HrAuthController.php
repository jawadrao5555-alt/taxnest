<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CompanySessionGeneration;
use App\Models\HrOrganizationMembership;
use App\Services\TwoStepLogin;
use App\Support\Hr\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class HrAuthController extends Controller
{
    public function show(Request $request)
    {
        $workspace = filter_var($request->query('workspace'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return view('hr.login', ['workspace' => $workspace ?: null]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'workspace' => ['required', 'integer', 'min:1'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $workspace = (int) $data['workspace'];
        $memberships = HrOrganizationMembership::query()->where('organization_id', $workspace)->where('active', true)
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->whereHas('user', fn ($query) => $query->where('is_active', true)->whereRaw('LOWER(email) = ?', [Str::lower($data['email'])]))
            ->limit(2)->get();
        $member = $memberships->count() === 1 ? $memberships->first() : null;
        $userId = $member && TenantAccess::allows($workspace, (int) $member->organization_id, $member->role, 'workspace.read')
            ? (int) $member->user_id : 0;
        if (!auth('hr')->attempt(['id' => $userId, 'is_active' => true, 'password' => $data['password']])) {
            throw ValidationException::withMessages(['email' => 'The workspace ID, email or password is incorrect.']);
        }
        $request->session()->regenerate();

        return TwoStepLogin::challenge($request, 'hr') ?? redirect()->route('hr.organizations.show', ['organization' => $workspace]);
    }

    public function logout(Request $request)
    {
        auth('hr')->logoutCurrentDevice();
        $request->session()->forget(['two_step_enrollment.hr', 'two_step_verified.hr', CompanySessionGeneration::key('hr')]);
        $request->session()->regenerate(true);

        return redirect()->route('hr.login');
    }
}
