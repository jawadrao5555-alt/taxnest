<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Services\HrLeave;
use App\Services\HrWorkspaceAccess;
use App\Support\Hr\TenantAccess;
use Illuminate\Http\Request;

final class HrLeaveController extends Controller
{
    public function __construct(private HrLeave $leave, private HrWorkspaceAccess $access)
    {
    }

    public function index(Request $request, int $organization)
    {
        $actor = (int) auth('hr')->id();
        $membership = $this->access->membership($organization, $actor, 'workspace.read');
        $canReview = TenantAccess::allows($organization, $membership->organization_id, $membership->role, 'leave.approve');
        $canApply = TenantAccess::allows($organization, $membership->organization_id, $membership->role, 'self.leave');
        $ownOnly = !$canReview || ($canApply && $request->query('view') === 'mine');
        $requests = $this->leave->list($organization, $actor, $ownOnly);
        $balance = $canApply ? $this->leave->balance($organization, $actor) : null;
        $workspace = $membership->organization;

        return view('hr.leave', compact('requests', 'canReview', 'canApply', 'actor', 'ownOnly', 'balance', 'workspace'));
    }

    public function store(Request $request, int $organization)
    {
        $this->leave->submit($organization, (int) auth('hr')->id(), $request->all());

        return redirect()->route('hr.leave.index', ['organization' => $organization, 'view' => 'mine'])->with('status', 'Leave request submitted.');
    }

    public function review(Request $request, int $organization, int $leave)
    {
        $this->leave->decide($organization, (int) auth('hr')->id(), $leave, $request->all());

        return redirect()->route('hr.leave.index', compact('organization'))->with('status', 'Leave decision recorded.');
    }

    public function cancel(int $organization, int $leave)
    {
        $this->leave->cancel($organization, (int) auth('hr')->id(), $leave);

        return redirect()->route('hr.leave.index', ['organization' => $organization, 'view' => 'mine'])->with('status', 'Leave request cancelled.');
    }
}
