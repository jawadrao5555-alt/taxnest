@extends('hr.layout')
@section('title', 'Workspaces')
@section('content')
    <h1>My workspaces</h1>
    <section aria-label="Available workspaces">
        @forelse ($memberships as $membership)
            <p><a href="{{ route('hr.organizations.show', ['organization' => $membership->organization_id]) }}">{{ $membership->organization->name }}</a></p>
        @empty
            <p>Your account currently has no active HR workspaces.</p>
        @endforelse
        <div class="pagination">{{ $memberships->links() }}</div>
    </section>
@endsection
