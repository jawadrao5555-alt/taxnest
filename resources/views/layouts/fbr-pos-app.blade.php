<!DOCTYPE html>
@php
    $isDarkMode = auth()->check() && auth()->user()->dark_mode;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $isDarkMode ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
        <meta name="theme-color" content="#059669">
        <title>FBR POS — {{ config('app.name', 'TaxNest') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
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
        <script>if(document.documentElement.classList.contains('dark')){document.documentElement.style.colorScheme='dark';}</script>
        <style>
            :root { --card-radius: 12px; --card-shadow: 0 1px 3px rgba(0,0,0,0.08); }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
            @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
            .page-fade { animation: fadeIn 0.15s ease-out; }
            .btn-loading { position: relative; pointer-events: none; opacity: 0.7; }
            .btn-loading::after { content: ''; position: absolute; right: 8px; top: 50%; width: 14px; height: 14px; margin-top: -7px; border: 2px solid transparent; border-top-color: currentColor; border-radius: 50%; animation: spin 0.6s linear infinite; }
            .sidebar-scroll::-webkit-scrollbar { width: 4px; }
            .sidebar-scroll::-webkit-scrollbar-thumb { background: rgba(156,163,175,0.3); border-radius: 4px; }
            .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
            .main-scroll::-webkit-scrollbar { width: 6px; }
            .main-scroll::-webkit-scrollbar-thumb { background: rgba(156,163,175,0.3); border-radius: 4px; }
            .main-scroll::-webkit-scrollbar-track { background: transparent; }
            .sidebar-link { transition: all 0.15s ease; }
            .sidebar-link:hover { background-color: rgba(249,250,251,0.8); }
            .dark .sidebar-link:hover { background-color: rgba(55,65,81,0.5); }
            .sidebar-link.active { background: linear-gradient(90deg, rgba(5,150,105,0.08) 0%, transparent 100%); font-weight: 600; border-left: 3px solid #059669; padding-left: 13px; }
            .dark .sidebar-link.active { background: linear-gradient(90deg, rgba(5,150,105,0.15) 0%, transparent 100%); border-left: 3px solid #059669; padding-left: 13px; }
            [x-cloak] { display: none !important; }
        </style>
    </head>
    <body class="h-screen overflow-hidden font-sans antialiased">
        <div id="sidebarOverlay" class="fixed inset-0 bg-black/40 z-40 lg:hidden hidden" onclick="closeSidebar()"></div>

        <div class="flex h-full">
            <nav id="sidebarDrawer" class="fixed left-0 top-0 w-64 h-full overflow-y-auto z-40 bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 sidebar-scroll -translate-x-full lg:translate-x-0 transition-transform duration-200">
                <div class="absolute top-3 right-3 z-10 lg:hidden">
                    <button onclick="closeSidebar()" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="p-5 border-b border-gray-200 dark:border-gray-800">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg bg-emerald-600 flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <p class="font-bold text-sm text-gray-900 dark:text-white">FBR POS</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">by TaxNest</p>
                        </div>
                    </div>
                </div>

                <div class="p-3 space-y-0.5">
                    <a href="{{ route('fbrpos.dashboard') }}" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-700 dark:text-gray-300 {{ request()->routeIs('fbrpos.dashboard') ? 'active' : '' }}">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                        Dashboard
                    </a>
                    <a href="{{ route('fbrpos.create') }}" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-700 dark:text-gray-300 {{ request()->routeIs('fbrpos.create') ? 'active' : '' }}">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        New Sale
                    </a>
                    <a href="{{ route('fbrpos.transactions') }}" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-700 dark:text-gray-300 {{ request()->routeIs('fbrpos.transactions') ? 'active' : '' }}">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        Transactions
                    </a>

                    <div class="pt-3 mt-3 border-t border-gray-200 dark:border-gray-700">
                        <a href="{{ route('dashboard') }}" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-500 dark:text-gray-400">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"/></svg>
                            Back to DI
                        </a>
                    </div>
                </div>
            </nav>

            <div class="flex flex-col h-full w-full lg:ml-64">
                <header class="sticky top-0 z-30 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
                    <div class="flex items-center justify-between h-14 px-4 sm:px-6">
                        <div class="flex items-center gap-3">
                            <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-700 transition">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            </button>
                            @if(!request()->routeIs('fbrpos.dashboard'))
                                <a href="{{ route('fbrpos.dashboard') }}" class="inline-flex items-center text-sm text-gray-500 hover:text-emerald-700 dark:text-gray-400 dark:hover:text-emerald-300 transition">
                                    <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                    <span class="hidden sm:inline">Dashboard</span>
                                </a>
                            @endif
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="hidden sm:inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">
                                FBR POS
                            </span>
                            <div class="relative" x-data="{ open: false }">
                                <button @click="open = !open" class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                    <span class="w-7 h-7 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center text-emerald-700 dark:text-emerald-400 text-xs font-bold">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                                    <span class="hidden sm:inline">{{ auth()->user()->name }}</span>
                                </button>
                                <div x-show="open" @click.away="open = false" x-cloak class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 py-1 z-50">
                                    <a href="{{ route('dashboard') }}" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">Back to DI</a>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">Log Out</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="flex-1 overflow-y-auto p-4 sm:p-6 main-scroll bg-gray-50 dark:bg-gray-950 page-fade">
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

        <script>
            function toggleSidebar() {
                document.getElementById('sidebarDrawer').classList.toggle('-translate-x-full');
                document.getElementById('sidebarOverlay').classList.toggle('hidden');
            }
            function closeSidebar() {
                document.getElementById('sidebarDrawer').classList.add('-translate-x-full');
                document.getElementById('sidebarOverlay').classList.add('hidden');
            }
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
