<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrOrganizationMembership;
use App\Services\HrAttendance;
use App\Services\HrEmployeeDirectory;
use App\Services\HrFieldDuty;
use App\Services\HrWorkspaceAccess;
use App\Support\Hr\TenantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class HrWorkspaceController extends Controller
{
    public function __construct(private HrWorkspaceAccess $access, private HrEmployeeDirectory $directory, private HrAttendance $attendance)
    {
    }

    public function dashboard()
    {
        $memberships = HrOrganizationMembership::query()->where('user_id', auth('hr')->id())->where('active', true)
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->with('organization')->orderBy('organization_id')->paginate(25);

        return view('hr.workspaces', compact('memberships'));
    }

    public function show(Request $request, int $organization, HrFieldDuty $fieldDuty)
    {
        $actor = (int) auth('hr')->id();
        $membership = $this->access->membership($organization, $actor, 'workspace.read');
        $allows = fn (string $ability) => TenantAccess::allows($organization, (int) $membership->organization_id, $membership->role, $ability);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'attendance' => ['nullable', 'in:team,mine'],
        ]);
        $today = CarbonImmutable::now($membership->organization->timezone);
        $from = $filters['from'] ?? $today->startOfWeek()->toDateString();
        $to = $filters['to'] ?? max($from, $today->endOfWeek()->toDateString());
        $ownOnly = !$allows('attendance.read') || ($allows('self.attendance') && ($filters['attendance'] ?? null) === 'mine');
        $employees = $allows('employee.read') ? $this->directory->list($organization, $actor)->withQueryString() : null;
        $members = $allows('employee.write') ? HrOrganizationMembership::query()->where('organization_id', $organization)
            ->where('active', true)->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->with('user:id,name,email')->orderBy('user_id')->get() : collect();
        $shifts = $this->attendance->report($organization, $actor, $ownOnly, $from, $to);
        $requestableShifts = $allows('self.attendance') ? $shifts->getCollection()->filter(
            fn ($shift) => (int) $shift->employee->user_id === $actor && $shift->employee->status === 'active'
                && !$shift->field_duty_approved && !$shift->field_duty_pending && $fieldDuty->requestIsEnabled($shift),
        )->pluck('id')->flip() : collect();
        $attendance = $this->attendance;

        return view('hr.workspace', compact('membership', 'allows', 'employees', 'members', 'shifts', 'ownOnly', 'from', 'to', 'attendance', 'today', 'actor', 'requestableShifts'));
    }

    public function createEmployee(Request $request, int $organization)
    {
        $this->directory->create($organization, (int) auth('hr')->id(), $request->all());

        return redirect()->route('hr.organizations.show', compact('organization'))->with('status', 'Employee created.');
    }

    public function updateEmployee(Request $request, int $organization, int $employee)
    {
        $this->directory->update($organization, (int) auth('hr')->id(), $employee, $request->all());

        return redirect()->route('hr.organizations.show', compact('organization'))->with('status', 'Employee updated.');
    }

    public function assignShift(Request $request, int $organization)
    {
        $shift = $this->attendance->schedule($organization, (int) auth('hr')->id(), $request->all());

        return redirect()->route('hr.organizations.show', ['organization' => $organization, 'from' => $shift->work_date, 'to' => $shift->work_date])
            ->with('status', 'Shift assignment saved.');
    }

    public function punch(Request $request, int $organization, int $shift)
    {
        $this->access->membership($organization, (int) auth('hr')->id(), 'self.attendance');
        $data = $request->validate(['action' => ['required', 'string'], 'request_key' => ['required', 'string']]);
        $this->attendance->punch($organization, (int) auth('hr')->id(), $shift, $data['action'], $data['request_key']);

        return redirect()->route('hr.organizations.show', ['organization' => $organization, 'attendance' => 'mine'])
            ->with('status', 'Attendance recorded.');
    }
}
