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
    <script>
        var adminThemes = {
            indigo:  { accent: '#6366f1', accentHover: '#4f46e5', accentBg: 'rgba(99,102,241,0.15)', accentText: '#818cf8', sidebar: '#111827', sidebarBorder: '#1f2937', body: '#030712', label: 'Indigo' },
            emerald: { accent: '#059669', accentHover: '#047857', accentBg: 'rgba(5,150,105,0.15)', accentText: '#34d399', sidebar: '#022c22', sidebarBorder: '#064e3b', body: '#011e18', label: 'Emerald' },
            cyan:    { accent: '#0891b2', accentHover: '#0e7490', accentBg: 'rgba(8,145,178,0.15)', accentText: '#22d3ee', sidebar: '#082f49', sidebarBorder: '#0c4a6e', body: '#051e2f', label: 'Cyan' },
            rose:    { accent: '#e11d48', accentHover: '#be123c', accentBg: 'rgba(225,29,72,0.15)', accentText: '#fb7185', sidebar: '#1c0a10', sidebarBorder: '#3b0817', body: '#0f0508', label: 'Rose' },
            amber:   { accent: '#d97706', accentHover: '#b45309', accentBg: 'rgba(217,119,6,0.15)', accentText: '#fbbf24', sidebar: '#1c1304', sidebarBorder: '#3b2506', body: '#120d03', label: 'Amber' },
            purple:  { accent: '#7c3aed', accentHover: '#6d28d9', accentBg: 'rgba(124,58,237,0.15)', accentText: '#a78bfa', sidebar: '#1e1033', sidebarBorder: '#2e1065', body: '#0f0820', label: 'Purple' }
        };
        function getAdminTheme() { return localStorage.getItem('admin_theme') || 'indigo'; }
        function applyAdminTheme(name) {
            var t = adminThemes[name] || adminThemes.indigo;
            document.documentElement.style.setProperty('--admin-accent', t.accent);
            document.documentElement.style.setProperty('--admin-accent-hover', t.accentHover);
            document.documentElement.style.setProperty('--admin-accent-bg', t.accentBg);
            document.documentElement.style.setProperty('--admin-accent-text', t.accentText);
            document.documentElement.style.setProperty('--admin-sidebar', t.sidebar);
            document.documentElement.style.setProperty('--admin-sidebar-border', t.sidebarBorder);
            document.documentElement.style.setProperty('--admin-body', t.body);
            localStorage.setItem('admin_theme', name);
        }
        applyAdminTheme(getAdminTheme());
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        html, body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; text-rendering: optimizeLegibility; }
        body { letter-spacing: -0.011em; }
        h1, h2, h3, h4, h5, h6, .font-bold, .font-extrabold, .font-semibold { text-rendering: geometricPrecision; }
        body.admin-themed { background-color: var(--admin-body) !important; }
        .admin-sidebar { background-color: var(--admin-sidebar) !important; border-color: var(--admin-sidebar-border) !important; }
        .admin-sidebar-border { border-color: var(--admin-sidebar-border) !important; }
        .admin-accent-text { color: var(--admin-accent-text) !important; }
        .admin-active-link { background-color: var(--admin-accent-bg) !important; color: var(--admin-accent-text) !important; }
        .admin-header-border { border-color: var(--admin-sidebar-border) !important; background-color: var(--admin-sidebar) !important; }
        .admin-btn { background-color: var(--admin-accent) !important; }
        .admin-btn:hover { background-color: var(--admin-accent-hover) !important; }
    </style>
</head>
<body class="h-full bg-gray-950 text-gray-100 admin-themed" x-data="{ sidebarOpen: false, themeOpen: false }">
    <div class="flex h-full">
        <div x-show="sidebarOpen" @click="sidebarOpen = false" class="fixed inset-0 bg-black/50 z-30 lg:hidden" x-transition.opacity></div>

        <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'" class="fixed lg:static lg:translate-x-0 z-40 w-64 h-full admin-sidebar flex flex-col transition-transform duration-200">
            <div class="px-5 py-5 admin-sidebar-border border-b">
                <h1 class="text-lg font-bold admin-accent-text">TaxNest Admin</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">SaaS Management Panel</p>
            </div>

            <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
                @php $current = request()->route()->getName() ?? ''; @endphp

                <a href="{{ route('saas.admin.dashboard') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.dashboard' ? 'admin-active-link font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    Dashboard
                </a>

                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-600 dark:text-gray-400 pt-4 pb-1 px-3">Management</p>

                <a href="{{ route('saas.admin.companies') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ str_starts_with($current, 'saas.admin.companies') && $current !== 'saas.admin.companies.bin' ? 'admin-active-link font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    Companies
                </a>

                <a href="{{ route('saas.admin.companies.bin') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.companies.bin' ? 'admin-active-link font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Bin
                </a>

                <a href="{{ route('saas.admin.plans') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.plans' ? 'admin-active-link font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    Plans
                </a>

                <a href="{{ route('saas.admin.subscriptions') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.subscriptions' ? 'admin-active-link font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    Subscriptions
                </a>

                <a href="{{ route('saas.admin.franchises') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.franchises' ? 'admin-active-link font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    Franchises
                </a>

                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-600 dark:text-gray-400 pt-4 pb-1 px-3">Monitoring</p>

                <a href="{{ route('saas.admin.usage') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.usage' ? 'admin-active-link font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    Company Usage
                </a>

                <a href="{{ route('saas.admin.system') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.system' ? 'admin-active-link font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    System Control
                </a>

                <a href="{{ route('saas.admin.audit') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm {{ $current === 'saas.admin.audit' ? 'admin-active-link font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200' }} transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Audit Logs
                </a>
            </nav>

            <div class="admin-sidebar-border border-t px-4 py-2">
                <div class="relative" x-data="{ tp: false }">
                    <button @click="tp = !tp" class="flex items-center gap-2 w-full px-2 py-1.5 rounded-lg text-xs text-gray-400 hover:text-gray-200 hover:bg-gray-800/50 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                        Theme
                        <svg class="w-3 h-3 ml-auto transition-transform" :class="tp ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                    </button>
                    <div x-show="tp" x-transition @click.away="tp = false" class="absolute bottom-full left-0 mb-1 w-full bg-gray-900 border border-gray-700 rounded-lg shadow-xl p-2 space-y-1 z-50">
                        <button onclick="applyAdminTheme('indigo'); location.reload();" class="flex items-center gap-2 w-full px-2 py-1.5 rounded text-xs text-gray-300 hover:bg-gray-800 transition">
                            <span class="w-3 h-3 rounded-full" style="background:#6366f1;"></span> Indigo
                        </button>
                        <button onclick="applyAdminTheme('emerald'); location.reload();" class="flex items-center gap-2 w-full px-2 py-1.5 rounded text-xs text-gray-300 hover:bg-gray-800 transition">
                            <span class="w-3 h-3 rounded-full" style="background:#059669;"></span> Emerald
                        </button>
                        <button onclick="applyAdminTheme('cyan'); location.reload();" class="flex items-center gap-2 w-full px-2 py-1.5 rounded text-xs text-gray-300 hover:bg-gray-800 transition">
                            <span class="w-3 h-3 rounded-full" style="background:#0891b2;"></span> Cyan
                        </button>
                        <button onclick="applyAdminTheme('rose'); location.reload();" class="flex items-center gap-2 w-full px-2 py-1.5 rounded text-xs text-gray-300 hover:bg-gray-800 transition">
                            <span class="w-3 h-3 rounded-full" style="background:#e11d48;"></span> Rose
                        </button>
                        <button onclick="applyAdminTheme('amber'); location.reload();" class="flex items-center gap-2 w-full px-2 py-1.5 rounded text-xs text-gray-300 hover:bg-gray-800 transition">
                            <span class="w-3 h-3 rounded-full" style="background:#d97706;"></span> Amber
                        </button>
                        <button onclick="applyAdminTheme('purple'); location.reload();" class="flex items-center gap-2 w-full px-2 py-1.5 rounded text-xs text-gray-300 hover:bg-gray-800 transition">
                            <span class="w-3 h-3 rounded-full" style="background:#7c3aed;"></span> Purple
                        </button>
                    </div>
                </div>
            </div>
            <div class="admin-sidebar-border border-t px-4 py-3">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-300">{{ auth('admin')->user()->name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ auth('admin')->user()->email }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="text-gray-500 dark:text-gray-400 hover:text-red-400 transition">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="flex-1 flex flex-col min-h-0">
            <header class="admin-header-border border-b px-4 py-3 lg:hidden flex items-center gap-3">
                <button @click="sidebarOpen = !sidebarOpen" class="text-gray-400 hover:text-white">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 class="text-sm font-bold admin-accent-text">TaxNest Admin</h1>
            </header>

            <main class="flex-1 overflow-y-auto">
                @if(isset($header))
                <div class="bg-gray-900 border-b border-gray-800 px-6 py-4 hidden lg:block">
                    {{ $header }}
                </div>
                @endif
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
