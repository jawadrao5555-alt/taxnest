<!DOCTYPE html>
@php
    $isDarkMode = auth('pos')->check() && auth('pos')->user()->dark_mode;
    $posUserLayout = auth('pos')->user();
    $isCashierLayout = $posUserLayout && $posUserLayout->isPosCashier();
    $companyLayout = \App\Models\Company::find(app('currentCompanyId'));
    $isRestaurantLayout = $companyLayout && ($companyLayout->pos_type === 'restaurant' || $companyLayout->restaurant_mode);
    $praEnabledLayout = $companyLayout && $companyLayout->pra_reporting_enabled;
    $inventoryEnabledLayout = $companyLayout && $companyLayout->inventory_enabled;
    $companyName = $companyLayout->name ?? 'My Business';
    $userName = $posUserLayout->name ?? 'User';
    $userInitial = strtoupper(substr($userName, 0, 1));
    $userRole = $isCashierLayout ? 'Cashier' : 'Admin';
    $posTheme = $companyLayout->pos_theme ?? 'purple';
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $isDarkMode ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
        <meta name="theme-color" content="#1e1b4b">
        <title>NestPOS — {{ config('app.name', 'TaxNest') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800,900&display=swap" rel="stylesheet" />
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
            @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
            @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
            @keyframes slideDown { from { opacity: 0; transform: translateY(-8px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
            .page-fade { animation: fadeIn 0.15s ease-out; }
            .btn-loading { position: relative; pointer-events: none; opacity: 0.7; }
            .btn-loading::after { content: ''; position: absolute; right: 8px; top: 50%; width: 14px; height: 14px; margin-top: -7px; border: 2px solid transparent; border-top-color: currentColor; border-radius: 50%; animation: spin 0.6s linear infinite; }
            .main-scroll::-webkit-scrollbar { width: 6px; }
            .main-scroll::-webkit-scrollbar-thumb { background: rgba(156,163,175,0.3); border-radius: 4px; }
            .main-scroll::-webkit-scrollbar-track { background: transparent; }

            [data-theme="purple"] { --nav-bg: linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #4c1d95 100%); --accent-h: 263; --accent-s: 70%; --accent-l: 50%; --avatar-from: #a78bfa; --avatar-to: #7c3aed; --accent-glow: rgba(124,58,237,0.2); --meta-color: rgba(196,181,253,0.5); --status-text: rgba(196,181,253,0.6); --pill-hover: rgba(255,255,255,0.12); --pill-active: rgba(255,255,255,0.18); }
            [data-theme="blue"] { --nav-bg: linear-gradient(135deg, #0c1929 0%, #1e3a5f 40%, #1d4ed8 100%); --accent-h: 217; --accent-s: 91%; --accent-l: 48%; --avatar-from: #60a5fa; --avatar-to: #2563eb; --accent-glow: rgba(37,99,235,0.2); --meta-color: rgba(147,197,253,0.5); --status-text: rgba(147,197,253,0.6); --pill-hover: rgba(255,255,255,0.12); --pill-active: rgba(255,255,255,0.18); }
            [data-theme="emerald"] { --nav-bg: linear-gradient(135deg, #022c22 0%, #064e3b 40%, #047857 100%); --accent-h: 160; --accent-s: 84%; --accent-l: 39%; --avatar-from: #34d399; --avatar-to: #059669; --accent-glow: rgba(5,150,105,0.2); --meta-color: rgba(110,231,183,0.5); --status-text: rgba(110,231,183,0.6); --pill-hover: rgba(255,255,255,0.12); --pill-active: rgba(255,255,255,0.18); }
            [data-theme="orange"] { --nav-bg: linear-gradient(135deg, #431407 0%, #7c2d12 40%, #c2410c 100%); --accent-h: 21; --accent-s: 90%; --accent-l: 48%; --avatar-from: #fb923c; --avatar-to: #ea580c; --accent-glow: rgba(234,88,12,0.2); --meta-color: rgba(253,186,116,0.5); --status-text: rgba(253,186,116,0.6); --pill-hover: rgba(255,255,255,0.12); --pill-active: rgba(255,255,255,0.18); }
            [data-theme="midnight"] { --nav-bg: linear-gradient(135deg, #0a0a0a 0%, #171717 40%, #262626 100%); --accent-h: 0; --accent-s: 0%; --accent-l: 45%; --avatar-from: #a3a3a3; --avatar-to: #525252; --accent-glow: rgba(115,115,115,0.2); --meta-color: rgba(163,163,163,0.5); --status-text: rgba(163,163,163,0.6); --pill-hover: rgba(255,255,255,0.1); --pill-active: rgba(255,255,255,0.15); }
            [data-theme="rose"] { --nav-bg: linear-gradient(135deg, #4c0519 0%, #881337 40%, #be123c 100%); --accent-h: 347; --accent-s: 77%; --accent-l: 50%; --avatar-from: #fb7185; --avatar-to: #e11d48; --accent-glow: rgba(225,29,72,0.2); --meta-color: rgba(253,164,175,0.5); --status-text: rgba(253,164,175,0.6); --pill-hover: rgba(255,255,255,0.12); --pill-active: rgba(255,255,255,0.18); }

            .topnav-bar { background: var(--nav-bg, linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #4c1d95 100%)); }
            .nav-pill { transition: all 0.15s ease; }
            .nav-pill:hover { background: var(--pill-hover); }
            .nav-pill.active { background: var(--pill-active); box-shadow: 0 0 0 1px rgba(255,255,255,0.1); }
            .profile-dropdown { animation: slideDown 0.15s ease-out; }
            .menu-link { transition: all 0.1s ease; }
            .menu-link:hover { background: hsla(var(--accent-h), var(--accent-s), var(--accent-l), 0.08); }
            .dark .menu-link:hover { background: hsla(var(--accent-h), var(--accent-s), var(--accent-l), 0.15); }
            .avatar-themed { background: linear-gradient(135deg, var(--avatar-from), var(--avatar-to)); }
            .accent-glow { box-shadow: 0 4px 15px var(--accent-glow); }
            .theme-swatch { width: 28px; height: 28px; border-radius: 8px; cursor: pointer; border: 2px solid transparent; transition: all 0.15s ease; }
            .theme-swatch:hover { transform: scale(1.15); }
            .theme-swatch.active-theme { border-color: white; box-shadow: 0 0 0 2px rgba(255,255,255,0.3); }
            [x-cloak] { display: none !important; }
        </style>
    </head>
    <body class="h-screen overflow-hidden antialiased" data-theme="{{ $posTheme }}">
        <div class="flex flex-col h-full" x-data="{ profileOpen: false, mobileMenuOpen: false, themeOpen: false, currentTheme: '{{ $posTheme }}' }" @keydown.escape.window="profileOpen = false; mobileMenuOpen = false; themeOpen = false">

            <header class="topnav-bar flex-shrink-0 relative z-50">
                <div class="flex items-center justify-between px-3 sm:px-5 h-12">

                    <div class="flex items-center gap-3">
                        <a href="{{ $isRestaurantLayout ? route('pos.restaurant.dashboard') : route('pos.dashboard') }}" class="flex items-center gap-2 group">
                            <div class="w-7 h-7 rounded-lg bg-white/15 flex items-center justify-center group-hover:bg-white/25 transition">
                                <svg class="w-4 h-4 text-white/80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </div>
                            <div class="hidden sm:block">
                                <span class="text-sm font-extrabold text-white tracking-tight">NestPOS</span>
                                <span class="text-[9px] text-white/30 ml-1 hidden lg:inline">Enterprise</span>
                            </div>
                        </a>

                        <div class="h-5 w-px bg-white/10 hidden md:block"></div>

                        <nav class="hidden md:flex items-center gap-1">
                            @if($isRestaurantLayout)
                            <a href="{{ route('pos.restaurant.pos') }}"
                               class="nav-pill flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[11px] font-medium {{ request()->routeIs('pos.restaurant.pos') ? 'active text-white' : 'text-white/60' }}">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4"/></svg>
                                New Sale
                            </a>
                            @else
                            <a href="{{ route('pos.invoice.create') }}"
                               class="nav-pill flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[11px] font-medium {{ request()->routeIs('pos.invoice.create') ? 'active text-white' : 'text-white/60' }}">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4"/></svg>
                                New Sale
                            </a>
                            @endif
                        </nav>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="hidden lg:flex items-center gap-2 mr-2">
                            <span class="w-1.5 h-1.5 rounded-full {{ $praEnabledLayout ? 'bg-green-400' : 'bg-amber-400' }}" style="box-shadow: 0 0 6px {{ $praEnabledLayout ? 'rgba(16,185,129,0.5)' : 'rgba(245,158,11,0.5)' }}"></span>
                            <span class="text-[9px] {{ $praEnabledLayout ? 'text-green-300' : 'text-amber-300' }} font-medium">PRA {{ $praEnabledLayout ? 'Online' : 'Offline' }}</span>
                            <span class="text-[9px] font-mono ml-2" style="color: var(--meta-color)">{{ now()->format('H:i') }}</span>
                        </div>

                        <div class="relative">
                            <button @click="themeOpen = !themeOpen; profileOpen = false" class="p-2 rounded-lg text-white/60 hover:text-white hover:bg-white/10 transition" title="Change Theme">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                            </button>
                            <div x-show="themeOpen" x-cloak @click.outside="themeOpen = false" x-transition class="absolute right-0 top-full mt-2 bg-white dark:bg-gray-900 rounded-xl shadow-2xl shadow-black/20 border border-gray-200/80 dark:border-gray-700/80 p-3 z-[100] w-48">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2">POS Theme</p>
                                <div class="grid grid-cols-3 gap-2">
                                    <button @click="currentTheme='purple'; document.body.setAttribute('data-theme','purple'); fetch('/pos/settings/theme', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'purple'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='purple' && 'active-theme'" style="background:linear-gradient(135deg,#312e81,#7c3aed)" title="Royal Purple"></button>
                                    <button @click="currentTheme='blue'; document.body.setAttribute('data-theme','blue'); fetch('/pos/settings/theme', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'blue'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='blue' && 'active-theme'" style="background:linear-gradient(135deg,#1e3a5f,#2563eb)" title="Ocean Blue"></button>
                                    <button @click="currentTheme='emerald'; document.body.setAttribute('data-theme','emerald'); fetch('/pos/settings/theme', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'emerald'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='emerald' && 'active-theme'" style="background:linear-gradient(135deg,#064e3b,#059669)" title="Emerald Green"></button>
                                    <button @click="currentTheme='orange'; document.body.setAttribute('data-theme','orange'); fetch('/pos/settings/theme', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'orange'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='orange' && 'active-theme'" style="background:linear-gradient(135deg,#7c2d12,#ea580c)" title="Sunset Orange"></button>
                                    <button @click="currentTheme='midnight'; document.body.setAttribute('data-theme','midnight'); fetch('/pos/settings/theme', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'midnight'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='midnight' && 'active-theme'" style="background:linear-gradient(135deg,#171717,#404040)" title="Midnight Dark"></button>
                                    <button @click="currentTheme='rose'; document.body.setAttribute('data-theme','rose'); fetch('/pos/settings/theme', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'rose'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='rose' && 'active-theme'" style="background:linear-gradient(135deg,#881337,#e11d48)" title="Rose Pink"></button>
                                </div>
                                <div class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-800">
                                    <p class="text-[9px] text-gray-400 text-center" x-text="currentTheme.charAt(0).toUpperCase() + currentTheme.slice(1) + ' Theme'"></p>
                                </div>
                            </div>
                        </div>

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-2 rounded-lg text-white/60 hover:text-white hover:bg-white/10 transition">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </button>

                        <div class="relative">
                            <button @click="profileOpen = !profileOpen; themeOpen = false"
                                    class="flex items-center gap-2 px-2 py-1.5 rounded-xl hover:bg-white/10 transition cursor-pointer">
                                <div class="w-7 h-7 rounded-lg avatar-themed flex items-center justify-center text-[11px] font-bold text-white accent-glow">
                                    {{ $userInitial }}
                                </div>
                                <div class="hidden sm:block text-left">
                                    <p class="text-[11px] font-semibold text-white leading-tight">{{ Str::limit($userName, 15) }}</p>
                                    <p class="text-[9px] leading-tight" style="color: var(--status-text)">{{ $userRole }} · {{ Str::limit($companyName, 18) }}</p>
                                </div>
                                <svg class="w-3 h-3 hidden sm:block transition-transform" style="color: var(--meta-color)" :class="profileOpen && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>

                            <div x-show="profileOpen" x-cloak @click.outside="profileOpen = false"
                                 x-transition:enter="transition ease-out duration-150"
                                 x-transition:enter-start="opacity-0 -translate-y-2 scale-95"
                                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                 x-transition:leave="transition ease-in duration-100"
                                 x-transition:leave-start="opacity-100"
                                 x-transition:leave-end="opacity-0 scale-95"
                                 class="profile-dropdown absolute right-0 top-full mt-2 w-64 bg-white dark:bg-gray-900 rounded-xl shadow-2xl shadow-black/20 border border-gray-200/80 dark:border-gray-700/80 overflow-hidden z-[100]">

                                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800" style="background: linear-gradient(to right, hsla(var(--accent-h), var(--accent-s), 95%, 1), hsla(var(--accent-h), var(--accent-s), 92%, 1))">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl avatar-themed flex items-center justify-center text-sm font-bold text-white">{{ $userInitial }}</div>
                                        <div>
                                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ $userName }}</p>
                                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $userRole }} · {{ $companyName }}</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="py-1.5 max-h-[65vh] overflow-y-auto">
                                    <div class="px-3 pt-2 pb-1">
                                        <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-600">Quick Access</p>
                                    </div>
                                    <a href="{{ $isRestaurantLayout ? route('pos.restaurant.dashboard') : route('pos.dashboard') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                                        Dashboard
                                    </a>
                                    <a href="{{ route('pos.transactions') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                        Orders
                                    </a>
                                    <a href="{{ route('pos.products') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                        Products
                                    </a>
                                    <a href="{{ route('pos.customers') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        Customers
                                    </a>
                                    @if($isRestaurantLayout && !$isCashierLayout)
                                    <a href="{{ route('pos.restaurant.tables') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 12h16M4 18h16"/></svg>
                                        Tables
                                    </a>
                                    <a href="{{ route('pos.restaurant.kds') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                        Kitchen Display
                                    </a>
                                    @endif

                                    <div class="px-3 pt-3 pb-1">
                                        <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-600">Reports</p>
                                    </div>
                                    <a href="{{ route('pos.reports') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                        Sales Reports
                                    </a>
                                    <a href="{{ route('pos.tax-reports') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                                        Tax Reports
                                    </a>
                                    <a href="{{ route('pos.day-close') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        Day Close
                                    </a>

                                    @if($inventoryEnabledLayout && !$isCashierLayout)
                                    <div class="px-3 pt-3 pb-1">
                                        <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-600">Inventory</p>
                                    </div>
                                    <a href="{{ route('pos.inventory.dashboard') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                                        Stock Overview
                                    </a>
                                    <a href="{{ route('pos.inventory.stock') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                        Stock Levels
                                    </a>
                                    <a href="{{ route('pos.inventory.movements') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
                                        Movements
                                    </a>
                                    <a href="{{ route('pos.inventory.low-stock') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                                        Low Stock Alerts
                                    </a>
                                    @endif

                                    @if($isRestaurantLayout && !$isCashierLayout)
                                    <div class="px-3 pt-3 pb-1">
                                        <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-600">Restaurant</p>
                                    </div>
                                    <a href="{{ route('pos.restaurant.ingredients') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                                        Ingredients
                                    </a>
                                    <a href="{{ route('pos.restaurant.recipes') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                                        Recipes
                                    </a>
                                    @endif

                                    @if(!$isCashierLayout)
                                    <div class="px-3 pt-3 pb-1">
                                        <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-600">Settings</p>
                                    </div>
                                    <a href="{{ route('pos.services') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                                        Services
                                    </a>
                                    <a href="{{ route('pos.terminals') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                        Terminals
                                    </a>
                                    <a href="{{ route('pos.team') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                        Team
                                    </a>
                                    <a href="{{ route('pos.business-profile') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                        Business Profile
                                    </a>
                                    <a href="{{ route('pos.pra-settings') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        PRA Settings
                                    </a>
                                    <a href="{{ route('pos.billing') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                        Billing
                                    </a>
                                    @endif

                                    <a href="{{ route('pos.user-profile') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        My Profile
                                    </a>
                                </div>

                                <div class="border-t border-gray-100 dark:border-gray-800 p-2">
                                    <form method="POST" action="/pos/logout">
                                        @csrf
                                        <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-[12px] font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                            Sign Out
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div x-show="mobileMenuOpen" x-cloak
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0 -translate-y-2"
                     class="md:hidden border-t border-white/10 px-3 py-2 flex flex-wrap gap-1.5" style="background: hsla(var(--accent-h), var(--accent-s), 10%, 0.9)">
                    @if($isRestaurantLayout)
                    <a href="{{ route('pos.restaurant.pos') }}" class="nav-pill px-3 py-1.5 rounded-lg text-[11px] font-medium {{ request()->routeIs('pos.restaurant.pos') ? 'active text-white' : 'text-white/60' }}">New Sale</a>
                    @else
                    <a href="{{ route('pos.invoice.create') }}" class="nav-pill px-3 py-1.5 rounded-lg text-[11px] font-medium {{ request()->routeIs('pos.invoice.create') ? 'active text-white' : 'text-white/60' }}">New Sale</a>
                    @endif
                </div>
            </header>

            <main class="flex-1 overflow-y-auto overflow-x-hidden main-scroll bg-slate-50 dark:bg-gray-950 page-fade" style="min-width: 0;">
                @if(session('success'))
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 pt-4">
                        <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-xl text-sm">
                            {{ session('success') }}
                        </div>
                    </div>
                @endif
                @if(session('error'))
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 pt-4">
                        <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-xl text-sm">
                            {{ session('error') }}
                        </div>
                    </div>
                @endif

                <div class="p-4 sm:p-6">
                    {{ $slot }}
                </div>
            </main>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('form:not(.no-auto-loading)').forEach(function(form) {
                    form.addEventListener('submit', function() {
                        var btn = form.querySelector('button[type="submit"]');
                        if (btn && !btn.classList.contains('btn-loading')) {
                            btn.classList.add('btn-loading');
                            setTimeout(function() { btn.classList.remove('btn-loading'); }, 5000);
                        }
                    });
                });
            });
        </script>
        @stack('scripts')
    </body>
</html>
