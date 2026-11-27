<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Services\HrPolicySettings;
use App\Services\HrWorkspaceAccess;
use App\Support\Hr\TenantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class HrPolicyController extends Controller
{
    public function show(int $organization, HrWorkspaceAccess $access, HrPolicySettings $policies)
    {
        $membership = $access->membership($organization, (int) auth('hr')->id(), 'policy.read');
        $workspace = $membership->organization;
        $today = CarbonImmutable::now($workspace->timezone)->toDateString();
        $current = $policies->effectiveOn($organization, $today);
        $versions = $workspace->policyVersions()->orderByDesc('effective_from')->orderByDesc('version')->paginate(25);
        $canEdit = TenantAccess::allows($organization, $membership->organization_id, $membership->role, 'policy.write');

        return view('hr.policy', compact('workspace', 'today', 'current', 'versions', 'canEdit'));
    }

    public function store(Request $request, int $organization, HrPolicySettings $policies)
    {
        $policies->publish($organization, (int) auth('hr')->id(), $request->all());

        return redirect()->route('hr.policy.show', compact('organization'))->with('status', 'Policy version saved.');
    }
}
