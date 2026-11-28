@extends('hr.layout')
@section('title', 'Invitation unavailable')
@section('content')
    <section class="login" aria-labelledby="invitation-status-title">
        <h1 id="invitation-status-title">Invitation unavailable</h1>
        <p>This link may have expired or already been used. Ask your workspace owner for a new invitation if you still need access.</p>
        <a href="{{ route('hr.login') }}">Go to HR sign-in</a>
    </section>
@endsection
