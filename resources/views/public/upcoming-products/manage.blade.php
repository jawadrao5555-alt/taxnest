<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Manage product interest | TaxNest</title>
    <style>
        body { margin: 0; background: #f7f8f5; color: #092a33; font: 1rem/1.6 system-ui, sans-serif; }
        main { width: min(100% - 2rem, 38rem); margin: 4rem auto; }
        button { font: inherit; color: white; background: #075952; border: 0; border-radius: .4rem; padding: .65rem 1rem; cursor: pointer; }
        a { color: #075952; }
        :is(button, a):focus-visible { outline: 2px solid #075952; outline-offset: 4px; }
    </style>
</head>
<body>
    <main>
        <h1>Manage product interest</h1>
        <p>{{ $interest->product->name }} · {{ \App\Models\UpcomingProductInterest::PURPOSES[$interest->purpose] }}</p>
        @if($interest->withdrawn_at)
            <p role="status">Your request has been withdrawn.</p>
        @elseif(!$interest->confirmed_at && $expired)
            <p role="status">This confirmation expired. Submit a new request from Upcoming Products.</p>
        @else
            @if($interest->confirmed_at)
                <p role="status">Your email address is confirmed for this request.</p>
            @else
                <p>Confirm that you want to receive email for this request.</p>
            @endif
            <form method="POST" action="{{ route('upcoming-products.change-interest') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button type="submit" name="action" value="{{ $interest->confirmed_at ? 'withdraw' : 'confirm' }}">
                    {{ $interest->confirmed_at ? 'Withdraw this request' : 'Confirm this request' }}
                </button>
            </form>
        @endif
        <p><a href="{{ route('upcoming-products.index') }}" rel="noreferrer">Back to Upcoming Products</a></p>
    </main>
</body>
</html>
