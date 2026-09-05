@php use App\Support\HealthPanel; use App\Support\PosLocale; @endphp
@php($hLocale = PosLocale::normalize(session(PosLocale::SESSION_KEY)))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if(app()->getLocale() === 'ur') dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('health.panel_name') }} — {{ __('health.register') }}</title>
    <link rel="stylesheet" href="{{ asset('css/mobile.css?v=2.6') }}">
    @include('partials.font-css', ['fontFamilies' => 'figtree:400,500,600,700'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.urdu-font')
    <style>
        .health-hero { background: radial-gradient(ellipse 900px 600px at 15% -10%, #115e59 0%, transparent 55%), linear-gradient(135deg, #042f2e 0%, #0f766e 55%, #0891b2 100%); }
        .health-cta { background: linear-gradient(135deg, #0f766e, #0d9488); }
        .health-cta:hover { background: linear-gradient(135deg, #115e59, #0f766e); }
    </style>
</head>
<body class="font-sans text-gray-900 antialiased">
<div class="health-hero min-h-screen flex flex-col items-center justify-center p-4">
    <div class="w-full max-w-2xl">

        <div class="text-center mb-6">
            <a href="{{ url('/healthcare') }}" class="inline-flex w-14 h-14 rounded-2xl bg-white/15 ring-1 ring-white/25 items-center justify-center">
                <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            </a>
            {{-- Product first, vertical underneath it (Task 1568). --}}
            <p class="mt-3 text-sm font-black text-white tracking-tight">{{ __('health.product_name') }}</p>
            <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-[#E7BF3B]">{{ __('health.vertical_name') }}</p>
            <h1 class="mt-2 text-2xl font-black text-white tracking-tight">{{ __('health.register_title') }}</h1>
            <p class="text-teal-100/85 text-sm mt-1">{{ __('health.register_subtitle') }}</p>
        </div>

        <div class="bg-white rounded-2xl shadow-2xl p-6 sm:p-7">
            @if($errors->any())
                <div class="mb-4 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @if($pickedPlanName)
                <p class="mb-4 inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-teal-50 text-teal-800 text-xs font-bold">
                    {{ __('health.selected_package') }}: {{ $pickedPlanName }}
                </p>
            @endif

            <form method="POST" action="{{ url('/health/register') }}" class="space-y-5">
                @csrf
                @if($pickedPlanName)
                    <input type="hidden" name="requested_plan" value="{{ request('plan') }}">
                @endif

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-600 mb-2">{{ __('health.org_type') }}</label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        @foreach($orgTypes as $type)
                            <label class="cursor-pointer">
                                <input type="radio" name="org_type" value="{{ $type }}" class="peer sr-only"
                                       @checked(old('org_type', HealthPanel::DEFAULT_ORG_TYPE) === $type)>
                                <span class="block text-center px-3 py-2.5 rounded-xl border-2 border-gray-200 text-sm font-bold text-gray-600
                                             peer-checked:border-teal-600 peer-checked:bg-teal-50 peer-checked:text-teal-800 transition">
                                    {{ __(HealthPanel::orgTypeLabelKey($type)) }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label for="company_name" class="block text-xs font-bold uppercase tracking-wide text-gray-600 mb-1.5">{{ __('health.org_name') }}</label>
                        <input id="company_name" name="company_name" type="text" value="{{ old('company_name') }}" required
                               class="w-full rounded-xl border-gray-300 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label for="company_ntn" class="block text-xs font-bold uppercase tracking-wide text-gray-600 mb-1.5">{{ __('health.org_ntn') }}</label>
                        <input id="company_ntn" name="company_ntn" type="text" value="{{ old('company_ntn') }}" required
                               class="w-full rounded-xl border-gray-300 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="company_cnic" class="block text-xs font-bold uppercase tracking-wide text-gray-600 mb-1.5">{{ __('health.owner_cnic') }}</label>
                        <input id="company_cnic" name="company_cnic" type="text" value="{{ old('company_cnic') }}" placeholder="00000-0000000-0"
                               class="w-full rounded-xl border-gray-300 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                </div>

                <hr class="border-gray-200">

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label for="name" class="block text-xs font-bold uppercase tracking-wide text-gray-600 mb-1.5">{{ __('health.your_name') }}</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" required
                               class="w-full rounded-xl border-gray-300 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label for="phone" class="block text-xs font-bold uppercase tracking-wide text-gray-600 mb-1.5">{{ __('health.phone') }}</label>
                        <input id="phone" name="phone" type="text" value="{{ old('phone') }}"
                               class="w-full rounded-xl border-gray-300 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="email" class="block text-xs font-bold uppercase tracking-wide text-gray-600 mb-1.5">{{ __('health.email') }}</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username"
                               class="w-full rounded-xl border-gray-300 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label for="password" class="block text-xs font-bold uppercase tracking-wide text-gray-600 mb-1.5">{{ __('health.password') }}</label>
                        <input id="password" name="password" type="password" required autocomplete="new-password"
                               class="w-full rounded-xl border-gray-300 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label for="password_confirmation" class="block text-xs font-bold uppercase tracking-wide text-gray-600 mb-1.5">{{ __('health.confirm_password') }}</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                               class="w-full rounded-xl border-gray-300 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                </div>

                <button type="submit" class="health-cta w-full py-3 rounded-xl text-white text-sm font-black tracking-wide shadow-lg transition">
                    {{ __('health.register') }}
                </button>
            </form>

            <p class="mt-5 text-center text-sm text-gray-500">
                {{ __('health.already_registered') }}
                <a href="{{ url('/health/login') }}" class="font-bold text-teal-700 hover:text-teal-900">{{ __('health.login') }}</a>
            </p>
        </div>

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
