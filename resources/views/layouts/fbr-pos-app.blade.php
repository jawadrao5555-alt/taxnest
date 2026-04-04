<!DOCTYPE html>
@php
    $isDarkMode = Auth::guard('fbrpos')->check() && Auth::guard('fbrpos')->user()->dark_mode;
    $fbrUser = Auth::guard('fbrpos')->user();
    $fbrCompany = \App\Models\Company::find($fbrUser->company_id ?? null);
    $companyName = $fbrCompany->name ?? 'My Business';
    $userName = $fbrUser->name ?? 'User';
    $userInitial = strtoupper(substr($userName, 0, 1));
    $dashboardStyle = $fbrCompany->pos_dashboard_style ?? 'square-classic';
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $isDarkMode ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
        <meta name="theme-color" content="#1e3a5f">
        <title>FBR POS — {{ config('app.name', 'TaxNest') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800,900&display=swap" rel="stylesheet" />
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script>
            setTimeout(function(){
                if(!window.__alpineStarted){
                    window.__alpineStarted=true;
                    var c=document.createElement('script');
                    c.src='https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.14.8/dist/cdn.min.js';
                    document.head.appendChild(c);
                    c.onload=function(){
                        var s=document.createElement('script');
                        s.src='https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js';
                        document.head.appendChild(s);
                    };
                }
            }, 2000);
        </script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>if(document.documentElement.classList.contains('dark')){document.documentElement.style.colorScheme='dark';}</script>
        <style>
            *, *::before, *::after { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
            html, body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; text-rendering: optimizeLegibility; font-feature-settings: 'cv11', 'ss01'; font-variation-settings: 'opsz' 32; }
            body { letter-spacing: -0.011em; }
            h1, h2, h3, h4, h5, h6, .font-bold, .font-extrabold, .font-semibold { text-rendering: geometricPrecision; }
            .dark body { color: #f1f5f9; }
            .dark .text-gray-400 { color: #cbd5e1 !important; }
            .dark .text-gray-500 { color: #94a3b8 !important; }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
            @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
            @keyframes slideDown { from { opacity: 0; transform: translateY(-8px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
            .page-fade { animation: fadeIn 0.15s ease-out; }
            .btn-loading { position: relative; pointer-events: none; opacity: 0.7; }
            .btn-loading::after { content: ''; position: absolute; right: 8px; top: 50%; width: 14px; height: 14px; margin-top: -7px; border: 2px solid transparent; border-top-color: currentColor; border-radius: 50%; animation: spin 0.6s linear infinite; }
            .main-scroll::-webkit-scrollbar { width: 6px; }
            .main-scroll::-webkit-scrollbar-thumb { background: rgba(156,163,175,0.3); border-radius: 4px; }
            .main-scroll::-webkit-scrollbar-track { background: transparent; }
            .topnav-bar { background: linear-gradient(135deg, #0c1929 0%, #1e3a5f 40%, #1d4ed8 100%); }
            .nav-pill { transition: all 0.15s ease; }
            .nav-pill:hover { background: rgba(255,255,255,0.12); }
            .nav-pill.active { background: rgba(255,255,255,0.18); box-shadow: 0 0 0 1px rgba(255,255,255,0.1); }
            .profile-dropdown { animation: slideDown 0.15s ease-out; }
            .menu-link { transition: all 0.1s ease; }
            .menu-link:hover { background: rgba(37,99,235,0.08); }
            .dark .menu-link:hover { background: rgba(59,130,246,0.15); }
            [x-cloak] { display: none !important; }
        </style>
    </head>
    <body class="h-screen overflow-hidden antialiased">
        <div class="flex flex-col h-full" x-data="{ profileOpen: false, mobileMenuOpen: false }" @keydown.escape.window="profileOpen = false; mobileMenuOpen = false">

            <header class="topnav-bar flex-shrink-0 relative z-50">
                <div class="flex items-center justify-between px-3 sm:px-5 h-12">

                    <div class="flex items-center gap-3">
                        <a href="{{ route('fbrpos.dashboard') }}" class="flex items-center gap-2 group">
                            <div class="w-7 h-7 rounded-lg bg-white/15 flex items-center justify-center group-hover:bg-white/25 transition">
                                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                            </div>
                            <div class="hidden sm:block">
                                <span class="text-sm font-extrabold text-white tracking-tight">FBR POS</span>
                                <span class="text-[9px] text-white/70 ml-1 hidden lg:inline">by TaxNest</span>
                            </div>
                        </a>

                        <div class="h-5 w-px bg-white/10 hidden md:block"></div>

                        <nav class="hidden md:flex items-center gap-1">
                            <a href="{{ route('fbrpos.create') }}"
                               class="nav-pill px-3 py-1.5 rounded-lg text-xs font-semibold {{ request()->routeIs('fbrpos.create') ? 'active text-white' : 'text-white/90' }}">
                                New Sale
                            </a>
                        </nav>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="hidden lg:inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-white/10 text-blue-200 border border-white/10">
                            FBR POS
                        </span>

                        <div class="relative">
                            <button @click="profileOpen = !profileOpen" class="flex items-center gap-2 px-2 py-1 rounded-lg hover:bg-white/10 transition">
                                <div class="w-7 h-7 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-xs font-bold shadow-md">
                                    {{ $userInitial }}
                                </div>
                                <div class="hidden sm:block text-left">
                                    <p class="text-xs font-semibold text-white leading-tight truncate max-w-[100px]">{{ $userName }}</p>
                                    <p class="text-[9px] text-blue-200/90 leading-tight">{{ $companyName }}</p>
                                </div>
                                <svg class="w-3 h-3 text-white/70 hidden sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>

                            <div x-show="profileOpen" @click.away="profileOpen = false" x-cloak
                                 x-transition:enter="transition ease-out duration-150"
                                 x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
                                 x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                 x-transition:leave="transition ease-in duration-100"
                                 x-transition:leave-start="opacity-100"
                                 x-transition:leave-end="opacity-0 scale-95"
                                 class="profile-dropdown absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden z-50">

                                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
                                    <p class="text-sm font-bold text-gray-900 dark:text-white">{{ $userName }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $companyName }}</p>
                                </div>

                                <div class="py-1">
                                    <p class="px-4 pt-2 pb-1 text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">Navigation</p>
                                    <a href="{{ route('fbrpos.dashboard') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                                        Dashboard
                                    </a>
                                    <a href="{{ route('fbrpos.transactions') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                        Orders
                                    </a>
                                    <a href="{{ route('fbrpos.products') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                        Products
                                    </a>
                                    <a href="{{ route('fbrpos.reports') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                        Reports
                                    </a>
                                    <a href="{{ route('fbrpos.tax-reports') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                                        Tax Reports
                                    </a>
                                    <a href="{{ route('fbrpos.day-close') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        Day Close (Z-Report)
                                    </a>
                                </div>

                                <div class="border-t border-gray-100 dark:border-gray-700 py-1">
                                    <p class="px-4 pt-2 pb-1 text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">Settings</p>
                                    <a href="{{ route('fbrpos.business-profile') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                        Business Profile
                                    </a>
                                    <a href="{{ route('fbrpos.settings') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        FBR Settings
                                    </a>
                                    <a href="{{ route('fbrpos.billing') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                        Billing
                                    </a>
                                    <a href="{{ route('fbrpos.my-profile') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        My Profile
                                    </a>
                                </div>

                                <div class="border-t border-gray-100 dark:border-gray-700 py-1">
                                    <form method="POST" action="{{ route('fbrpos.logout') }}">
                                        @csrf
                                        <button type="submit" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-red-600 dark:text-red-400 w-full">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                            Sign Out
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-1.5 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition">
                            <svg x-show="!mobileMenuOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            <svg x-show="mobileMenuOpen" x-cloak class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>

                <div x-show="mobileMenuOpen" x-cloak
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0 -translate-y-2"
                     class="md:hidden border-t border-white/10 px-3 py-2 flex flex-wrap gap-1.5" style="background: rgba(12,25,41,0.9)">
                    <a href="{{ route('fbrpos.create') }}" class="nav-pill px-3 py-1.5 rounded-lg text-[11px] font-medium {{ request()->routeIs('fbrpos.create') ? 'active text-white' : 'text-white/90' }}">New Sale</a>
                </div>
            </header>

            <main class="flex-1 overflow-y-auto overflow-x-hidden main-scroll bg-slate-50 dark:bg-gray-950 page-fade" style="min-width: 0;">
                @if(session('success'))
                    <div class="max-w-7xl mx-auto mb-4 px-4 sm:px-6 pt-4">
                        <div class="bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-300 px-4 py-3 rounded-lg">
                            {{ session('success') }}
                        </div>
                    </div>
                @endif
                @if(session('error'))
                    <div class="max-w-7xl mx-auto mb-4 px-4 sm:px-6 pt-4">
                        <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
                            {{ session('error') }}
                        </div>
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>

        @php
            $toastMessages = [];
            if(session('success')) $toastMessages[] = ['msg' => session('success'), 'type' => 'success'];
            if(session('error')) $toastMessages[] = ['msg' => session('error'), 'type' => 'error'];
        @endphp
        <div x-data="{ toasts: [], init() { const msgs = JSON.parse(this.$el.dataset.messages || '[]'); msgs.forEach(m => this.addToast(m.msg, m.type)); }, addToast(msg, type) { let id = Date.now() + Math.random(); this.toasts.push({id, msg, type}); setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 5000); } }" data-messages="{{ json_encode($toastMessages) }}" class="fixed top-4 right-4 z-50 space-y-2" style="pointer-events: none;">
            <template x-for="toast in toasts" :key="toast.id">
                <div x-transition class="px-4 py-3 rounded-xl shadow-lg border text-sm font-medium max-w-sm" style="pointer-events: auto;"
                    :class="toast.type === 'success' ? 'bg-blue-50 border-blue-200 text-blue-800' : 'bg-red-50 border-red-200 text-red-800'">
                    <span x-text="toast.msg"></span>
                </div>
            </template>
        </div>
    </body>
</html>
