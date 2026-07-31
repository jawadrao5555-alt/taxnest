<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('pos.restaurant_features_off_title') }} — {{ $company->name ?? 'NestPOS' }}</title>
    @vite(['resources/css/app.css'])
    <style>
        body { background: #0f1b1e; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6 text-white antialiased">
    <div class="max-w-md w-full text-center">
        <div class="mx-auto w-16 h-16 rounded-2xl bg-gradient-to-br from-teal-600 to-teal-800 flex items-center justify-center mb-6">
            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
            </svg>
        </div>

        <h1 class="text-2xl font-bold mb-2">{{ __('pos.restaurant_features_are_off') }}</h1>
        <p class="text-teal-100/80 leading-relaxed mb-1">
            {!! __('pos.restaurant_locked_body', ['role' => e($roleLabel), 'business' => '<span class="font-semibold text-white">' . e($company->name ?? __('pos.this_business')) . '</span>']) !!}
        </p>
        <p class="text-teal-100/80 leading-relaxed mb-8">
            {{ __('pos.restaurant_locked_ask_admin') }}
        </p>

        <div class="bg-white/5 border border-white/10 rounded-xl p-4 text-left text-sm text-teal-100/70 mb-8">
            <p class="font-semibold text-white mb-1">{{ __('pos.what_admin_needs_to_do') }}</p>
            <p>{!! __('pos.restaurant_locked_admin_steps', ['billing' => '<span class="text-white">' . e(__('pos.billing_word')) . '</span>']) !!}</p>
        </div>

        <div class="flex items-center justify-center gap-3">
            <button onclick="location.reload()" class="px-5 py-2.5 rounded-lg bg-teal-600 hover:bg-teal-700 font-semibold text-sm shadow-sm transition">
                {{ __('pos.check_again') }}
            </button>
            <form method="POST" action="{{ route('pos.logout') }}">
                @csrf
                <button type="submit" class="px-5 py-2.5 rounded-lg bg-white/10 hover:bg-white/15 border border-white/10 font-semibold text-sm transition">
                    {{ __('pos.log_out') }}
                </button>
            </form>
        </div>

        <p class="mt-6 text-xs text-teal-100/50" id="auto-check-note">
            {{ __('pos.auto_recheck_note') }}
        </p>
    </div>

    <script>
        (function () {
            var INTERVAL_MS = 60000 + Math.floor(Math.random() * 10000); // ~60s + jitter so tablets don't sync up
            var checking = false;

            function checkAccess() {
                if (checking || document.hidden) return;
                checking = true;
                fetch(window.location.href, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store'
                }).then(function (res) {
                    // Middleware returns 403 JSON while features are off.
                    // Anything OK (or a redirect that resolved OK) means we're back.
                    if (res.ok) {
                        window.location.reload();
                    }
                }).catch(function () {
                    /* offline / transient error — try again next tick */
                }).finally(function () {
                    checking = false;
                });
            }

            setInterval(checkAccess, INTERVAL_MS);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) checkAccess();
            });
        })();
    </script>
</body>
</html>
