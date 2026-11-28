@extends('hr.layout')
@section('title', 'Accept employee invitation')
@section('content')
    <section class="login" aria-labelledby="accept-invitation-title">
        <h1 id="accept-invitation-title">Join {{ $workspace->name }}</h1>
        <p>Confirm the TaxNest account selected by your workspace owner.</p>
        <form method="POST" action="{{ route('hr.invitations.accept', compact('token')) }}">
            @csrf
            <div class="fields">
                <label>TaxNest email <input name="email" type="email" maxlength="255" autocomplete="username" required></label>
                <label>Password <input name="password" type="password" maxlength="255" autocomplete="current-password" required></label>
                <label>Two-step verification code, if enabled <input name="two_step_code" maxlength="64" autocomplete="one-time-code"></label>
            </div>
            <button type="submit">Accept employee access</button>
        </form>
    </section>
@endsection
