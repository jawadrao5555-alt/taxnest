@extends('hr.layout')
@section('title', 'Request field duty')
@section('content')
    <p><a href="{{ route('hr.organizations.show', compact('organization')) }}">Attendance</a> · <a href="{{ route('hr.field-duty.index', compact('organization')) }}">Field-duty requests</a></p>
    <h1>Request field duty</h1>
    <section aria-label="Shift">
        <p>{{ $assignment->localTime($assignment->starts_at_utc) }} to {{ $assignment->localTime($assignment->ends_at_utc) }}</p>
        @if ($canSubmit)
            <form method="POST" action="{{ route('hr.field-duty.store', ['organization' => $organization, 'shift' => $assignment->id]) }}">
                @csrf
                <input type="hidden" name="request_key" value="{{ old('request_key', \Illuminate\Support\Str::uuid()) }}">
                <div class="fields">
                    <label>Assignment description <textarea name="assignment_note" required maxlength="500" rows="3">{{ old('assignment_note') }}</textarea></label>
                </div>
                <button type="submit">Submit request</button>
            </form>
        @else
            <p>A new field-duty request is unavailable for this shift. Check your existing requests or ask your HR administrator about the field-duty policy.</p>
        @endif
    </section>
@endsection
