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
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Nest FBR Pos">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="application-name" content="Nest FBR Pos">
        <link rel="manifest" href="/manifest-fbrpos.json">
        <link rel="apple-touch-icon" href="/icons/nest-fbr/icon-192.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/icons/nest-fbr/icon-192.png">
        <link rel="icon" type="image/png" sizes="512x512" href="/icons/nest-fbr/icon-512.png">
        <title>FBR POS — {{ config('app.name', 'TaxNest') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800,900&display=swap" rel="stylesheet" />
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        {{-- Alpine CDN fallback — only fires if Vite bundle failed (5s grace) --}}
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
            }, 5000);
        </script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
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
            /* === FBR POS Premium Header === */
            .topnav-bar {
                background:
                    radial-gradient(1100px 220px at 12% -50%, rgba(96,165,250,0.28), transparent 60%),
                    radial-gradient(900px 180px at 88% -40%, rgba(251,191,36,0.16), transparent 65%),
                    linear-gradient(135deg, #050b18 0%, #0b1d3d 28%, #1e3a8a 62%, #1d4ed8 100%);
                box-shadow:
                    0 1px 0 rgba(255,255,255,0.08) inset,
                    0 8px 24px -10px rgba(0,0,0,0.55),
                    0 0 0 1px rgba(255,255,255,0.04);
                position: relative;
            }
            .topnav-bar::after {
                content: '';
                position: absolute; left: 0; right: 0; bottom: 0; height: 1px;
                background: linear-gradient(90deg, transparent 0%, rgba(96,165,250,0.55) 25%, rgba(251,191,36,0.45) 55%, rgba(96,165,250,0.55) 80%, transparent 100%);
                opacity: 0.55;
            }
            .nav-pill { transition: all 0.18s cubic-bezier(.4,0,.2,1); position: relative; }
            .nav-pill:hover { background: rgba(255,255,255,0.14); transform: translateY(-1px); }
            .nav-pill.active {
                background: linear-gradient(135deg, rgba(96,165,250,0.28), rgba(59,130,246,0.18));
                box-shadow: 0 0 0 1px rgba(147,197,253,0.35) inset, 0 4px 14px -4px rgba(59,130,246,0.45);
            }
            .nav-pill.active::after {
                content: ''; position: absolute; left: 14%; right: 14%; bottom: -2px; height: 2px;
                background: linear-gradient(90deg, transparent, #fbbf24, transparent);
                border-radius: 2px;
            }
            /* Premium gold-accented FBR badge */
            .fbr-badge-premium {
                background: linear-gradient(135deg, rgba(251,191,36,0.18) 0%, rgba(245,158,11,0.10) 100%);
                color: #fcd34d;
                border: 1px solid rgba(251,191,36,0.35);
                box-shadow: 0 0 0 1px rgba(0,0,0,0.18) inset, 0 4px 10px -4px rgba(251,191,36,0.5);
                text-shadow: 0 1px 0 rgba(0,0,0,0.25);
                letter-spacing: 0.06em;
            }
            /* Brand mark with subtle inner glow */
            .brand-tile-fbr {
                background: linear-gradient(135deg, rgba(255,255,255,0.20) 0%, rgba(96,165,250,0.18) 50%, rgba(251,191,36,0.10) 100%);
                box-shadow: 0 0 0 1px rgba(255,255,255,0.18) inset, 0 4px 12px -4px rgba(59,130,246,0.45);
            }
            /* Profile avatar ring */
            .avatar-ring-fbr {
                background: linear-gradient(135deg, #fbbf24 0%, #60a5fa 50%, #1d4ed8 100%);
                box-shadow: 0 4px 14px -4px rgba(251,191,36,0.55), 0 0 0 1px rgba(255,255,255,0.18) inset;
            }
            .profile-dropdown {
                animation: slideDown 0.18s cubic-bezier(.4,0,.2,1);
                box-shadow: 0 25px 60px -15px rgba(15,23,42,0.55), 0 0 0 1px rgba(15,23,42,0.06);
            }
            .menu-link { transition: all 0.12s ease; border-left: 2px solid transparent; }
            .menu-link:hover { background: linear-gradient(90deg, rgba(37,99,235,0.10), transparent 70%); border-left-color: #2563eb; padding-left: calc(1rem + 2px); }
            .dark .menu-link:hover { background: linear-gradient(90deg, rgba(59,130,246,0.18), transparent 70%); border-left-color: #60a5fa; }
            /* Premium page background — subtle navy/blue wash with corner gradients */
            .fbr-page-bg {
                background:
                    radial-gradient(circle 800px at 100% 0%, rgba(96,165,250,0.10), transparent 60%),
                    radial-gradient(circle 600px at 0% 100%, rgba(251,191,36,0.06), transparent 55%),
                    linear-gradient(180deg, #f5f8ff 0%, #f1f5fb 100%);
            }
            .dark .fbr-page-bg {
                background:
                    radial-gradient(circle 800px at 100% 0%, rgba(59,130,246,0.10), transparent 60%),
                    radial-gradient(circle 600px at 0% 100%, rgba(251,191,36,0.04), transparent 55%),
                    linear-gradient(180deg, #030712 0%, #0a1124 100%);
            }
            /* Premium session toast banners */
            .fbr-banner-success {
                background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
                border: 1px solid #6ee7b7;
                box-shadow: 0 6px 18px -8px rgba(16,185,129,0.35), 0 0 0 1px rgba(16,185,129,0.10) inset;
            }
            .dark .fbr-banner-success { background: linear-gradient(135deg, rgba(6,78,59,0.40), rgba(6,95,70,0.30)); border-color: rgba(16,185,129,0.45); }
            [x-cloak] { display: none !important; }
        </style>
        {{-- PWA service worker (FBR POS scope) --}}
        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).catch(()=>{});
                });
            }
        </script>
    </head>
    <body class="h-screen overflow-hidden antialiased">
        <x-pwa-init />
        <div class="flex flex-col h-full" x-data="{ profileOpen: false, mobileMenuOpen: false }" @keydown.escape.window="profileOpen = false; mobileMenuOpen = false">

            <header class="topnav-bar flex-shrink-0 relative z-50">
                <div class="flex items-center justify-between px-3 sm:px-5 h-12">

                    <div class="flex items-center gap-3">
                        <a href="{{ route('fbrpos.dashboard') }}" class="flex items-center gap-2.5 group">
                            <div class="brand-tile-fbr w-8 h-8 rounded-xl flex items-center justify-center transition group-hover:scale-105">
                                <svg class="w-4 h-4 text-white drop-shadow" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                            </div>
                            <div class="hidden sm:flex items-center gap-1.5">
                                <span class="text-sm font-extrabold text-white tracking-tight">FBR POS</span>
                                <span class="hidden lg:inline-flex items-center px-1.5 py-0.5 rounded-md text-[8.5px] font-black tracking-widest fbr-badge-premium">PREMIUM</span>
                                <span class="text-[9px] text-white/60 ml-0.5 hidden xl:inline">by TaxNest</span>
                            </div>
                        </a>

                        <div class="h-5 w-px bg-white/10 hidden md:block"></div>

                        <nav class="hidden md:flex items-center gap-1">
                            <a href="{{ route('fbrpos.create') }}"
                               class="nav-pill px-3 py-1.5 rounded-lg text-xs font-semibold {{ request()->routeIs('fbrpos.create') ? 'active text-white' : 'text-white/90' }}">
                                New Sale
                            </a>
                        </nav>

                        <div class="hidden md:block ml-1">
                            <x-branch-switcher color="blue" />
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-pwa-install color="blue" label="Install" />
                        <x-pwa-refresh-btn color="blue" />
                        <span class="hidden lg:inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black tracking-wider fbr-badge-premium">
                            <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 16.8l-6.2 4.5 2.4-7.4L2 9.4h7.6z"/></svg>
                            FBR CERTIFIED
                        </span>

                        <div class="relative">
                            <button @click="profileOpen = !profileOpen" class="flex items-center gap-2 px-2 py-1 rounded-lg hover:bg-white/10 transition group">
                                <div class="avatar-ring-fbr w-8 h-8 rounded-full p-[1.5px] flex items-center justify-center transition group-hover:scale-105">
                                    <div class="w-full h-full rounded-full bg-gradient-to-br from-blue-500 to-indigo-700 flex items-center justify-center text-white text-xs font-black">{{ $userInitial }}</div>
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
                                    @php
                                        $failQueueCount = 0;
                                        try {
                                            $cid = app('currentCompanyId');
                                            $failQueueCount = \App\Models\FbrPosTransaction::where('company_id', $cid)
                                                ->whereIn('fbr_status', ['failed', 'pending'])
                                                ->where(function ($q) { $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode'); })
                                                ->count();
                                        } catch (\Throwable $e) {}
                                    @endphp
                                    <a href="{{ route('fbrpos.failQueue') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 {{ $failQueueCount > 0 ? 'text-red-500' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.74-2.991l-7-12a2 2 0 00-3.48 0l-7 12A2 2 0 005 19z"/></svg>
                                        <span class="flex-1">Fail Queue</span>
                                        @if($failQueueCount > 0)
                                            <span class="px-2 py-0.5 rounded-full bg-red-500 text-white text-[10px] font-bold">{{ $failQueueCount }}</span>
                                        @endif
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

            <main class="flex-1 overflow-y-auto overflow-x-hidden main-scroll page-fade fbr-page-bg" style="min-width: 0;">
                @if(session('success'))
                    <div class="max-w-7xl mx-auto mb-4 px-4 sm:px-6 pt-4">
                        <div class="bg-emerald-50 dark:bg-emerald-900/30 border-2 border-emerald-300 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200 px-4 py-3 rounded-lg font-semibold shadow-sm">
                            {{ session('success') }}
                        </div>
                    </div>
                @endif
                @if(session('warning'))
                    <div class="max-w-7xl mx-auto mb-4 px-4 sm:px-6 pt-2">
                        <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700 text-amber-800 dark:text-amber-200 px-4 py-3 rounded-lg flex items-start gap-3">
                            <span class="text-xl leading-none">⚠️</span>
                            <div class="flex-1">{{ session('warning') }}
                                @if(str_contains(strtolower(session('warning')), 'token'))
                                    <a href="{{ route('fbrpos.settings') }}" class="ml-2 inline-flex items-center px-2.5 py-1 rounded-md bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold">⚙ Configure FBR Token →</a>
                                @endif
                            </div>
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

                <div class="max-w-7xl mx-auto px-4 sm:px-6 pt-3">
                    <x-pwa-banner color="blue" appName="Nest FBR Pos" />
                </div>

                {{ $slot }}
            </main>
        </div>
        <x-pwa-push scope="fbrpos" />

        @php
            $toastMessages = [];
            if(session('success')) $toastMessages[] = ['msg' => session('success'), 'type' => 'success'];
            if(session('warning')) $toastMessages[] = ['msg' => session('warning'), 'type' => 'warning'];
            if(session('error')) $toastMessages[] = ['msg' => session('error'), 'type' => 'error'];
        @endphp
        <div x-data="{ toasts: [], init() { const msgs = JSON.parse(this.$el.dataset.messages || '[]'); msgs.forEach(m => this.addToast(m.msg, m.type)); }, addToast(msg, type) { let id = Date.now() + Math.random(); this.toasts.push({id, msg, type}); setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 6000); } }" data-messages="{{ json_encode($toastMessages) }}" class="fixed top-4 right-4 z-50 space-y-2" style="pointer-events: none;">
            <template x-for="toast in toasts" :key="toast.id">
                <div x-transition class="px-4 py-3 rounded-xl shadow-lg border-2 text-sm font-semibold max-w-sm" style="pointer-events: auto;"
                    :class="toast.type === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' :
                            toast.type === 'warning' ? 'bg-amber-50 border-amber-300 text-amber-800' :
                            'bg-red-50 border-red-200 text-red-800'">
                    <span x-text="toast.msg"></span>
                </div>
            </template>
        </div>
        <script>
        // Smart prefetch of FBR POS sale screen — skip on slow/save-data connections
        (function(){
            try {
                var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
                if (c && (c.saveData || /2g/.test(c.effectiveType || ''))) return;
                if (location.pathname.indexOf('/fbr-pos/create') === 0) return;
                var run = function(){
                    var l = document.createElement('link');
                    l.rel = 'prefetch'; l.href = '/fbr-pos/create'; l.as = 'document';
                    document.head.appendChild(l);
                };
                ('requestIdleCallback' in window) ? requestIdleCallback(run, {timeout: 4000}) : setTimeout(run, 2500);
            } catch(_){}
        })();
        </script>
        <x-pwa-update color="blue" />
    </body>
</html>
