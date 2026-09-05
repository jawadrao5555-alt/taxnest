@php use App\Support\PosLocale; @endphp
@php($hLocale = PosLocale::normalize(session(PosLocale::SESSION_KEY)))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if(app()->getLocale() === 'ur') dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('health.panel_name') }} — {{ __('health.login') }}</title>
    <link rel="stylesheet" href="{{ asset('css/mobile.css?v=2.6') }}">
    @include('partials.font-css', ['fontFamilies' => 'figtree:400,500,600,700'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Standalone <html>: the Urdu font comes from no layout, so include it here. --}}
    @include('partials.urdu-font')
    <style>
        .health-hero { background: radial-gradient(ellipse 900px 600px at 15% -10%, #115e59 0%, transparent 55%), linear-gradient(135deg, #042f2e 0%, #0f766e 55%, #0891b2 100%); }
        .health-cta { background: linear-gradient(135deg, #0f766e, #0d9488); }
        .health-cta:hover { background: linear-gradient(135deg, #115e59, #0f766e); }
    </style>
</head>
<body class="font-sans text-gray-900 antialiased">
<div class="health-hero min-h-screen flex flex-col items-center justify-center p-4">
    <div class="w-full max-w-md">

        <div class="text-center mb-6">
            <a href="{{ url('/healthcare') }}" class="inline-flex w-16 h-16 rounded-2xl bg-white/15 ring-1 ring-white/25 items-center justify-center">
                <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            </a>
            <h1 class="mt-4 text-2xl font-black text-white tracking-tight">{{ __('health.panel_name') }}</h1>
            <p class="text-teal-100/85 text-sm mt-1">{{ __('health.panel_tagline') }}</p>
        </div>

        <div class="bg-white rounded-2xl shadow-2xl p-6 sm:p-7">
            <h2 class="text-lg font-black text-gray-900">{{ __('health.login_title') }}</h2>
            <p class="text-sm text-gray-500 mt-0.5">{{ __('health.login_subtitle') }}</p>

            @if(session('error'))
                <div class="mt-4 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">
                    {{ session('error') }}
                </div>
            @endif
            @if($errors->any())
                <div class="mt-4 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ url('/health/login') }}" class="mt-5 space-y-4">
                @csrf
                <div>
                    <label for="login" class="block text-xs font-bold uppercase tracking-wide text-gray-600 mb-1.5">{{ __('health.login_identifier') }}</label>
                    <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus autocomplete="username"
                           class="w-full rounded-xl border-gray-300 focus:border-teal-500 focus:ring-teal-500 text-sm">
                </div>
                <div>
                    <label for="password" class="block text-xs font-bold uppercase tracking-wide text-gray-600 mb-1.5">{{ __('health.password') }}</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password"
                           class="w-full rounded-xl border-gray-300 focus:border-teal-500 focus:ring-teal-500 text-sm">
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="remember" value="1" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                    {{ __('health.remember_me') }}
                </label>
                <button type="submit" class="health-cta w-full py-3 rounded-xl text-white text-sm font-black tracking-wide shadow-lg transition">
                    {{ __('health.login') }}
                </button>
            </form>

            <p class="mt-5 text-center text-sm text-gray-500">
                {{ __('health.need_account') }}
                <a href="{{ url('/health/register') }}" class="font-bold text-teal-700 hover:text-teal-900">{{ __('health.register') }}</a>
            </p>
        </div>

        {{-- Guest language picker: session-only, same three locales as the panel. --}}
        <form method="POST" action="{{ url('/health/guest-language') }}" class="mt-5 flex justify-center gap-2">
            @csrf
            @foreach(['en' => 'English', 'rur' => 'Roman Urdu', 'ur' => 'اردو'] as $code => $label)
                <button type="submit" name="language" value="{{ $code }}"
                        class="px-3 py-1.5 rounded-full text-[11px] font-bold transition {{ $hLocale === $code ? 'bg-white text-teal-800' : 'bg-white/15 text-white hover:bg-white/25' }}">
                    {{ $label }}
                </button>
            @endforeach
        </form>
    </div>
</div>
</body>
</html>
