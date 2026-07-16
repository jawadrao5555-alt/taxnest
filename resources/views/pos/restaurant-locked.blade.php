<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurant Features Off — {{ $company->name ?? 'NestPOS' }}</title>
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

        <h1 class="text-2xl font-bold mb-2">Restaurant features are off</h1>
        <p class="text-teal-100/80 leading-relaxed mb-1">
            The {{ $roleLabel }} screen needs the restaurant module, which isn't active on
            <span class="font-semibold text-white">{{ $company->name ?? 'this business' }}</span> right now
            &mdash; usually because the free trial ended or the current plan doesn't include it.
        </p>
        <p class="text-teal-100/80 leading-relaxed mb-8">
            Please ask your admin to upgrade the plan (or re-enable restaurant features) and this screen will come right back.
        </p>

        <div class="bg-white/5 border border-white/10 rounded-xl p-4 text-left text-sm text-teal-100/70 mb-8">
            <p class="font-semibold text-white mb-1">What your admin needs to do</p>
            <p>Sign in to NestPOS, open <span class="text-white">Billing</span>, and upgrade to a plan that includes restaurant features (Pro or Unlimited).</p>
        </div>

        <div class="flex items-center justify-center gap-3">
            <button onclick="location.reload()" class="px-5 py-2.5 rounded-lg bg-teal-600 hover:bg-teal-700 font-semibold text-sm shadow-sm transition">
                Check again
            </button>
            <form method="POST" action="{{ route('pos.logout') }}">
                @csrf
                <button type="submit" class="px-5 py-2.5 rounded-lg bg-white/10 hover:bg-white/15 border border-white/10 font-semibold text-sm transition">
                    Log out
                </button>
            </form>
        </div>
    </div>
</body>
</html>
