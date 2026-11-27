<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Services\HrFieldDuty;
use App\Services\HrWorkspaceAccess;
use App\Support\Hr\TenantAccess;
use Illuminate\Http\Request;

final class HrFieldDutyController extends Controller
{
    public function __construct(private HrFieldDuty $fieldDuty, private HrWorkspaceAccess $access)
    {
    }

    public function index(int $organization)
    {
        $actor = (int) auth('hr')->id();
        $membership = $this->access->membership($organization, $actor, 'workspace.read');
        $team = TenantAccess::allows($organization, $membership->organization_id, $membership->role, 'attendance.approve');
        $workspace = $membership->organization;
        $requests = $this->fieldDuty->requests($organization, $actor, $team);

        return view('hr.field-duty', compact('workspace', 'requests', 'actor', 'team'));
    }

    public function create(int $organization, int $shift)
    {
        [$assignment, $canSubmit] = $this->fieldDuty->requestableShift($organization, (int) auth('hr')->id(), $shift);

        return view('hr.field-duty-request', compact('organization', 'assignment', 'canSubmit'));
    }

    public function store(Request $request, int $organization, int $shift)
    {
        $this->fieldDuty->submit($organization, (int) auth('hr')->id(), $shift, $request->all());

        return redirect()->route('hr.field-duty.index', compact('organization'))->with('status', 'Field-duty request submitted.');
    }

    public function review(Request $request, int $organization, int $fieldDuty)
    {
        $this->fieldDuty->review($organization, (int) auth('hr')->id(), $fieldDuty, $request->all());

        return redirect()->route('hr.field-duty.index', compact('organization'))->with('status', 'Field-duty decision recorded.');
    }
}
