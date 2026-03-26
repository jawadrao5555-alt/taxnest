<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Franchise Panel' }} - TaxNest</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="h-full bg-gray-50 dark:bg-gray-950 text-gray-900 dark:text-gray-100" x-data="{ sidebarOpen: false }">
    <div class="flex h-full">
        <div x-show="sidebarOpen" @click="sidebarOpen = false" class="fixed inset-0 bg-black/50 z-30 lg:hidden" x-transition.opacity></div>

        <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'" class="fixed lg:static lg:translate-x-0 z-40 w-60 h-full bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 flex flex-col transition-transform duration-200">
            <div class="px-5 py-5 border-b border-gray-200 dark:border-gray-800">
                <h1 class="text-lg font-bold text-teal-600">TaxNest Franchise</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ auth('franchise')->user()->name ?? 'Partner Portal' }}</p>
            </div>

            <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
                @php $current = request()->route()->getName() ?? ''; @endphp
                <a href="{{ route('franchise.dashboard') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'franchise.dashboard' ? 'bg-teal-50 dark:bg-teal-900/20 text-teal-700 dark:text-teal-400 font-medium' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    Dashboard
                </a>
                <a href="{{ route('franchise.companies') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'franchise.companies' ? 'bg-teal-50 dark:bg-teal-900/20 text-teal-700 dark:text-teal-400 font-medium' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    Companies
                </a>
                <a href="{{ route('franchise.subscriptions') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'franchise.subscriptions' ? 'bg-teal-50 dark:bg-teal-900/20 text-teal-700 dark:text-teal-400 font-medium' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    Subscriptions
                </a>
                <a href="{{ route('franchise.revenue') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'franchise.revenue' ? 'bg-teal-50 dark:bg-teal-900/20 text-teal-700 dark:text-teal-400 font-medium' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800' }} transition">
                    <span class="text-xs font-bold">PKR</span>
                    Revenue
                </a>
            </nav>

            <div class="border-t border-gray-200 dark:border-gray-800 px-4 py-3">
                <form method="POST" action="{{ route('franchise.logout') }}">
                    @csrf
                    <button type="submit" class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 hover:text-red-500 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        Logout
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex-1 flex flex-col min-h-0">
            <header class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 px-4 py-3 lg:hidden flex items-center gap-3">
                <button @click="sidebarOpen = !sidebarOpen" class="text-gray-500 dark:text-gray-400"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg></button>
                <h1 class="text-sm font-bold text-teal-600">TaxNest Franchise</h1>
            </header>
            <main class="flex-1 overflow-y-auto">
                @if(session('success'))<div class="mx-4 mt-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 text-emerald-800 dark:text-emerald-300 rounded-lg px-4 py-3 text-sm">{{ session('success') }}</div>@endif
                @if(session('error'))<div class="mx-4 mt-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-800 dark:text-red-300 rounded-lg px-4 py-3 text-sm">{{ session('error') }}</div>@endif
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
