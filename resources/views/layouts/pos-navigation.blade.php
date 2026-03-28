<div class="p-4 border-b border-gray-200 dark:border-gray-800">
    <a href="/pos/dashboard" class="flex items-center space-x-2">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center">
            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        </div>
        <span class="text-lg font-bold text-gray-900 dark:text-white">NestPOS</span>
    </a>
</div>

<div class="px-2 py-4 space-y-1">
    <a href="{{ route('pos.dashboard') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.dashboard') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
        Dashboard
    </a>

    <a href="{{ route('pos.invoice.create') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.invoice.create') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v16m8-8H4"/></svg>
        New Sale
    </a>

    <a href="{{ route('pos.transactions') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.transactions') || request()->routeIs('pos.transaction.show') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        Transactions
    </a>

    <a href="{{ route('pos.reports') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.reports') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
        Reports
    </a>

    <a href="{{ route('pos.tax-reports') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.tax-reports') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
        Tax Reports
    </a>

    <a href="{{ route('pos.day-close') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.day-close') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Day Close (Z-Report)
    </a>

    @php
        $inventoryEnabled = \App\Models\Company::find(app('currentCompanyId'))?->inventory_enabled ?? false;
        $posUserNav = auth('pos')->user();
        $isCashierNav = $posUserNav && $posUserNav->isPosCashier();
    @endphp
    @if($inventoryEnabled && !$isCashierNav)
    <div class="pt-4 pb-1 px-4">
        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">Inventory</p>
    </div>

    <a href="{{ route('pos.inventory.dashboard') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.inventory.dashboard') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
        Inventory Dashboard
    </a>

    <a href="{{ route('pos.inventory.stock') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.inventory.stock') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
        Stock
    </a>

    <a href="{{ route('pos.inventory.movements') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.inventory.movements') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
        Stock Movements
    </a>

    <a href="{{ route('pos.inventory.low-stock') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.inventory.low-stock') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
        Low Stock Alerts
    </a>
    @endif

    @php $posUser = auth('pos')->user(); $isCashier = $posUser && $posUser->isPosCashier(); @endphp

    @if(!$isCashierNav)
    <div class="pt-4 pb-1 px-4">
        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">Restaurant</p>
    </div>

    <a href="{{ route('pos.restaurant.pos') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.restaurant.pos') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3h18v18H3V3zm3 6h12m-12 6h12"/></svg>
        Restaurant POS
    </a>

    <a href="{{ route('pos.restaurant.tables') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.restaurant.tables') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h16M4 18h16"/></svg>
        Tables
    </a>

    <a href="{{ route('pos.restaurant.kds') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.restaurant.kds') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        Kitchen Display
    </a>

    <a href="{{ route('pos.restaurant.table-management') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.restaurant.table-management') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        Table Setup
    </a>

    <a href="{{ route('pos.restaurant.ingredients') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.restaurant.ingredients') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
        Ingredients
    </a>

    <a href="{{ route('pos.restaurant.recipes') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.restaurant.recipes') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
        Recipes (BOM)
    </a>
    @endif

    <div class="pt-4 pb-1 px-4">
        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">Manage</p>
    </div>

    @if(!$isCashier)
    <a href="{{ route('pos.services') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.services') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
        Services
    </a>

    <a href="{{ route('pos.terminals') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.terminals') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        Terminals
    </a>
    @endif

    <a href="{{ route('pos.products') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.products') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
        Products
    </a>

    <a href="{{ route('pos.customers') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.customers') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        Customers
    </a>

    @if(!$isCashier)
    <a href="{{ route('pos.team') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.team') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        Team
    </a>
    @endif

    <div class="pt-4 pb-1 px-4">
        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">Settings</p>
    </div>

    @if(!$isCashier)
    <a href="{{ route('pos.business-profile') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.business-profile') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
        Business Profile
    </a>
    @endif

    <a href="{{ route('pos.user-profile') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.user-profile') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
        My Profile
    </a>

    @if(!$isCashier)
    <a href="{{ route('pos.pra-settings') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.pra-settings') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        PRA Settings
    </a>

    <a href="{{ route('pos.billing') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.billing') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
        Billing
    </a>
    @endif
</div>
