@extends('hr.layout')
@section('title', 'Sign in')
@section('content')
    <section class="login" aria-labelledby="title">
        <h1 id="title">Sign in to Nest HR</h1>
        <form method="POST" action="{{ route('hr.login.store') }}">
            @csrf
            <div class="fields">
                <label>Workspace ID
                    <input name="workspace" inputmode="numeric" type="number" min="1" required value="{{ old('workspace', $workspace) }}">
                </label>
                <label>Email
                    <input name="email" type="email" autocomplete="username" required value="{{ old('email') }}">
                </label>
                <label>Password
                    <input name="password" type="password" autocomplete="current-password" required>
                </label>
            </div>
            <button type="submit">Sign in</button>
        </form>
    </section>
@endsection
