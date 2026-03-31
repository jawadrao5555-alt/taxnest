<style>
@keyframes slideUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
@keyframes countUp { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
.slide-up { animation: slideUp 0.4s ease-out forwards; }
.slide-up-1 { animation-delay: 0ms; } .slide-up-2 { animation-delay: 60ms; } .slide-up-3 { animation-delay: 120ms; } .slide-up-4 { animation-delay: 180ms; } .slide-up-5 { animation-delay: 240ms; }
.count-up { animation: countUp 0.5s ease-out forwards; }
.stat-card { position: relative; overflow: hidden; border-radius: 16px; }
.stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; opacity: 0.03; background: repeating-linear-gradient(45deg, transparent, transparent 8px, currentColor 8px, currentColor 9px); }
.stat-card:hover { transform: translateY(-2px); transition: transform 0.2s ease; }
.glass-card { background: rgba(255,255,255,0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.4); }
.dark .glass-card { background: rgba(17,24,39,0.7); border: 1px solid rgba(255,255,255,0.05); }
.progress-bar { height: 3px; border-radius: 2px; overflow: hidden; }
.progress-fill { height: 100%; border-radius: 2px; transition: width 1.2s ease-out; }
.table-row-hover:hover { background: linear-gradient(90deg, rgba(124,58,237,0.02) 0%, transparent 100%); }
.dark .table-row-hover:hover { background: linear-gradient(90deg, rgba(124,58,237,0.06) 0%, transparent 100%); }
.tile-card { transition: all 0.2s ease; border-radius: 14px; }
.tile-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px -5px rgba(0,0,0,0.1), 0 4px 10px -5px rgba(0,0,0,0.05); }
.tile-icon { transition: all 0.2s ease; }
.tile-card:hover .tile-icon { transform: scale(1.1); }
.r-stat { position: relative; overflow: hidden; border-radius: 14px; }
.r-stat::before { content: ''; position: absolute; top: -40%; right: -40%; width: 80%; height: 80%; background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); pointer-events: none; }
.stat-val { font-variant-numeric: tabular-nums; letter-spacing: -0.02em; }
.r-progress { transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1); }
.r-glass { background: rgba(255,255,255,0.8); backdrop-filter: blur(12px); border: 1px solid rgba(0,0,0,0.05); border-radius: 14px; }
.dark .r-glass { background: rgba(17,24,39,0.8); border: 1px solid rgba(255,255,255,0.06); }
.r-row:hover { background: rgba(124,58,237,0.02); }
.dark .r-row:hover { background: rgba(124,58,237,0.06); }
</style>

<div class="space-y-5 w-full">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 slide-up slide-up-1">
        <div>
            <h1 class="text-xl font-extrabold text-gray-900 dark:text-white tracking-tight">Welcome Back<span class="text-purple-500">.</span></h1>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ now()->format('l, d M Y') }} — {{ $company->name ?? 'Business' }}</p>
        </div>
        @include('pos.dashboard-styles._style-picker')
    </div>

    @if($isRestaurant)
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 slide-up slide-up-2">
        <a href="{{ route('pos.restaurant.pos') }}" class="tile-card glass-card p-4 text-center group cursor-pointer">
            <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center mb-2.5 shadow-lg shadow-purple-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            </div>
            <p class="text-[11px] font-bold text-gray-900 dark:text-white">POS Screen</p>
            <p class="text-[9px] text-gray-400 mt-0.5">Start selling</p>
        </a>
        <a href="{{ route('pos.transactions') }}" class="tile-card glass-card p-4 text-center group cursor-pointer">
            <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center mb-2.5 shadow-lg shadow-blue-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <p class="text-[11px] font-bold text-gray-900 dark:text-white">Orders</p>
            <p class="text-[9px] text-gray-400 mt-0.5">View history</p>
        </a>
        <a href="{{ route('pos.restaurant.tables') }}" class="tile-card glass-card p-4 text-center group cursor-pointer">
            <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center mb-2.5 shadow-lg shadow-amber-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </div>
            <p class="text-[11px] font-bold text-gray-900 dark:text-white">Tables</p>
            <p class="text-[9px] text-gray-400 mt-0.5">{{ $occupiedTables ?? 0 }}/{{ $totalTables ?? 0 }} occupied</p>
        </a>
        <a href="{{ route('pos.restaurant.kds') }}" class="tile-card glass-card p-4 text-center group cursor-pointer">
            <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-orange-500 to-red-500 flex items-center justify-center mb-2.5 shadow-lg shadow-orange-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </div>
            <p class="text-[11px] font-bold text-gray-900 dark:text-white">Kitchen</p>
            <p class="text-[9px] text-gray-400 mt-0.5">KDS display</p>
        </a>
        <a href="{{ route('pos.products') }}" class="tile-card glass-card p-4 text-center group cursor-pointer">
            <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center mb-2.5 shadow-lg shadow-emerald-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <p class="text-[11px] font-bold text-gray-900 dark:text-white">Menu</p>
            <p class="text-[9px] text-gray-400 mt-0.5">Products</p>
        </a>
        <a href="{{ route('pos.restaurant.ingredients') }}" class="tile-card glass-card p-4 text-center group cursor-pointer">
            <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-pink-500 to-rose-600 flex items-center justify-center mb-2.5 shadow-lg shadow-pink-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
            </div>
            <p class="text-[11px] font-bold text-gray-900 dark:text-white">Ingredients</p>
            <p class="text-[9px] text-gray-400 mt-0.5">{{ ($lowStockItems ?? collect())->count() > 0 ? ($lowStockItems ?? collect())->count() . ' low stock' : 'All stocked' }}</p>
        </a>
    </div>
    @else
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 slide-up slide-up-2">
        <a href="{{ route('pos.invoice.create') }}" class="tile-card glass-card p-4 text-center group cursor-pointer">
            <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center mb-2.5 shadow-lg shadow-purple-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4"/></svg>
            </div>
            <p class="text-[11px] font-bold text-gray-900 dark:text-white">New Sale</p>
            <p class="text-[9px] text-gray-400 mt-0.5">Create invoice</p>
        </a>
        <a href="{{ route('pos.transactions') }}" class="tile-card glass-card p-4 text-center group cursor-pointer">
            <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center mb-2.5 shadow-lg shadow-blue-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <p class="text-[11px] font-bold text-gray-900 dark:text-white">Orders</p>
            <p class="text-[9px] text-gray-400 mt-0.5">View history</p>
        </a>
        <a href="{{ route('pos.products') }}" class="tile-card glass-card p-4 text-center group cursor-pointer">
            <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center mb-2.5 shadow-lg shadow-emerald-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <p class="text-[11px] font-bold text-gray-900 dark:text-white">Products</p>
            <p class="text-[9px] text-gray-400 mt-0.5">Manage menu</p>
        </a>
        <a href="{{ route('pos.customers') }}" class="tile-card glass-card p-4 text-center group cursor-pointer">
            <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-pink-500 to-rose-600 flex items-center justify-center mb-2.5 shadow-lg shadow-pink-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <p class="text-[11px] font-bold text-gray-900 dark:text-white">Customers</p>
            <p class="text-[9px] text-gray-400 mt-0.5">Directory</p>
        </a>
        <a href="{{ route('pos.reports') }}" class="tile-card glass-card p-4 text-center group cursor-pointer">
            <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center mb-2.5 shadow-lg shadow-amber-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </div>
            <p class="text-[11px] font-bold text-gray-900 dark:text-white">Reports</p>
            <p class="text-[9px] text-gray-400 mt-0.5">Analytics</p>
        </a>
    </div>
    @endif

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 slide-up slide-up-3">
        <div class="stat-card bg-gradient-to-br from-emerald-500 to-emerald-700 p-4 shadow-lg shadow-emerald-500/15">
            <div class="relative z-10">
                <span class="text-[9px] font-bold uppercase tracking-wider text-emerald-100/70">Today's Revenue</span>
                <p class="text-xl font-extrabold text-white count-up mt-1">Rs. {{ number_format($todaySales ?? $todayStats->revenue ?? 0) }}</p>
                <div class="progress-bar bg-white/10 mt-2"><div class="progress-fill bg-white/40" style="width: {{ min(100, ($monthSales ?? $monthStats->revenue ?? 1) > 0 ? (($todaySales ?? $todayStats->revenue ?? 0) / ($monthSales ?? $monthStats->revenue ?? 1) * 100) : 0) }}%"></div></div>
            </div>
        </div>
        <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-700 p-4 shadow-lg shadow-blue-500/15">
            <div class="relative z-10">
                <span class="text-[9px] font-bold uppercase tracking-wider text-blue-100/70">Orders</span>
                <p class="text-xl font-extrabold text-white count-up mt-1">{{ $todayOrders ?? $todayStats->count ?? 0 }}</p>
                <div class="progress-bar bg-white/10 mt-2"><div class="progress-fill bg-white/40" style="width: {{ min(100, ($todayOrders ?? $todayStats->count ?? 0) * 5) }}%"></div></div>
            </div>
        </div>
        <div class="stat-card bg-gradient-to-br from-purple-500 to-violet-700 p-4 shadow-lg shadow-purple-500/15">
            <div class="relative z-10">
                <span class="text-[9px] font-bold uppercase tracking-wider text-purple-100/70">Avg Order</span>
                <p class="text-xl font-extrabold text-white count-up mt-1">Rs. {{ number_format($todayStats->avg_ticket ?? (($todayOrders ?? 0) > 0 ? ($todaySales ?? 0) / ($todayOrders ?? 1) : 0)) }}</p>
                <div class="progress-bar bg-white/10 mt-2"><div class="progress-fill bg-white/40" style="width: 60%"></div></div>
            </div>
        </div>
        @if($isRestaurant)
        <div class="stat-card bg-gradient-to-br from-amber-500 to-orange-600 p-4 shadow-lg shadow-amber-500/15">
            <div class="relative z-10">
                <span class="text-[9px] font-bold uppercase tracking-wider text-amber-100/70">Tables</span>
                <p class="text-xl font-extrabold text-white count-up mt-1">{{ $occupiedTables ?? 0 }}<span class="text-sm text-white/50">/{{ $totalTables ?? 0 }}</span></p>
                <div class="progress-bar bg-white/10 mt-2"><div class="progress-fill bg-white/40" style="width: {{ ($totalTables ?? 0) > 0 ? round(($occupiedTables ?? 0) / ($totalTables ?? 1) * 100) : 0 }}%"></div></div>
            </div>
        </div>
        @else
        <div class="stat-card bg-gradient-to-br from-amber-500 to-orange-600 p-4 shadow-lg shadow-amber-500/15">
            <div class="relative z-10">
                <span class="text-[9px] font-bold uppercase tracking-wider text-amber-100/70">Monthly</span>
                <p class="text-xl font-extrabold text-white count-up mt-1">Rs. {{ number_format($monthSales ?? $monthStats->revenue ?? 0) }}</p>
                <div class="progress-bar bg-white/10 mt-2"><div class="progress-fill bg-white/40" style="width: 75%"></div></div>
            </div>
        </div>
        @endif
    </div>

    @include('pos.dashboard-styles._common-sections')
</div>
