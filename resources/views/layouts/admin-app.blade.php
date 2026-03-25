<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Admin Panel' }} - TaxNest Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="h-full bg-gray-950 text-gray-100" x-data="{ sidebarOpen: false }">
    <div class="flex h-full">
        <div x-show="sidebarOpen" @click="sidebarOpen = false" class="fixed inset-0 bg-black/50 z-30 lg:hidden" x-transition.opacity></div>

        <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'" class="fixed lg:static lg:translate-x-0 z-40 w-64 h-full bg-gray-900 border-r border-gray-800 flex flex-col transition-transform duration-200">
            <div class="px-5 py-5 border-b border-gray-800">
                <h1 class="text-lg font-bold text-indigo-400">TaxNest Admin</h1>
                <p class="text-xs text-gray-500 mt-0.5">SaaS Management Panel</p>
            </div>

            <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
                @php $current = request()->route()->getName() ?? ''; @endphp

                <a href="{{ route('saas.admin.dashboard') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.dashboard' ? 'bg-indigo-600/20 text-indigo-400 font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    Dashboard
                </a>

                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-600 pt-4 pb-1 px-3">Management</p>

                <a href="{{ route('saas.admin.companies') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ str_starts_with($current, 'saas.admin.companies') && $current !== 'saas.admin.companies.bin' ? 'bg-indigo-600/20 text-indigo-400 font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    Companies
                </a>

                <a href="{{ route('saas.admin.companies.bin') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.companies.bin' ? 'bg-indigo-600/20 text-indigo-400 font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Bin
                </a>

                <a href="{{ route('saas.admin.plans') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.plans' ? 'bg-indigo-600/20 text-indigo-400 font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    Plans
                </a>

                <a href="{{ route('saas.admin.subscriptions') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.subscriptions' ? 'bg-indigo-600/20 text-indigo-400 font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    Subscriptions
                </a>

                <a href="{{ route('saas.admin.franchises') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.franchises' ? 'bg-indigo-600/20 text-indigo-400 font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    Franchises
                </a>

                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-600 pt-4 pb-1 px-3">Monitoring</p>

                <a href="{{ route('saas.admin.usage') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.usage' ? 'bg-indigo-600/20 text-indigo-400 font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    Company Usage
                </a>

                <a href="{{ route('saas.admin.system') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.system' ? 'bg-indigo-600/20 text-indigo-400 font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    System Control
                </a>

                <a href="{{ route('saas.admin.audit') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.audit' ? 'bg-indigo-600/20 text-indigo-400 font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Audit Logs
                </a>
            </nav>

            <div class="border-t border-gray-800 px-4 py-3">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-300">{{ auth('admin')->user()->name }}</p>
                        <p class="text-xs text-gray-500">{{ auth('admin')->user()->email }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="text-gray-500 hover:text-red-400 transition">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="flex-1 flex flex-col min-h-0">
            <header class="bg-gray-900 border-b border-gray-800 px-4 py-3 lg:hidden flex items-center gap-3">
                <button @click="sidebarOpen = !sidebarOpen" class="text-gray-400 hover:text-white">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 class="text-sm font-bold text-indigo-400">TaxNest Admin</h1>
            </header>

            <main class="flex-1 overflow-y-auto">
                @if(session('success'))
                <div class="mx-4 mt-4 bg-emerald-900/30 border border-emerald-700 text-emerald-300 rounded-lg px-4 py-3 text-sm">{{ session('success') }}</div>
                @endif
                @if(session('error'))
                <div class="mx-4 mt-4 bg-red-900/30 border border-red-700 text-red-300 rounded-lg px-4 py-3 text-sm">{{ session('error') }}</div>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
