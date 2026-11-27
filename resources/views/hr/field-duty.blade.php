@extends('hr.layout')
@section('title', 'Field-duty requests')
@section('content')
    <p><a href="{{ route('hr.organizations.show', ['organization' => $workspace->id]) }}">{{ $workspace->name }} attendance</a></p>
    <h1>Field-duty requests</h1>
    <section class="table" aria-label="{{ $team ? 'Team requests' : 'My requests' }}">
        <table>
            <thead><tr><th scope="col">Employee</th><th scope="col">Shift</th><th scope="col">Assignment</th><th scope="col">Status</th></tr></thead>
            <tbody>
                @forelse ($requests as $duty)
                    <tr>
                        <td>{{ $duty->shift->employee->full_name }}</td>
                        <td>{{ $duty->localTime($duty->starts_at_utc) }}<br>{{ $duty->localTime($duty->ends_at_utc) }}</td>
                        <td>{{ $duty->assignment_note }}</td>
                        <td>
                            <p>{{ $duty->status === 'requested' ? 'Pending review' : ucfirst($duty->status) }}</p>
                            @if ($duty->decision_note)<p>{{ $duty->decision_note }}</p>@endif
                            @if ($team && $duty->status === 'requested' && (int) $duty->requested_by !== $actor)
                                <form method="POST" action="{{ route('hr.field-duty.review', ['organization' => $workspace->id, 'fieldDuty' => $duty->id]) }}">
                                    @csrf @method('PATCH')
                                    <label>Decision note <textarea name="decision_note" maxlength="500" rows="2"></textarea></label>
                                    <div class="actions">
                                        <button type="submit" name="decision" value="approved">Approve</button>
                                        <button type="submit" name="decision" value="declined">Decline</button>
                                    </div>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">No field-duty requests.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="pagination">{{ $requests->links() }}</div>
    </section>
@endsection
