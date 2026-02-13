<div class="flex flex-col h-full">
    <div class="flex-shrink-0 px-5 pt-5 pb-4">
        <a href="{{ route('dashboard') }}" class="flex items-center space-x-2.5">
            <div class="h-9 w-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/20">
                <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z" />
                </svg>
            </div>
            <span class="text-lg font-bold text-gray-800 dark:text-white tracking-tight">TaxNest</span>
        </a>
        <div class="mt-4 h-px bg-gradient-to-r from-emerald-500/40 via-teal-500/20 to-transparent"></div>
    </div>

    <nav class="flex-1 overflow-y-auto sidebar-scroll px-3 py-3 space-y-0.5">
        <a href="{{ route('dashboard') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('dashboard') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Dashboard
        </a>

        @if(auth()->user()->role !== 'super_admin' || auth()->user()->company_id)
        <div class="pt-4 pb-1 px-4">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-gradient-to-r from-emerald-400 to-teal-500"></span>Business</p>
        </div>

        <a href="/invoices" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('invoices*') || request()->is('invoice*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Invoices
        </a>

        <a href="/products" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('products*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            Products
        </a>

        <a href="/customer-profiles" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('customer-profiles*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Customers
        </a>

        <a href="/customers" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('customers*') && !request()->is('customer-profiles*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            Ledger
        </a>

        <a href="/billing/plans" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('billing*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Billing
        </a>

        <div class="pt-4 pb-1 px-4">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-gradient-to-r from-sky-400 to-indigo-500"></span>Reports</p>
        </div>

        <a href="/mis" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('mis*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            MIS Reports
        </a>

        <a href="/reports/wht" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('reports/wht*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            WHT Report
        </a>

        <a href="/reports/tax-summary" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('reports/tax-summary*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 14v6m-3-3h6M6 10h2a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2zm10 0h2a2 2 0 002-2V6a2 2 0 00-2-2h-2a2 2 0 00-2 2v2a2 2 0 002 2zM6 20h2a2 2 0 002-2v-2a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2z"/></svg>
            Tax Summary
        </a>

        @if(auth()->user()->role === 'company_admin')
        <div class="pt-4 pb-1 px-4">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-gradient-to-r from-amber-400 to-orange-500"></span>Management</p>
        </div>

        <a href="/branches" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('branches*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            Branches
        </a>

        <a href="/company/users" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('company/users*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            Team
        </a>

        <a href="/tax-overrides" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('tax-overrides*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
            Tax Rules
        </a>

        <a href="/company/fbr-settings" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('company/fbr*') || request()->is('company/profile*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Settings
        </a>
        @endif
        @endif

        @if(auth()->user()->role === 'super_admin')
        <div class="pt-4 pb-1 px-4">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-gradient-to-r from-purple-400 to-pink-500"></span>Admin</p>
        </div>

        <a href="/admin/dashboard" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('admin/dashboard*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/></svg>
            Admin Panel
        </a>

        <a href="/admin/companies" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('admin/companies*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            Companies
        </a>

        <a href="/admin/users" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('admin/users*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            Users
        </a>

        <a href="/admin/hs-master" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('admin/hs-master*') || request()->is('admin/hs_master*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
            HS Master
        </a>

        <a href="/admin/system-health" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('admin/system-health*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
            System Health
        </a>

        <a href="/admin/anomalies" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('admin/anomalies*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            Anomalies
        </a>
        @endif
    </nav>

    <div class="flex-shrink-0 p-3">
        <div class="rounded-xl bg-white/60 dark:bg-gray-800/60 backdrop-blur-sm border border-gray-200/30 dark:border-gray-700/30 p-1" style="background-image: linear-gradient(135deg, rgba(16,185,129,0.03), rgba(20,184,166,0.03));">
            <a href="{{ route('profile.edit') }}" class="sidebar-link flex items-center gap-3 py-2.5 px-3 rounded-lg text-sm text-gray-600 dark:text-gray-400">
                <span class="w-8 h-8 rounded-full bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center text-white text-xs font-bold flex-shrink-0 shadow-sm shadow-emerald-500/20">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ Auth::user()->name }}</p>
                    <p class="text-xs text-gray-400 truncate">{{ Auth::user()->email }}</p>
                </div>
            </a>
        </div>
    </div>
</div>