<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>TaxNest Admin account recovery</title>
    <style>
        body { font: 1rem system-ui, sans-serif; background: #0f172a; color: #f1f5f9; }
        main { max-width: 38rem; margin: 3rem auto; padding: 1.5rem; background: #1e293b; border-radius: .75rem; }
        label, form { display: block; margin: 1rem 0; }
        input:not([type=hidden]) { display: block; box-sizing: border-box; width: 100%; padding: .6rem; margin-top: .25rem; }
        button { padding: .55rem .8rem; cursor: pointer; }
        a { color: #a5f3fc; }
        code { overflow-wrap: anywhere; }
    </style>
</head>
<body>
<main>
    <h1>Admin account recovery</h1>
    @if(session('status'))
        <p role="status">{{ session('status') }}</p>
    @endif
    @foreach($errors->all() as $message)
        <p role="alert">{{ $message }}</p>
    @endforeach

    @if($screen === 'settings')
        <p>Set up two independent verified emails for this shared admin account. Each email can receive a reset link, and each slot has its own backup code.</p>
        @foreach([1, 2] as $slot)
            @php($contact = $contacts->get($slot))
            <form method="post" action="{{ route('admin.recovery.enroll') }}">
                @csrf
                <input type="hidden" name="slot" value="{{ $slot }}">
                <h2>Recovery email {{ $slot }}</h2>
                @if($contact)
                    <p>{{ $contact->email }} · Verified · {{ $contact->code_hash ? 'Backup code active' : 'Backup code used' }}</p>
                @endif
                <label>Recovery email <input name="email" type="email" maxlength="254" value="{{ $contact->email ?? '' }}" required></label>
                <label>Current password <input name="password" type="password" autocomplete="current-password" required></label>
                <button>Send verification link</button>
            </form>
        @endforeach
    @elseif($screen === 'verify')
        <form method="post" action="{{ $action }}">
            @csrf
            <label>Current password <input name="password" type="password" autocomplete="current-password" required></label>
            <button>Verify email and issue a new backup code</button>
        </form>
    @elseif($screen === 'code')
        <p>Save this one-time backup code in your password manager. It is shown once.</p>
        <p><code>{{ $backup }}</code></p>
        <p><a href="{{ route('admin.recovery.settings') }}">Back to recovery settings</a></p>
    @elseif($screen === 'forgot')
        <form method="post" action="{{ route('admin.recovery.send') }}">
            @csrf
            <label>Verified recovery email <input name="email" type="email" autocomplete="email" maxlength="254" required></label>
            <button>Send reset link</button>
        </form>
        <p><a href="{{ route('admin.recovery.backup') }}">Use a backup recovery code</a></p>
    @else
        <form method="post" action="{{ route('admin.recovery.finish') }}">
            @csrf
            @if($screen === 'reset')
                <input type="hidden" name="token" value="{{ $token }}">
            @else
                <label>One-time backup code <input type="password" name="code" autocomplete="off" required></label>
            @endif
            <label>New password (at least 14 characters) <input type="password" name="password" minlength="14" maxlength="72" autocomplete="new-password" required></label>
            <label>Confirm new password <input type="password" name="password_confirmation" autocomplete="new-password" required></label>
            <button>Reset admin password</button>
        </form>
    @endif
    <p><a href="{{ route('admin.login') }}">Back to Admin sign-in</a></p>
</main>
</body>
</html>
