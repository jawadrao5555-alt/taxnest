@php
    use App\Services\HealthModuleService;
    use App\Support\HealthPanel;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TaxNest Healthcare ERP — Clinics, Hospitals, Labs &amp; Pharmacies</title>
    <meta name="description" content="TaxNest Healthcare ERP: one panel for clinics, hospitals, laboratories and pharmacies. Switch on only the modules your organisation runs.">
    @include('partials.font-css', ['fontFamilies' => 'figtree:400,500,600,700,800'])
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white text-[#0B1B20] antialiased font-sans">

{{-- ══════════════ Header ══════════════ --}}
<header class="sticky top-0 z-40 bg-white/95 backdrop-blur border-b border-gray-200">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
        <a href="/" class="flex items-center gap-2.5">
            <img src="{{ asset('images/brand/taxnest-logo.svg') }}" alt="TaxNest" class="h-7 w-auto"
                 onerror="this.style.display='none'">
            <span class="text-sm font-extrabold tracking-tight text-[#0A4D5C]">Healthcare ERP</span>
        </a>
        <div class="flex items-center gap-2">
            <a href="{{ url('/health/login') }}" class="px-4 py-2 text-sm font-semibold text-[#0A4D5C] hover:bg-[#0A4D5C]/5 rounded-lg transition">Log In</a>
            @if(\App\Support\HealthPanel::registrationOpen())
                <a href="{{ url('/health/register') }}" class="px-4 py-2 text-sm font-bold text-white bg-[#0A4D5C] hover:bg-[#083c48] rounded-lg transition">Get Started</a>
            @endif
        </div>
    </div>
</header>

{{-- ══════════════ Hero ══════════════ --}}
<section class="bg-[#052730] text-white">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 py-20 lg:py-28">
        <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-[#E7BF3B]">TaxNest Healthcare</p>
        <h1 class="mt-4 text-3xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight leading-[1.15] max-w-3xl">
            One system for the clinic, the hospital, the lab and the pharmacy.
        </h1>
        <p class="mt-5 text-base sm:text-lg text-white/70 leading-relaxed max-w-2xl">
            Run your organisation on the modules you actually use — outpatients, pharmacy, inpatients,
            laboratory, accounts and HR — with branch and department boundaries enforced everywhere,
            on the same platform that already files Pakistani tax for thousands of businesses.
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
            @if(\App\Support\HealthPanel::registrationOpen())
                <a href="{{ url('/health/register') }}" class="px-6 py-3 rounded-xl bg-[#0A4D5C] hover:bg-[#0d5d70] text-white text-sm font-bold transition">Start your organisation</a>
            @endif
            <a href="{{ url('/health/login') }}" class="px-6 py-3 rounded-xl border border-white/25 hover:bg-white/10 text-white text-sm font-bold transition">Log in</a>
        </div>
    </div>
</section>

{{-- ══════════════ Organisation types ══════════════ --}}
<section class="py-16 lg:py-20">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
        <h2 class="text-2xl font-extrabold tracking-tight">Built for four kinds of organisation</h2>
        <p class="mt-2 text-sm text-gray-600 max-w-2xl">Pick your type at sign-up and the right modules are switched on for you. You can change them any time.</p>
        <div class="mt-8 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach(HealthPanel::ORG_TYPES as $type)
                <div class="rounded-2xl border border-gray-200 p-5">
                    <p class="text-sm font-extrabold text-[#0A4D5C]">{{ ucfirst($type) }}</p>
                    <p class="mt-1.5 text-xs text-gray-600 leading-relaxed">
                        Starts with {{ implode(', ', array_map(
                            fn ($m) => ucfirst($m),
                            HealthModuleService::ORG_TYPE_DEFAULTS[$type] ?? []
                        )) }}.
                    </p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══════════════ Modules ══════════════ --}}
<section class="py-16 lg:py-20 bg-gray-50 border-y border-gray-200">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
        <h2 class="text-2xl font-extrabold tracking-tight">Only the modules you run</h2>
        <p class="mt-2 text-sm text-gray-600 max-w-2xl">A module that is switched off does not appear anywhere in the panel — not in the menu, not as an upsell.</p>
        <div class="mt-8 grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($modules as $module)
                <div class="rounded-2xl bg-white border border-gray-200 p-5">
                    <div class="flex items-center gap-2">
                        <span class="text-lg leading-none">{{ $moduleMeta[$module]['icon'] ?? '•' }}</span>
                        <p class="text-sm font-extrabold">{{ __(HealthModuleService::moduleLabelKey($module), [], 'en') }}</p>
                    </div>
                    <p class="mt-1.5 text-xs text-gray-600 leading-relaxed">{{ __(HealthModuleService::moduleDescriptionKey($module), [], 'en') }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══════════════ Packages ══════════════ --}}
<section class="py-16 lg:py-20">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
        <h2 class="text-2xl font-extrabold tracking-tight">Packages</h2>
        @if($plans->isEmpty())
            <p class="mt-4 text-sm text-gray-600">
                Packages are being finalised. <a href="{{ url('/contact') }}" class="font-bold text-[#0A4D5C] underline">Contact us</a> and we will set your organisation up.
            </p>
        @else
            <div class="mt-8 grid sm:grid-cols-2 gap-5 max-w-3xl">
                @foreach($plans as $plan)
                    @php $planModules = HealthModuleService::normalize($plan->health_modules ?? []); @endphp
                    <div class="rounded-2xl border-2 border-gray-200 p-6 flex flex-col">
                        <p class="text-sm font-extrabold text-[#0A4D5C]">{{ $plan->name }}</p>
                        <p class="mt-3">
                            <span class="text-3xl font-extrabold tracking-tight">Rs {{ number_format($plan->sale_price ?? $plan->price) }}</span>
                            <span class="text-xs font-semibold text-gray-500">/ year</span>
                        </p>
                        @if($plan->description)
                            <p class="mt-2 text-xs text-gray-600 leading-relaxed">{{ $plan->description }}</p>
                        @endif
                        <ul class="mt-4 space-y-1.5 text-xs text-gray-700 flex-1">
                            @foreach($planModules as $module)
                                <li class="flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#E7BF3B] flex-shrink-0"></span>
                                    {{ __(HealthModuleService::moduleLabelKey($module), [], 'en') }}
                                </li>
                            @endforeach
                            <li class="flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-[#E7BF3B] flex-shrink-0"></span>
                                @if(($plan->health_department_limit ?? -1) >= 0)
                                    Up to {{ $plan->health_department_limit }} departments
                                @else
                                    Unlimited departments
                                @endif
                            </li>
                        </ul>
                        @if(\App\Support\HealthPanel::registrationOpen())
                            <a href="{{ url('/health/register?plan=' . $plan->id) }}"
                               class="mt-5 block text-center px-5 py-2.5 rounded-xl bg-[#0A4D5C] hover:bg-[#083c48] text-white text-sm font-bold transition">
                                Choose {{ $plan->name }}
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>

<x-site-footer loginUrl="/health/login" loginLabel="Healthcare Log In" />
</body>
</html>
