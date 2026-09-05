@php
    use App\Services\HealthAccessService;
    use App\Services\HealthModuleService;
    use App\Support\HealthPanel;
    use App\Support\PosLocale;

    /** @var \App\Models\Company|null $healthCompany */
    $hCompany      = $healthCompany ?? null;
    $hUser         = $healthUser ?? auth()->guard(HealthPanel::GUARD)->user();
    $hModules      = $healthModules ?? [];
    $hCaps         = $healthCapabilities ?? [];
    $hIsOwner      = HealthAccessService::isOwner($hUser);
    $hDark         = (bool) ($hUser->dark_mode ?? false);
    $hLocale       = PosLocale::normalize($hUser->language ?? session(PosLocale::SESSION_KEY));

    /*
     * NAVIGATION — declared once, filtered three ways.
     *
     * An entry is rendered only when ALL of these hold:
     *   1. the user holds its capability,
     *   2. its module (if any) is switched on for this organisation, and
     *   3. its route actually exists.
     *
     * (3) is what keeps this honest today: the foundation ships the shell, and
     * the clinical / pharmacy / IPD / lab / accounts / HR modules register their
     * own routes as they land. Until a module's routes exist there is nothing to
     * click, so the panel does not advertise it — navigation lists only features
     * that are usable, never features we would like to sell. When a module task
     * registers its routes the link appears here on its own, with no edit to
     * this layout.
     */
    $hNav = [
        ['key' => 'dashboard',    'route' => 'health.dashboard',    'cap' => 'dashboard.view',      'module' => null,       'label' => 'health.nav_dashboard'],
        ['key' => 'patients',     'route' => 'health.patients',     'cap' => 'patients.view',       'module' => null,       'label' => 'health.nav_patients'],
        ['key' => 'appointments', 'route' => 'health.appointments', 'cap' => 'appointments.view',   'module' => 'opd',      'label' => 'health.nav_appointments'],
        ['key' => 'clinical',     'route' => 'health.clinical',     'cap' => 'clinical.view',       'module' => 'opd',      'label' => 'health.nav_clinical'],
        ['key' => 'doctors',      'route' => 'health.doctors',      'cap' => 'doctors.manage',      'module' => 'opd',      'label' => 'health.nav_doctors'],
        ['key' => 'ipd',          'route' => 'health.ipd',          'cap' => 'ipd.view',            'module' => 'ipd',      'label' => 'health.nav_ipd'],
        ['key' => 'pharmacy',     'route' => 'health.pharmacy',     'cap' => 'pharmacy.view',       'module' => 'pharmacy', 'label' => 'health.nav_pharmacy'],
        ['key' => 'lab',          'route' => 'health.lab',          'cap' => 'lab.view',            'module' => 'lab',      'label' => 'health.nav_lab'],
        ['key' => 'billing',      'route' => 'health.billing',      'cap' => 'billing.view',        'module' => 'accounts', 'label' => 'health.nav_billing'],
        ['key' => 'accounts',     'route' => 'health.accounts',     'cap' => 'accounts.view',       'module' => 'accounts', 'label' => 'health.nav_accounts'],
        ['key' => 'hr',           'route' => 'health.hr',           'cap' => 'hr.view',             'module' => 'hr',       'label' => 'health.nav_hr'],
        ['key' => 'reports',      'route' => 'health.reports',      'cap' => 'reports.view',        'module' => null,       'label' => 'health.nav_reports'],
        ['key' => 'audit',        'route' => 'health.audit',        'cap' => 'audit.view',          'module' => null,       'label' => 'health.nav_audit'],
        ['key' => 'departments',  'route' => 'health.departments',  'cap' => 'departments.manage',  'module' => null,       'label' => 'health.nav_departments'],
        ['key' => 'team',         'route' => 'health.team',         'cap' => 'staff.manage',        'module' => null,       'label' => 'health.nav_team'],
        ['key' => 'settings',     'route' => 'health.settings',     'cap' => 'settings.manage',     'module' => null,       'label' => 'health.nav_settings'],
    ];

    $hNav = array_values(array_filter($hNav, function (array $item) use ($hCaps, $hModules) {
        if (!in_array($item['cap'], $hCaps, true)) {
            return false;
        }
        if ($item['module'] !== null && !in_array($item['module'], $hModules, true)) {
            return false;
        }

        return \Illuminate\Support\Facades\Route::has($item['route']);
    }));

    // Simple line icons, keyed by nav entry — kept here so a new module only has
    // to add its key, not hunt for an icon convention.
    $hIcons = [
        'dashboard'    => 'M4 5a1 1 0 011-1h5v7H4V5zm0 9h6v6H5a1 1 0 01-1-1v-5zm10-10h5a1 1 0 011 1v4h-6V4zm0 7h6v8a1 1 0 01-1 1h-5v-9z',
        'patients'     => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
        'appointments' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
        'clinical'     => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        'doctors'      => 'M4.5 21v-2a4 4 0 014-4h1m6 6v-2a4 4 0 00-4-4h-1m0 0V9m0 0a4 4 0 100-8 4 4 0 000 8zm7 7a2 2 0 11-4 0 2 2 0 014 0zm-2 2v3',
        'ipd'          => 'M3 10h18M3 10V6a2 2 0 012-2h14a2 2 0 012 2v4m-18 0v8a2 2 0 002 2h14a2 2 0 002-2v-8M7 14h.01',
        'pharmacy'     => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
        'lab'          => 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z',
        'billing'      => 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z',
        'accounts'     => 'M9 7h6m-6 4h6m-6 4h4M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z',
        'hr'           => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
        'reports'      => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        'audit'        => 'M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z',
        'departments'  => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
        'team'         => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
        'settings'     => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $hDark ? 'dark' : '' }}" @if(app()->getLocale() === 'ur') dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('health.panel_name') }} — {{ $hCompany->name ?? __('health.panel_name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/mobile.css?v=2.6') }}">
    @include('partials.font-css', ['fontFamilies' => 'figtree:400,500,600,700'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Urdu-script UI font: own <html> head, so it needs its own include. --}}
    @include('partials.urdu-font')
    <style>
        [x-cloak] { display: none !important; }
        /* Panel palette kept as plain CSS, not arbitrary Tailwind values: a live
           deploy that has not rebuilt the bundle must still render the shell in
           the right colours rather than unstyled. */
        .health-bar { background: linear-gradient(135deg, #0f766e 0%, #0d9488 55%, #0891b2 100%); }
        .health-tile { background: linear-gradient(135deg, #0d9488, #0891b2); }
        .health-link-active { background: rgba(13, 148, 136, 0.12); color: #0f766e; }
        .dark .health-link-active { background: rgba(13, 148, 136, 0.22); color: #5eead4; }
    </style>
</head>
<body class="min-h-screen bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 antialiased font-sans">
<div x-data="healthShell({{ $hDark ? 'true' : 'false' }})" @keydown.escape.window="drawer = false; profile = false">

    {{-- ══════════════ Top bar ══════════════ --}}
    <header class="health-bar sticky top-0 z-40 shadow-lg">
        <div class="flex items-center justify-between gap-2 px-3 sm:px-5 h-14">

            <div class="flex items-center gap-2 min-w-0">
                <button type="button" @click="drawer = !drawer"
                        class="p-2 rounded-lg text-white hover:bg-white/15 transition lg:hidden"
                        aria-label="{{ __('health.menu') }}">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>

                {{-- Admin "view as / manage as" chip lives INSIDE the bar, and is
                     the only way back out of impersonation. --}}
                @include('partials.impersonation-banner')

                <a href="{{ route('health.dashboard') }}" class="flex items-center gap-2.5 min-w-0">
                    <span class="w-9 h-9 rounded-xl bg-white/15 ring-1 ring-white/25 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-black text-white leading-tight truncate">{{ __('health.panel_name') }}</span>
                        <span class="block text-[11px] text-white/75 leading-tight truncate">{{ $hCompany->name ?? '' }}</span>
                    </span>
                </a>
            </div>

            <div class="flex items-center gap-1.5 sm:gap-2">
                <div class="hidden sm:block">
                    <x-branch-switcher color="emerald" :showManage="false" :allowAll="true" />
                </div>

                {{-- Language: three locales, same set as every other panel. --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" @click="open = !open"
                            class="inline-flex items-center gap-1 px-2.5 h-8 rounded-lg text-white bg-white/10 hover:bg-white/20 ring-1 ring-white/20 text-[11px] font-bold uppercase transition"
                            title="{{ __('health.language') }}">
                        {{ $hLocale === 'ur' ? 'اردو' : ($hLocale === 'rur' ? 'RUR' : 'EN') }}
                    </button>
                    <div x-show="open" x-cloak
                         class="absolute end-0 mt-2 w-40 rounded-xl bg-white dark:bg-gray-800 shadow-2xl ring-1 ring-black/10 overflow-hidden z-50">
                        @foreach(['en' => 'English', 'rur' => 'Roman Urdu', 'ur' => 'اردو'] as $code => $label)
                            <form method="POST" action="{{ route('health.set-language') }}">
                                @csrf
                                <input type="hidden" name="language" value="{{ $code }}">
                                <button type="submit"
                                        class="w-full text-start px-4 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700 {{ $hLocale === $code ? 'font-black text-teal-700 dark:text-teal-300' : '' }}">
                                    {{ $label }}
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>

                {{-- Dark mode. The DOM only changes after the save contract is
                     honoured — a failed POST rolls the switch back rather than
                     showing a preference that was never stored. --}}
                <button type="button" @click="toggleDark()" :disabled="darkSaving"
                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-white bg-white/10 hover:bg-white/20 ring-1 ring-white/20 transition disabled:opacity-50"
                        :title="dark ? '{{ __('health.dark_mode') }}' : '{{ __('health.dark_mode') }}'">
                    <svg x-show="!dark" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36-6.36-.71.71M6.35 17.65l-.71.71m12.72 0-.71-.71M6.35 6.35l-.71-.71M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <svg x-show="dark" x-cloak class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.35 15.35A9 9 0 018.65 3.65 9 9 0 1012 21a9 9 0 008.35-5.65z"/></svg>
                </button>

                {{-- Profile --}}
                <div class="relative" @click.outside="profile = false">
                    <button type="button" @click="profile = !profile"
                            class="flex items-center gap-2 h-8 ps-1 pe-2 rounded-lg text-white bg-white/10 hover:bg-white/20 ring-1 ring-white/20 transition">
                        <span class="w-6 h-6 rounded-md bg-white/20 flex items-center justify-center text-[11px] font-black">
                            {{ mb_strtoupper(mb_substr($hUser->name ?? '?', 0, 1)) }}
                        </span>
                        <span class="hidden md:inline text-[11px] font-bold truncate max-w-[120px]">{{ $hUser->name ?? '' }}</span>
                    </button>
                    <div x-show="profile" x-cloak
                         class="absolute end-0 mt-2 w-60 rounded-xl bg-white dark:bg-gray-800 shadow-2xl ring-1 ring-black/10 overflow-hidden z-50">
                        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                            <p class="text-sm font-bold truncate">{{ $hUser->name ?? '' }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 truncate">{{ $hUser->email ?? '' }}</p>
                            <span class="inline-block mt-1.5 px-2 py-0.5 rounded-full bg-teal-100 dark:bg-teal-900/40 text-teal-800 dark:text-teal-200 text-[10px] font-black uppercase tracking-wide">
                                {{ __(HealthAccessService::roleLabelKey($healthRole ?? null)) }}
                            </span>
                        </div>
                        <div class="sm:hidden px-3 py-2 border-b border-gray-200 dark:border-gray-700">
                            <x-branch-switcher color="emerald" :showManage="false" :allowAll="true" />
                        </div>
                        <form method="POST" action="{{ route('health.logout') }}">
                            @csrf
                            <button type="submit" class="w-full text-start px-4 py-2.5 text-sm font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">
                                {{ __('health.logout') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="flex">
        {{-- ══════════════ Sidebar ══════════════ --}}
        <div x-show="drawer" x-cloak @click="drawer = false"
             class="fixed inset-0 bg-black/50 z-30 lg:hidden"></div>

        <aside class="fixed lg:sticky top-14 z-30 h-[calc(100vh-3.5rem)] w-64 flex-shrink-0 overflow-y-auto
                      bg-white dark:bg-gray-800 border-e border-gray-200 dark:border-gray-700
                      transition-transform lg:translate-x-0"
               :class="drawer ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full lg:translate-x-0'">
            <nav class="p-3 space-y-0.5">
                @foreach($hNav as $item)
                    @php($active = request()->routeIs($item['route']))
                    <a href="{{ route($item['route']) }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold transition
                              {{ $active ? 'health-link-active' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/60' }}">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $hIcons[$item['key']] ?? $hIcons['dashboard'] }}"/>
                        </svg>
                        <span class="truncate">{{ __($item['label']) }}</span>
                    </a>
                @endforeach
            </nav>

            @if($hIsOwner)
                <div class="px-3 pb-4">
                    <a href="{{ route('health.settings.modules') }}"
                       class="flex items-center gap-2 px-3 py-2.5 rounded-xl text-xs font-bold text-teal-700 dark:text-teal-300 bg-teal-50 dark:bg-teal-900/20 hover:bg-teal-100 dark:hover:bg-teal-900/40 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/></svg>
                        {{ __('health.nav_modules') }}
                        <span class="ms-auto text-[10px] font-black px-1.5 py-0.5 rounded-full bg-teal-600 text-white">{{ count($hModules) }}</span>
                    </a>
                </div>
            @endif
        </aside>

        {{-- ══════════════ Page ══════════════ --}}
        <main class="flex-1 min-w-0">
            {{-- Flash + validation are rendered centrally, exactly once. A page
                 that renders its own would double every message. --}}
            @if(session('success') || session('error') || $errors->any())
                <div class="max-w-6xl mx-auto px-4 sm:px-6 pt-4 space-y-2">
                    @if(session('success'))
                        <div class="rounded-xl border border-emerald-300 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 px-4 py-3 text-sm font-semibold text-emerald-800 dark:text-emerald-200">
                            {{ session('success') }}
                        </div>
                    @endif
                    @if(session('error'))
                        <div class="rounded-xl border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/20 px-4 py-3 text-sm font-semibold text-red-800 dark:text-red-200">
                            {{ session('error') }}
                        </div>
                    @endif
                    @if($errors->any())
                        <div class="rounded-xl border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/20 px-4 py-3 text-sm text-red-800 dark:text-red-200">
                            <ul class="list-disc list-inside space-y-0.5">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>
</div>

<script>
    function healthShell(initialDark) {
        return {
            drawer: false,
            profile: false,
            dark: initialDark,
            darkSaving: false,
            async toggleDark() {
                if (this.darkSaving) return;
                const next = !this.dark;
                this.darkSaving = true;
                try {
                    // route(..., [], false) — a forced-https absolute URL never
                    // arrives on the plain-http dev bridge.
                    const r = await fetch('{{ route('health.set-dark-mode', [], false) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ dark_mode: next }),
                    });
                    if (!r.ok) throw new Error('save failed');
                    const data = await r.json();
                    if (!data || data.ok !== true) throw new Error('bad contract');
                    // Only now is the preference real.
                    this.dark = !!data.dark_mode;
                    document.documentElement.classList.toggle('dark', this.dark);
                } catch (e) {
                    // Rolled back on purpose: a switch that shows "saved" after a
                    // 403/419/500 is a switch that lies.
                    this.dark = initialDark;
                    document.documentElement.classList.toggle('dark', this.dark);
                } finally {
                    this.darkSaving = false;
                }
            },
        };
    }
</script>
</body>
</html>
