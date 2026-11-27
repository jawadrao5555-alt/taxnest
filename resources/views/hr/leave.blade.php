@extends('hr.layout')
@section('title', 'Leave requests')
@section('content')
    <p><a href="{{ route('hr.organizations.show', ['organization' => $workspace->id]) }}">{{ $workspace->name }}</a></p>
    <h1>Leave requests</h1>
    @if ($canApply)
        <section aria-labelledby="new-leave-title">
            <h2 id="new-leave-title">Request leave</h2>
            @if ($balance)
                <p>{{ $balance['year'] }} annual leave balance: {{ $balance['remaining'] }} calendar days.</p>
                <form method="POST" action="{{ route('hr.leave.store', ['organization' => $workspace->id]) }}">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ old('request_key', \Illuminate\Support\Str::uuid()) }}">
                    <div class="fields">
                        <label>Start date <input type="date" name="starts_on" value="{{ old('starts_on') }}" required></label>
                        <label>End date <input type="date" name="ends_on" value="{{ old('ends_on') }}" required></label>
                        <label>Leave type
                            <select name="category">
                                <option value="annual" @selected(old('category') === 'annual')>Annual</option>
                                <option value="unpaid" @selected(old('category') === 'unpaid')>Unpaid</option>
                            </select>
                        </label>
                        <label>Emergency request <input type="checkbox" name="emergency" value="1" @checked(old('emergency'))></label>
                        <label>Note <textarea name="employee_note" maxlength="1000" rows="3">{{ old('employee_note') }}</textarea></label>
                    </div>
                    <button type="submit">Submit leave request</button>
                </form>
            @else
                <p>Ask your HR administrator to link your employee profile.</p>
            @endif
        </section>
    @endif
    <section aria-labelledby="leave-list-title">
        <h2 id="leave-list-title">{{ $ownOnly ? 'My requests' : 'Team requests' }}</h2>
        @if ($canApply && $canReview)
            <p><a href="{{ route('hr.leave.index', ['organization' => $workspace->id, 'view' => $ownOnly ? 'team' : 'mine']) }}">{{ $ownOnly ? 'Team requests' : 'My requests' }}</a></p>
        @endif
        <div class="table">
            <table>
                <thead><tr><th scope="col">Employee</th><th scope="col">Dates</th><th scope="col">Type</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    @forelse ($requests as $leave)
                        <tr>
                            <td>{{ $leave->employee->full_name }} @if ($leave->employee_note)<p>{{ $leave->employee_note }}</p>@endif</td>
                            <td>{{ $leave->starts_on }} to {{ $leave->ends_on }}</td>
                            <td>{{ ucfirst($leave->category) }} @if ($leave->emergency)<p>Emergency</p>@endif</td>
                            <td>
                                <p>{{ $leave->status === 'requested' ? 'Pending review' : ucfirst($leave->status) }}</p>
                                @if ($leave->decision_note)<p>{{ $leave->decision_note }}</p>@endif
                                @if ($leave->status === 'requested' && $canApply && (int) $leave->requested_by === $actor)
                                    <form method="POST" action="{{ route('hr.leave.cancel', ['organization' => $workspace->id, 'leave' => $leave->id]) }}">
                                        @csrf @method('PATCH')
                                        <button type="submit">Cancel request</button>
                                    </form>
                                @elseif ($leave->status === 'requested' && $canReview)
                                    <form method="POST" action="{{ route('hr.leave.review', ['organization' => $workspace->id, 'leave' => $leave->id]) }}">
                                        @csrf @method('PATCH')
                                        <label>Decision note <textarea name="decision_note" maxlength="1000" rows="2"></textarea></label>
                                        <div class="actions">
                                            <button type="submit" name="decision" value="approved">Approve</button>
                                            <button type="submit" name="decision" value="declined">Decline</button>
                                        </div>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No leave requests.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $requests->links() }}</div>
    </section>
@endsection
