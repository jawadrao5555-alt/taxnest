<div class="flex flex-col h-full" x-data="{
    sections: JSON.parse(localStorage.getItem('sidebarSections') || '{}'),
    toggle(name) {
        this.sections[name] = !this.sections[name];
        localStorage.setItem('sidebarSections', JSON.stringify(this.sections));
    },
    isOpen(name) {
        return this.sections[name] !== false;
    }
}">
    <div class="flex-shrink-0 px-5 pt-5 pb-4">
        <a href="{{ route('dashboard') }}" class="flex items-center space-x-2.5">
            <div class="h-9 w-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/20">
                <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z" />
                </svg>
            </div>
            <span class="text-lg font-bold text-gray-800 dark:text-white tracking-tight">TaxNest</span>
        </a>
        <div class="mt-4 h-px bg-gray-200 dark:bg-gray-700"></div>
    </div>

    <nav class="flex-1 overflow-y-auto sidebar-scroll px-3 py-3 space-y-0.5">
        <a href="{{ route('dashboard') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('dashboard') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Dashboard
        </a>

        @if(auth()->user()->role !== 'super_admin' || auth()->user()->company_id)
        <div class="pt-4 pb-1 px-4 cursor-pointer select-none" @click="toggle('business')">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 flex items-center justify-between">
                <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-gradient-to-r from-emerald-400 to-teal-500"></span>Business</span>
                <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="isOpen('business') ? 'rotate-0' : '-rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </p>
        </div>

        <div x-show="isOpen('business')" x-collapse>
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

        <a href="/wht-management" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('wht-management*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            WHT Manager
        </a>

        <a href="/billing/plans" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('billing*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Billing
        </a>
        </div>

        @if(auth()->user()->company && auth()->user()->company->inventory_enabled)
        <div class="pt-4 pb-1 px-4 cursor-pointer select-none" @click="toggle('inventory')">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 flex items-center justify-between">
                <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-gradient-to-r from-cyan-400 to-blue-500"></span>Inventory</span>
                <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="isOpen('inventory') ? 'rotate-0' : '-rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </p>
        </div>

        <div x-show="isOpen('inventory')" x-collapse>
        <a href="/inventory" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('inventory') || request()->is('inventory/product*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
            Stock
        </a>

        <a href="/inventory/movements" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('inventory/movements*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
            Movements
        </a>

        <a href="/suppliers" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('suppliers*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Suppliers
        </a>

        <a href="/purchase-orders" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('purchase-orders*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
            Purchase Orders
        </a>
        </div>
        @endif

        <div class="pt-4 pb-1 px-4 cursor-pointer select-none" @click="toggle('pos')">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 flex items-center justify-between">
                <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-gradient-to-r from-rose-400 to-pink-500"></span>POS</span>
                <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="isOpen('pos') ? 'rotate-0' : '-rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </p>
        </div>

        <div x-show="isOpen('pos')" x-collapse>
        <a href="{{ route('pos.dashboard') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.dashboard') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            POS Dashboard
        </a>

        <a href="{{ route('pos.invoice.create') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.invoice.create') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v16m8-8H4"/></svg>
            Create Invoice
        </a>

        <a href="{{ route('pos.transactions') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.transactions') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            Transactions
        </a>

        <a href="{{ route('pos.reports') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.reports') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            POS Reports
        </a>

        <a href="{{ route('pos.services') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.services') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            Services
        </a>

        <a href="{{ route('products.index') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('products.*') && request()->is('pos/*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            Products
        </a>

        <a href="{{ route('customers.index') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('customers.*') && request()->is('pos/*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Customers
        </a>

        <a href="{{ route('pos.pra-settings') }}" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->routeIs('pos.pra-settings') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            PRA Settings
        </a>
        </div>

        <div class="pt-4 pb-1 px-4 cursor-pointer select-none" @click="toggle('reports')">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 flex items-center justify-between">
                <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-gradient-to-r from-sky-400 to-indigo-500"></span>Reports</span>
                <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="isOpen('reports') ? 'rotate-0' : '-rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </p>
        </div>

        <div x-show="isOpen('reports')" x-collapse>
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
        </div>

        @if(auth()->user()->role === 'company_admin')
        <div class="pt-4 pb-1 px-4 cursor-pointer select-none" @click="toggle('management')">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 flex items-center justify-between">
                <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-gradient-to-r from-amber-400 to-orange-500"></span>Management</span>
                <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="isOpen('management') ? 'rotate-0' : '-rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </p>
        </div>

        <div x-show="isOpen('management')" x-collapse>
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

        <a href="/company/fbr-settings" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('company/fbr*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            FBR Settings
        </a>
        </div>
        @endif
        @endif

        @if(auth()->user()->role === 'super_admin')
        <div class="pt-4 pb-1 px-4 cursor-pointer select-none" @click="toggle('admin')">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 flex items-center justify-between">
                <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-gradient-to-r from-purple-400 to-pink-500"></span>Admin</span>
                <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="isOpen('admin') ? 'rotate-0' : '-rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </p>
        </div>

        <div x-show="isOpen('admin')" x-collapse>
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

        <a href="/admin/announcements" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('admin/announcements*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
            Announcements
        </a>

        <a href="/admin/invoice-override" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('admin/invoice-override*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            Invoice Override
        </a>

        <a href="/admin/hs-master" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('admin/hs-master*') || request()->is('admin/hs_master*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
            HS Master
        </a>

        <a href="/admin/hs-mapping-engine" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('admin/hs-mapping-engine*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg>
            HS Mapping Engine
        </a>

        <a href="/admin/system-health" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('admin/system-health*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
            System Health
        </a>

        <a href="/admin/anomalies" class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm {{ request()->is('admin/anomalies*') ? 'active text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            Anomalies
        </a>
        </div>
        @endif
    </nav>

    <div class="flex-shrink-0 p-3">
        <div class="rounded-xl bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-1 space-y-1">
            <a href="/profile" class="sidebar-link flex items-center gap-3 py-2.5 px-3 rounded-lg text-sm {{ request()->is('profile*') ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
                <span class="w-8 h-8 rounded-full bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center text-white text-xs font-bold flex-shrink-0 shadow-sm shadow-emerald-500/20">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ Auth::user()->name }}</p>
                    <p class="text-xs text-gray-400 truncate">Profile</p>
                </div>
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full flex items-center gap-3 py-2 px-3 rounded-lg text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    Logout
                </button>
            </form>
        </div>
    </div>
</div>
