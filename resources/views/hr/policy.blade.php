@extends('hr.layout')
@section('title', 'Workspace policies')
@section('content')
    <p><a href="{{ route('hr.organizations.show', ['organization' => $workspace->id]) }}">{{ $workspace->name }}</a></p>
    <h1>Workspace policies</h1>
    @if ($canEdit)
        <section aria-labelledby="policy-version-title">
            <h2 id="policy-version-title">Publish a policy version</h2>
            <form method="POST" action="{{ route('hr.policy.store', ['organization' => $workspace->id]) }}">
                @csrf
                <div class="fields">
                    <label>Effective date <input name="effective_from" type="date" value="{{ old('effective_from', $today) }}" min="{{ $today }}" required></label>
                    <label>Check-in grace (minutes) <input name="grace_minutes" type="number" min="0" max="1440" value="{{ old('grace_minutes', $current?->rules['grace_minutes'] ?? 5) }}" required></label>
                    <label>Absence cutoff (minutes) <input name="absent_after_minutes" type="number" min="0" max="1440" value="{{ old('absent_after_minutes', $current?->rules['absent_after_minutes'] ?? 30) }}" required></label>
                    <label>Annual leave (calendar days) <input name="annual_leave_days" type="number" min="0" max="366" value="{{ old('annual_leave_days', $current?->rules['annual_leave_days'] ?? 0) }}" required></label>
                    <label>Manual field-duty requests
                        <input type="hidden" name="manual_field_duty" value="0">
                        <input type="checkbox" name="manual_field_duty" value="1" @checked(old('manual_field_duty', $current?->rules['manual_field_duty'] ?? false))>
                    </label>
                </div>
                <button type="submit">Publish policy</button>
            </form>
        </section>
    @endif
    <section aria-labelledby="policy-history-title">
        <h2 id="policy-history-title">Policy history</h2>
        <div class="table">
            <table>
                <thead><tr><th scope="col">Version</th><th scope="col">Effective date</th><th scope="col">Grace</th><th scope="col">Absence cutoff</th><th scope="col">Annual leave</th><th scope="col">Field duty</th></tr></thead>
                <tbody>
                    @forelse ($versions as $version)
                        <tr>
                            <td>{{ $version->version }} @if ($version->id === $current?->id) (current) @endif</td>
                            <td>{{ $version->effective_from->toDateString() }}</td>
                            <td>{{ $version->rules['grace_minutes'] ?? 5 }} min</td>
                            <td>{{ $version->rules['absent_after_minutes'] ?? 30 }} min</td>
                            <td>{{ $version->rules['annual_leave_days'] ?? 0 }} days</td>
                            <td>{{ ($version->rules['manual_field_duty'] ?? false) ? 'Enabled' : 'Disabled' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No policy versions have been published.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $versions->links() }}</div>
    </section>
@endsection
