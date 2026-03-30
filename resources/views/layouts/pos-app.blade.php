<!DOCTYPE html>
@php
    $isDarkMode = auth('pos')->check() && auth('pos')->user()->dark_mode;
    $posUserLayout = auth('pos')->user();
    $isCashierLayout = $posUserLayout && $posUserLayout->isPosCashier();
    $companyLayout = \App\Models\Company::find(app('currentCompanyId'));
    $isRestaurantLayout = $companyLayout && ($companyLayout->pos_type === 'restaurant' || $companyLayout->restaurant_mode);
    $praEnabledLayout = $companyLayout && $companyLayout->pra_reporting_enabled;
    $inventoryEnabledLayout = $companyLayout && $companyLayout->inventory_enabled;
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
            .page-fade { animation: fadeIn 0.15s ease-out; }
            .btn-loading { position: relative; pointer-events: none; opacity: 0.7; }
            .btn-loading::after { content: ''; position: absolute; right: 8px; top: 50%; width: 14px; height: 14px; margin-top: -7px; border: 2px solid transparent; border-top-color: currentColor; border-radius: 50%; animation: spin 0.6s linear infinite; }
            .exe-sidebar::-webkit-scrollbar { width: 3px; }
            .exe-sidebar::-webkit-scrollbar-thumb { background: rgba(124,58,237,0.2); border-radius: 4px; }
            .exe-sidebar::-webkit-scrollbar-track { background: transparent; }
            .main-scroll::-webkit-scrollbar { width: 6px; }
            .main-scroll::-webkit-scrollbar-thumb { background: rgba(156,163,175,0.3); border-radius: 4px; }
            .main-scroll::-webkit-scrollbar-track { background: transparent; }
            .exe-nav-item { transition: all 0.12s ease; border-left: 3px solid transparent; }
            .exe-nav-item:hover { background: rgba(124,58,237,0.06); }
            .dark .exe-nav-item:hover { background: rgba(124,58,237,0.12); }
            .exe-nav-item.active { background: linear-gradient(90deg, rgba(124,58,237,0.1) 0%, transparent 100%); border-left-color: #7c3aed; font-weight: 700; }
            .dark .exe-nav-item.active { background: linear-gradient(90deg, rgba(124,58,237,0.2) 0%, transparent 100%); }
            .exe-title-bar { background: linear-gradient(90deg, #1e1b4b 0%, #312e81 50%, #4c1d95 100%); }
            .exe-card { background: linear-gradient(135deg, rgba(255,255,255,0.95), rgba(255,255,255,0.85)); backdrop-filter: blur(20px); border: 1px solid rgba(0,0,0,0.06); }
            .dark .exe-card { background: linear-gradient(135deg, rgba(17,24,39,0.95), rgba(17,24,39,0.85)); border: 1px solid rgba(255,255,255,0.06); }
            [x-cloak] { display: none !important; }
        </style>
    </head>
    <body class="h-screen overflow-hidden antialiased">
        <div class="flex flex-col h-full">

            <div class="exe-title-bar flex items-center justify-between px-4 py-1.5 flex-shrink-0">
                <div class="flex items-center gap-3">
                    <button onclick="toggleSidebar()" class="lg:hidden p-1.5 rounded-lg text-purple-300 hover:text-white hover:bg-purple-500/20 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-md bg-purple-500/30 flex items-center justify-center">
                            <svg class="w-3.5 h-3.5 text-purple-200" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                        <div>
                            <span class="text-xs font-extrabold text-white tracking-tight">NestPOS</span>
                            <span class="text-[8px] text-purple-300/60 ml-1.5 hidden sm:inline">Enterprise Point of Sale</span>
                        </div>
                    </div>
                    <div class="h-4 w-px bg-purple-400/20 mx-1 hidden md:block"></div>
                    <div class="hidden md:flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full {{ $praEnabledLayout ? 'bg-green-400' : 'bg-amber-400' }}" style="box-shadow: 0 0 6px {{ $praEnabledLayout ? 'rgba(16,185,129,0.5)' : 'rgba(245,158,11,0.5)' }}"></span>
                        <span class="text-[9px] {{ $praEnabledLayout ? 'text-green-300' : 'text-amber-300' }} font-medium">PRA {{ $praEnabledLayout ? 'Connected' : 'Offline' }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-[9px] text-purple-300/50 font-mono hidden lg:block">{{ now()->format('D, d M Y — H:i') }}</span>
                    <div class="h-4 w-px bg-purple-400/15 mx-0.5 hidden lg:block"></div>
                    <div class="flex items-center gap-1.5 px-2 py-1 rounded-lg bg-purple-500/10">
                        <span class="w-5 h-5 rounded-md bg-purple-400/20 flex items-center justify-center text-[9px] font-bold text-purple-200">{{ strtoupper(substr($posUserLayout->name ?? 'A', 0, 1)) }}</span>
                        <div class="hidden sm:block">
                            <p class="text-[10px] font-semibold text-purple-100 leading-tight">{{ $posUserLayout->name ?? 'User' }}</p>
                            <p class="text-[8px] text-purple-300/50 leading-tight capitalize">{{ $isCashierLayout ? 'Cashier' : 'Admin' }}</p>
                        </div>
                    </div>
                    <form method="POST" action="/pos/logout" class="inline">
                        @csrf
                        <button type="submit" class="p-1.5 rounded-lg text-purple-300/60 hover:text-red-300 hover:bg-red-500/10 transition" title="Logout">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        </button>
                    </form>
                </div>
            </div>

            <div class="flex flex-1 overflow-hidden">

                <div id="sidebarOverlay" class="fixed inset-0 bg-black/40 z-40 lg:hidden hidden" onclick="closeSidebar()"></div>

                <nav id="sidebarDrawer" class="fixed lg:relative left-0 top-0 lg:top-auto w-52 h-full lg:h-auto flex flex-col bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 z-40 -translate-x-full lg:translate-x-0 transition-transform duration-200 flex-shrink-0 exe-sidebar overflow-y-auto">

                    <div class="lg:hidden p-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                        <span class="text-xs font-bold text-gray-900 dark:text-white">Navigation</span>
                        <button onclick="closeSidebar()" class="p-1 rounded text-gray-400 hover:text-gray-600"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                    </div>

                    <div class="py-2 px-1.5 space-y-0.5 flex-1">

                        <div class="px-3 pt-1 pb-1.5">
                            <p class="text-[8px] font-bold uppercase tracking-[0.15em] text-gray-400 dark:text-gray-600">Main</p>
                        </div>

                        <a href="{{ $isRestaurantLayout ? route('pos.restaurant.dashboard') : route('pos.dashboard') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.dashboard') || request()->routeIs('pos.restaurant.dashboard') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                            Dashboard
                        </a>

                        <a href="{{ route('pos.invoice.create') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.invoice.create') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            New Sale
                        </a>

                        <a href="{{ route('pos.transactions') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.transactions') || request()->routeIs('pos.transaction.show') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            Transactions
                        </a>

                        <a href="{{ route('pos.products') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.products') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            Products
                        </a>

                        <a href="{{ route('pos.customers') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.customers') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Customers
                        </a>

                        <div class="px-3 pt-3 pb-1.5">
                            <p class="text-[8px] font-bold uppercase tracking-[0.15em] text-gray-400 dark:text-gray-600">Reports</p>
                        </div>

                        <a href="{{ route('pos.reports') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.reports') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            Reports
                        </a>

                        <a href="{{ route('pos.tax-reports') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.tax-reports') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                            Tax Reports
                        </a>

                        <a href="{{ route('pos.day-close') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.day-close') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Day Close
                        </a>

                        @if($inventoryEnabledLayout && !$isCashierLayout)
                        <div class="px-3 pt-3 pb-1.5">
                            <p class="text-[8px] font-bold uppercase tracking-[0.15em] text-gray-400 dark:text-gray-600">Inventory</p>
                        </div>

                        <a href="{{ route('pos.inventory.dashboard') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.inventory.dashboard') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                            Stock Overview
                        </a>

                        <a href="{{ route('pos.inventory.stock') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.inventory.stock') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            Stock Levels
                        </a>

                        <a href="{{ route('pos.inventory.movements') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.inventory.movements') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
                            Movements
                        </a>

                        <a href="{{ route('pos.inventory.low-stock') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.inventory.low-stock') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                            Low Stock
                        </a>
                        @endif

                        @if(!$isCashierLayout && $isRestaurantLayout)
                        <div class="px-3 pt-3 pb-1.5">
                            <p class="text-[8px] font-bold uppercase tracking-[0.15em] text-gray-400 dark:text-gray-600">Restaurant</p>
                        </div>

                        <a href="{{ route('pos.restaurant.pos') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.restaurant.pos') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h18v18H3V3zm3 6h12m-12 6h12"/></svg>
                            Restaurant POS
                        </a>

                        <a href="{{ route('pos.restaurant.tables') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.restaurant.tables') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            Tables
                        </a>

                        <a href="{{ route('pos.restaurant.kds') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.restaurant.kds') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            Kitchen Display
                        </a>

                        <a href="{{ route('pos.restaurant.ingredients') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.restaurant.ingredients') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                            Ingredients
                        </a>

                        <a href="{{ route('pos.restaurant.recipes') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.restaurant.recipes') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                            Recipes
                        </a>
                        @endif

                        @if(!$isCashierLayout)
                        <div class="px-3 pt-3 pb-1.5">
                            <p class="text-[8px] font-bold uppercase tracking-[0.15em] text-gray-400 dark:text-gray-600">Admin</p>
                        </div>

                        <a href="{{ route('pos.services') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.services') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                            Services
                        </a>

                        <a href="{{ route('pos.terminals') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.terminals') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            Terminals
                        </a>

                        <a href="{{ route('pos.team') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.team') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            Team
                        </a>

                        <div class="px-3 pt-3 pb-1.5">
                            <p class="text-[8px] font-bold uppercase tracking-[0.15em] text-gray-400 dark:text-gray-600">Settings</p>
                        </div>

                        <a href="{{ route('pos.business-profile') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.business-profile') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                            Business Profile
                        </a>

                        <a href="{{ route('pos.pra-settings') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.pra-settings') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            PRA Settings
                        </a>

                        <a href="{{ route('pos.billing') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.billing') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                            Billing
                        </a>
                        @endif

                        <a href="{{ route('pos.user-profile') }}" class="exe-nav-item flex items-center gap-2.5 px-3 py-2 rounded-r-lg text-[11px] font-medium {{ request()->routeIs('pos.user-profile') ? 'active text-purple-700 dark:text-purple-400' : 'text-gray-600 dark:text-gray-400' }}">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            My Profile
                        </a>
                    </div>

                    <div class="p-3 border-t border-gray-100 dark:border-gray-800">
                        <div class="flex items-center gap-2 text-[9px] text-gray-400">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-400"></span>
                            NestPOS v2.0 Enterprise
                        </div>
                    </div>
                </nav>

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
        </div>

        <script>
            function toggleSidebar() {
                document.getElementById('sidebarDrawer').classList.toggle('-translate-x-full');
                document.getElementById('sidebarOverlay').classList.toggle('hidden');
            }
            function closeSidebar() {
                document.getElementById('sidebarDrawer').classList.add('-translate-x-full');
                document.getElementById('sidebarOverlay').classList.add('hidden');
            }
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('form:not(.no-auto-loading)').forEach(function(form) {
                    form.addEventListener('submit', function() {
                        var btn = form.querySelector('button[type="submit"]');
                        if (btn && !btn.classList.contains('btn-loading')) { btn.classList.add('btn-loading'); }
                    });
                });
            });
        </script>

        @php
            $toastMessages = [];
            if(session('success')) $toastMessages[] = ['msg' => session('success'), 'type' => 'success'];
            if(session('error')) $toastMessages[] = ['msg' => session('error'), 'type' => 'error'];
        @endphp
        <div x-data="{ toasts: [], init() { const msgs = JSON.parse(this.$el.dataset.messages || '[]'); msgs.forEach(m => this.addToast(m.msg, m.type)); }, addToast(msg, type) { let id = Date.now() + Math.random(); this.toasts.push({id, msg, type}); setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 5000); } }" data-messages="{{ json_encode($toastMessages) }}" class="fixed top-4 right-4 z-50 space-y-2" style="pointer-events: none;">
            <template x-for="toast in toasts" :key="toast.id">
                <div x-transition class="px-4 py-3 rounded-xl shadow-lg border text-sm font-medium max-w-sm" style="pointer-events: auto;"
                    :class="toast.type === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'">
                    <span x-text="toast.msg"></span>
                </div>
            </template>
        </div>
    </body>
</html>
