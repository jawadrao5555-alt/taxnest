@extends('hr.layout')
@section('title', 'Employee invitation')
@section('content')
    <h1>Invite {{ $employee->full_name }}</h1>
    <p><a href="{{ route('hr.organizations.show', ['organization' => $workspace->id]) }}">Back to {{ $workspace->name }}</a></p>
    <section aria-labelledby="employee-invite-title">
        <h2 id="employee-invite-title">Workspace invitation</h2>
        @isset($invitationUrl)
            <p>Share this link privately with the selected account holder. It is shown only now.</p>
            <label for="invitation-url">Private invitation link</label>
            <input id="invitation-url" readonly value="{{ $invitationUrl }}" size="72">
        @endisset
        @if ($invitation)
            <p>Invitation pending for TaxNest user #{{ $invitation->user_id }} until {{ $invitation->expires_at->copy()->setTimezone($workspace->timezone)->format('d M Y, H:i T') }}.</p>
            <form method="POST" action="{{ route('hr.employees.invitation.revoke', ['organization' => $workspace->id, 'employee' => $employee->id, 'invitation' => $invitation->id]) }}">
                @csrf
                @method('DELETE')
                <button type="submit">Revoke invitation</button>
            </form>
        @elseif ($workspace->linked_company_id && !$employee->user_id && $employee->status === 'active')
            <p>Use the existing TaxNest user ID from the linked company. The invitation grants employee access to this workspace once accepted and expires after 48 hours.</p>
            <form method="POST" action="{{ route('hr.employees.invitation.issue', ['organization' => $workspace->id, 'employee' => $employee->id]) }}">
                @csrf
                <div class="fields">
                    <label for="invite-user">TaxNest user ID <input id="invite-user" name="user_id" type="number" min="1" value="{{ old('user_id') }}" required></label>
                </div>
                <button type="submit">Create private invitation</button>
            </form>
        @else
            <p>Invitations are available for active, unlinked employees in a workspace linked to a TaxNest company.</p>
        @endif
    </section>
@endsection
