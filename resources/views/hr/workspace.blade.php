@extends('hr.layout')
@section('title', $membership->organization->name)
@section('content')
    @php
        $organization = $membership->organization_id;
    @endphp
    <h1>{{ $membership->organization->name }}</h1>
    <p>{{ $membership->organization->timezone }}</p>
    <div class="actions">
        @if ($allows('policy.read'))
            <a href="{{ route('hr.policy.show', compact('organization')) }}">Workspace policies</a>
        @endif
        @if ($allows('leave.approve') || $allows('self.leave'))
            <a href="{{ route('hr.leave.index', compact('organization')) }}">Leave requests</a>
        @endif
        @if ($allows('attendance.approve') || $allows('self.attendance'))
            <a href="{{ route('hr.field-duty.index', compact('organization')) }}">Field-duty requests</a>
        @endif
        @if ($allows('payroll.read'))
            <a href="{{ route('hr.payroll.index', compact('organization')) }}">Payroll</a>
        @endif
        @if ($allows('self.read'))
            <a href="{{ route('hr.payroll.own', compact('organization')) }}">My payslips</a>
        @endif
    </div>

    @if ($employees)
        <section aria-labelledby="directory-title">
            <h2 id="directory-title">Employee directory</h2>
            @if ($allows('employee.write'))
                <details open>
                    <summary>Add an employee</summary>
                    <form method="POST" action="{{ route('hr.employees.store', compact('organization')) }}">
                        @csrf
                        <div class="fields">
                            <label>Employee number <input name="employee_number" required maxlength="64"></label>
                            @include('hr.employee-fields', ['employee' => null])
                            <label>Joined on <input type="date" name="joined_on"></label>
                        </div>
                        <button type="submit">Add employee</button>
                    </form>
                </details>
            @endif
            @forelse ($employees as $employee)
                <p><strong>{{ $employee->full_name }}</strong> <small>{{ $employee->employee_number }} · {{ $employee->designation ?: 'No designation' }} · {{ ucfirst($employee->status) }}</small></p>
                @if ($allows('employee.invite') && $membership->organization->linked_company_id && !$employee->user_id && $employee->status === 'active')
                    <a href="{{ route('hr.employees.invitation', ['organization' => $organization, 'employee' => $employee->id]) }}">Invite {{ $employee->full_name }} to Nest HR</a>
                @endif
                @if ($allows('employee.write'))
                    <details>
                        <summary>Edit {{ $employee->full_name }}</summary>
                        <form method="POST" action="{{ route('hr.employees.update', ['organization' => $organization, 'employee' => $employee->id]) }}">
                            @csrf
                            @method('PATCH')
                            <div class="fields">
                                @include('hr.employee-fields')
                                <label>Employment status
                                    <select name="status">
                                        <option value="active" @selected($employee->status === 'active')>Active</option>
                                        <option value="inactive" @selected($employee->status === 'inactive')>Inactive</option>
                                    </select>
                                </label>
                            </div>
                            <button type="submit">Save employee</button>
                        </form>
                    </details>
                @endif
            @empty
                <p>No employees have been added.</p>
            @endforelse
            <div class="pagination">{{ $employees->links() }}</div>
        </section>
    @endif

    @if ($allows('attendance.approve') && $employees?->isNotEmpty())
        <section aria-labelledby="shift-title">
            <h2 id="shift-title">Assign a shift</h2>
            <form method="POST" action="{{ route('hr.shifts.store', compact('organization')) }}">
                @csrf
                <div class="fields">
                    <label>Employee
                        <select name="employee_id" required>
                            <option value="">Choose an employee</option>
                            @foreach ($employees->where('status', 'active') as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->full_name }} ({{ $employee->employee_number }})</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Shift date <input name="work_date" type="date" value="{{ $today->toDateString() }}" required></label>
                    <label>Start time <input name="starts_at" type="time" required></label>
                    <label>End time <input name="ends_at" type="time" required></label>
                </div>
                <p><small>End times earlier than the start time fall on the next day.</small></p>
                <button type="submit">Save shift</button>
            </form>
        </section>
    @endif

    <section aria-labelledby="attendance-title">
        <h2 id="attendance-title">{{ $ownOnly ? 'My attendance' : 'Team attendance' }}</h2>
        <form method="GET" action="{{ route('hr.organizations.show', compact('organization')) }}" class="filters">
            <label>From <input name="from" type="date" value="{{ $from }}" required></label>
            <label>To <input name="to" type="date" value="{{ $to }}" required></label>
            @if ($allows('attendance.read') && $allows('self.attendance'))
                <label>Attendance
                    <select name="attendance">
                        <option value="team" @selected(!$ownOnly)>Team</option>
                        <option value="mine" @selected($ownOnly)>My attendance</option>
                    </select>
                </label>
            @endif
            <button type="submit">Show shifts</button>
        </form>
        <div class="table">
            <table>
                <thead><tr><th scope="col">Employee</th><th scope="col">Shift</th><th scope="col">Attendance</th><th scope="col">Recorded times</th></tr></thead>
                <tbody>
                    @forelse ($shifts as $shift)
                        @php
                            $hasArrival = $shift->punches->contains('action', 'check_in');
                            $hasDeparture = $shift->punches->contains('action', 'check_out');
                            $utc = \Carbon\CarbonImmutable::now('UTC');
                            $shiftEnd = \Carbon\CarbonImmutable::parse($shift->ends_at_utc, 'UTC');
                            $action = !$hasArrival && $utc->setTimezone($shift->timezone)->toDateString() >= $shift->work_date && $utc < $shiftEnd
                                ? 'check_in' : ($hasArrival && !$hasDeparture && $utc >= $shiftEnd ? 'check_out' : null);
                        @endphp
                        <tr>
                            <td>{{ $shift->employee->full_name }}</td>
                            <td>{{ $shift->localTime($shift->starts_at_utc) }}<br>{{ $shift->localTime($shift->ends_at_utc) }}</td>
                            <td>
                                <p>{{ $attendance->status($shift) }}</p>
                                @if ($shift->field_duty_approved)
                                    <p>Field duty approved</p>
                                @elseif ($requestableShifts->has($shift->id))
                                    <a href="{{ route('hr.field-duty.create', ['organization' => $organization, 'shift' => $shift->id]) }}">Request field duty</a>
                                @endif
                            </td>
                            <td>
                                @foreach ($shift->punches as $punch)
                                    <p>{{ $punch->action === 'check_in' ? 'In' : 'Out' }} {{ $shift->localTime($punch->occurred_at_utc) }}</p>
                                @endforeach
                                @if ($action && $allows('self.attendance') && (int) $shift->employee->user_id === $actor && $shift->employee->status === 'active')
                                    <form method="POST" action="{{ route('hr.attendance.punch', ['organization' => $organization, 'shift' => $shift->id]) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="{{ $action }}">
                                        <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                        <button type="submit">{{ $action === 'check_in' ? 'Check in' : 'Check out' }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No shifts in this date range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $shifts->links() }}</div>
    </section>
@endsection
