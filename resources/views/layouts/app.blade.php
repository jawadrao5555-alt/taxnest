<!DOCTYPE html>
@php
    $isDarkMode = auth()->check() && auth()->user()->dark_mode;
    $isInternal = auth()->check() && auth()->user()->company_id && optional(\App\Models\Company::find(auth()->user()->company_id))->is_internal_account;
    if ($isInternal && is_null(auth()->user()->dark_mode)) {
        auth()->user()->update(['dark_mode' => true]);
        $isDarkMode = true;
    }
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $isDarkMode ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
        <meta name="theme-color" content="#059669">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="TaxNest">
        <link rel="manifest" href="/manifest.json">
        <link rel="apple-touch-icon" href="/icons/icon-192.png">

        <title>{{ config('app.name', 'TaxNest') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>if(document.documentElement.classList.contains('dark')){document.documentElement.style.colorScheme='dark';}</script>
        <style>
            :root {
                --card-radius: 12px;
                --card-shadow: 0 1px 3px rgba(0,0,0,0.08);
            }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
            .premium-hover { transition: all 0.15s ease; }
            .premium-hover:hover { transform: translateY(-1px); }
            .btn-premium { transition: all 0.15s ease; }
            .btn-premium:hover { transform: scale(1.02); }
            .btn-premium:active { transform: scale(0.98); }
            .sidebar-scroll::-webkit-scrollbar { width: 4px; }
            .sidebar-scroll::-webkit-scrollbar-thumb { background: rgba(156,163,175,0.3); border-radius: 4px; }
            .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
            .main-scroll::-webkit-scrollbar { width: 6px; }
            .main-scroll::-webkit-scrollbar-thumb { background: rgba(156,163,175,0.3); border-radius: 4px; }
            .main-scroll::-webkit-scrollbar-track { background: transparent; }
            .sidebar-link { transition: all 0.15s ease; }
            .sidebar-link:hover { background-color: rgba(249,250,251,0.8); }
            .dark .sidebar-link:hover { background-color: rgba(55,65,81,0.5); }
            .sidebar-link.active { background: linear-gradient(90deg, rgba(16,185,129,0.08) 0%, transparent 100%); font-weight: 600; border-left: 3px solid #10b981; padding-left: 13px; }
            .dark .sidebar-link.active { background: linear-gradient(90deg, rgba(16,185,129,0.15) 0%, transparent 100%); border-left: 3px solid #10b981; padding-left: 13px; }
        </style>
    </head>
    <body class="h-screen overflow-hidden font-sans antialiased">
        @auth
        <div id="sidebarOverlay" class="fixed inset-0 bg-black/40 z-40 lg:hidden hidden" onclick="closeSidebar()"></div>

        <div class="flex h-full">
            <nav id="sidebarDrawer" class="fixed left-0 top-0 w-64 h-full overflow-y-auto z-40 bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 sidebar-scroll -translate-x-full lg:translate-x-0 transition-transform duration-200">
                <div class="absolute top-3 right-3 z-10 lg:hidden">
                    <button onclick="closeSidebar()" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                @include('layouts.navigation')
            </nav>

            <div class="flex flex-col h-full w-full lg:ml-64">
                <header class="sticky top-0 z-30 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
                    <div class="flex items-center justify-between h-14 px-4 sm:px-6">
                        <div class="flex items-center gap-3">
                            <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-700 transition">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            </button>
                            @if(url()->previous() !== url()->current() && url()->previous() !== '')
                                <a href="{{ url()->previous() }}" class="hidden sm:inline-flex items-center text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200 transition">
                                    <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                    Back
                                </a>
                            @endif
                            @isset($header)
                                <div class="text-sm">{{ $header }}</div>
                            @endisset
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="hidden sm:inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                @if(auth()->user()->role === 'super_admin') bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300
                                @elseif(auth()->user()->role === 'company_admin') bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300
                                @elseif(auth()->user()->role === 'employee') bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300
                                @else bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300
                                @endif">
                                {{ ucfirst(str_replace('_', ' ', auth()->user()->role)) }}
                            </span>
                            <button id="pwa-install-btn" onclick="installPwa()" class="hidden items-center px-3 py-1.5 bg-emerald-600 text-white text-xs font-bold rounded-lg hover:bg-emerald-700 shadow-sm transition">
                                <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                Install
                            </button>
                            <x-dropdown align="right" width="48">
                                <x-slot name="trigger">
                                    <button class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                        <span class="w-7 h-7 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center text-emerald-700 dark:text-emerald-400 text-xs font-bold">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</span>
                                        <span class="hidden sm:inline">{{ Auth::user()->name }}</span>
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" fill="currentColor"/></svg>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <x-dropdown-link :href="route('profile.edit')">Profile</x-dropdown-link>
                                    <form method="POST" action="{{ route('toggle.dark-mode') }}">
                                        @csrf
                                        <x-dropdown-link href="#" onclick="event.preventDefault(); this.closest('form').submit();">
                                            {{ auth()->user()->dark_mode ? 'Light Mode' : 'Dark Mode' }}
                                        </x-dropdown-link>
                                    </form>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">Log Out</x-dropdown-link>
                                    </form>
                                </x-slot>
                            </x-dropdown>
                        </div>
                    </div>
                </header>

                @if(auth()->check() && auth()->user()->company_id)
                    @php $currentCompany = \App\Models\Company::find(auth()->user()->company_id); @endphp
                    @if($currentCompany && $currentCompany->company_status === 'pending')
                    <div class="bg-amber-500 text-white text-center py-2 px-4 text-sm font-medium">
                        Your company registration is pending approval. Some features may be limited.
                    </div>
                    @endif
                    @if($currentCompany && $currentCompany->company_status === 'suspended')
                    <div class="bg-red-600 text-white text-center py-2 px-4 text-sm font-medium">
                        Your company has been suspended. Please contact support.
                    </div>
                    @endif
                @endif

                <main class="flex-1 overflow-y-auto p-4 sm:p-6 main-scroll bg-gray-50 dark:bg-gray-950">
                    @if(session('success'))
                        <div class="max-w-7xl mx-auto mb-4">
                            <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg">
                                {{ session('success') }}
                            </div>
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="max-w-7xl mx-auto mb-4">
                            <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
                                {{ session('error') }}
                            </div>
                        </div>
                    @endif

                    {{ $slot }}
                </main>
            </div>
        </div>
        @else
        {{ $slot }}
        @endauth

        <div id="pwa-install-popup" class="hidden fixed bottom-6 right-6 z-50 bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-200 dark:border-gray-700 p-5 max-w-sm">
            <div class="flex items-start space-x-4">
                <div class="p-3 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-xl shadow-sm">
                    <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                </div>
                <div class="flex-1">
                    <h4 class="text-sm font-bold text-gray-900 dark:text-white">Install TaxNest App</h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Get instant access from your home screen with offline support.</p>
                    <div class="flex items-center space-x-2 mt-3">
                        <button onclick="installPwa()" class="px-4 py-1.5 bg-emerald-600 text-white text-xs font-bold rounded-lg hover:bg-emerald-700 transition">Install</button>
                        <button onclick="dismissInstallPopup()" class="px-3 py-1.5 text-gray-500 text-xs font-medium hover:text-gray-700 transition">Later</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="offline-badge" class="hidden fixed bottom-4 left-4 z-50 bg-amber-500 text-white text-xs font-bold px-3 py-1.5 rounded-full shadow-md">
            <svg class="w-3.5 h-3.5 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m-2.829-2.829a5 5 0 010-7.07m-2.828 2.829a1 1 0 010 1.414"/></svg>
            Offline
        </div>

        <div id="sw-update-banner" class="hidden fixed top-0 left-0 right-0 z-50 bg-indigo-600 text-white text-xs font-bold text-center py-2 shadow-md cursor-pointer" onclick="location.reload()">
            <svg class="w-3.5 h-3.5 inline mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            Update Available — Click to Refresh
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

            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('/sw.js').then(reg => {
                    reg.addEventListener('updatefound', () => {
                        const newWorker = reg.installing;
                        if (newWorker) {
                            newWorker.addEventListener('statechange', () => {
                                if (newWorker.state === 'activated') {
                                    const banner = document.getElementById('sw-update-banner');
                                    if (banner) banner.classList.remove('hidden');
                                }
                            });
                        }
                    });
                }).catch(() => {});
            }
            let deferredPrompt;
            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredPrompt = e;
                const btn = document.getElementById('pwa-install-btn');
                if (btn) btn.classList.remove('hidden');
                const popup = document.getElementById('pwa-install-popup');
                if (popup && !localStorage.getItem('pwa-install-dismissed')) {
                    setTimeout(() => popup.classList.remove('hidden'), 2000);
                }
            });
            function installPwa() {
                if (deferredPrompt) {
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.then(() => {
                        deferredPrompt = null;
                        const popup = document.getElementById('pwa-install-popup');
                        if (popup) popup.classList.add('hidden');
                    });
                }
            }
            function dismissInstallPopup() {
                const popup = document.getElementById('pwa-install-popup');
                if (popup) popup.classList.add('hidden');
                localStorage.setItem('pwa-install-dismissed', '1');
            }
            window.addEventListener('online', () => {
                const badge = document.getElementById('offline-badge');
                if (badge) badge.classList.add('hidden');
            });
            window.addEventListener('offline', () => {
                const badge = document.getElementById('offline-badge');
                if (badge) badge.classList.remove('hidden');
            });
        </script>
    </body>
</html>