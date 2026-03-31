<style>
@keyframes lsPop { from { opacity: 0; transform: scale(0.92); } to { opacity: 1; transform: scale(1); } }
.ls-anim { animation: lsPop 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
.ls-d1 { animation-delay: 0ms; } .ls-d2 { animation-delay: 50ms; } .ls-d3 { animation-delay: 100ms; } .ls-d4 { animation-delay: 150ms; } .ls-d5 { animation-delay: 200ms; }
.ls-tile { border-radius: 20px; padding: 20px; transition: all 0.25s ease; cursor: pointer; position: relative; overflow: hidden; }
.ls-tile:hover { transform: translateY(-4px) scale(1.02); }
.ls-tile::after { content: ''; position: absolute; inset: 0; background: linear-gradient(135deg, rgba(255,255,255,0.15) 0%, transparent 60%); pointer-events: none; }
.ls-ring { width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; position: relative; }
.ls-ring::before { content: ''; position: absolute; inset: 0; border-radius: 50%; border: 4px solid rgba(255,255,255,0.15); }
.ls-ring::after { content: ''; position: absolute; inset: 0; border-radius: 50%; border: 4px solid transparent; border-top-color: white; }
.ls-glass { background: rgba(255,255,255,0.85); backdrop-filter: blur(16px); border: 1px solid rgba(0,0,0,0.04); border-radius: 16px; }
.dark .ls-glass { background: rgba(17,24,39,0.85); border-color: rgba(255,255,255,0.06); }
</style>

<div class="space-y-5 w-full">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 ls-anim ls-d1">
        <div>
            <p class="text-[10px] font-bold text-purple-600 dark:text-purple-400 uppercase tracking-widest">Dashboard</p>
            <h1 class="text-xl font-black text-gray-900 dark:text-white mt-0.5">{{ $company->name ?? 'Business' }}</h1>
            <p class="text-[11px] text-gray-400 mt-0.5">{{ now()->format('l, d M Y') }}</p>
        </div>
        @include('pos.dashboard-styles._style-picker')
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 ls-anim ls-d2">
        <div class="ls-tile bg-gradient-to-br from-violet-500 via-purple-600 to-indigo-700 shadow-xl shadow-purple-500/25">
            <div class="relative z-10">
                <div class="ls-ring mx-auto mb-3">
                    <span class="text-white font-black text-sm z-10">Rs</span>
                </div>
                <p class="text-center text-xl font-black text-white">{{ number_format($todaySales ?? $todayStats->revenue ?? 0) }}</p>
                <p class="text-center text-[9px] text-white/60 font-semibold mt-1 uppercase tracking-wider">Revenue Today</p>
            </div>
        </div>
        <div class="ls-tile bg-gradient-to-br from-blue-500 via-blue-600 to-cyan-700 shadow-xl shadow-blue-500/25">
            <div class="relative z-10">
                <div class="ls-ring mx-auto mb-3">
                    <span class="text-white font-black text-lg z-10">{{ $todayOrders ?? $todayStats->count ?? 0 }}</span>
                </div>
                <p class="text-center text-xl font-black text-white">Orders</p>
                <p class="text-center text-[9px] text-white/60 font-semibold mt-1 uppercase tracking-wider">Completed</p>
            </div>
        </div>
        <div class="ls-tile bg-gradient-to-br from-emerald-500 via-emerald-600 to-teal-700 shadow-xl shadow-emerald-500/25">
            <div class="relative z-10">
                <div class="ls-ring mx-auto mb-3">
                    <span class="text-white font-black text-sm z-10">Avg</span>
                </div>
                <p class="text-center text-xl font-black text-white">{{ number_format($todayStats->avg_ticket ?? (($todayOrders ?? 0) > 0 ? ($todaySales ?? 0) / ($todayOrders ?? 1) : 0)) }}</p>
                <p class="text-center text-[9px] text-white/60 font-semibold mt-1 uppercase tracking-wider">Avg Ticket</p>
            </div>
        </div>
        <div class="ls-tile bg-gradient-to-br from-amber-500 via-orange-500 to-red-600 shadow-xl shadow-amber-500/25">
            <div class="relative z-10">
                <div class="ls-ring mx-auto mb-3">
                    @if($isRestaurant)
                    <span class="text-white font-black text-sm z-10">{{ $occupiedTables ?? 0 }}/{{ $totalTables ?? 0 }}</span>
                    @else
                    <span class="text-white font-black text-[10px] z-10">Month</span>
                    @endif
                </div>
                @if($isRestaurant)
                <p class="text-center text-xl font-black text-white">Tables</p>
                <p class="text-center text-[9px] text-white/60 font-semibold mt-1 uppercase tracking-wider">Occupied</p>
                @else
                <p class="text-center text-xl font-black text-white">{{ number_format($monthSales ?? $monthStats->revenue ?? 0) }}</p>
                <p class="text-center text-[9px] text-white/60 font-semibold mt-1 uppercase tracking-wider">Monthly Total</p>
                @endif
            </div>
        </div>
    </div>

    @if($isRestaurant)
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 ls-anim ls-d3">
        @php $navItems = [
            ['route' => 'pos.restaurant.pos', 'icon' => 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z', 'label' => 'POS', 'color' => 'purple'],
            ['route' => 'pos.transactions', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2', 'label' => 'Orders', 'color' => 'blue'],
            ['route' => 'pos.restaurant.tables', 'icon' => 'M4 6h16M4 12h16M4 18h16', 'label' => 'Tables', 'color' => 'amber'],
            ['route' => 'pos.restaurant.kds', 'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z', 'label' => 'Kitchen', 'color' => 'orange'],
            ['route' => 'pos.products', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4', 'label' => 'Menu', 'color' => 'emerald'],
            ['route' => 'pos.restaurant.ingredients', 'icon' => 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z', 'label' => 'Ingredients', 'color' => 'pink'],
        ]; @endphp
        @foreach($navItems as $nav)
        <a href="{{ route($nav['route']) }}" class="ls-glass p-3 text-center group hover:shadow-md transition-all rounded-xl">
            <div class="w-9 h-9 mx-auto rounded-xl bg-{{ $nav['color'] }}-100 dark:bg-{{ $nav['color'] }}-900/20 flex items-center justify-center mb-1.5 group-hover:scale-110 transition-transform">
                <svg class="w-4 h-4 text-{{ $nav['color'] }}-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="{{ $nav['icon'] }}"/></svg>
            </div>
            <p class="text-[10px] font-bold text-gray-700 dark:text-gray-300">{{ $nav['label'] }}</p>
        </a>
        @endforeach
    </div>
    @else
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 ls-anim ls-d3">
        @php $regNav = [
            ['route' => 'pos.invoice.create', 'icon' => 'M12 4v16m8-8H4', 'label' => 'New Sale', 'color' => 'purple'],
            ['route' => 'pos.transactions', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2', 'label' => 'Orders', 'color' => 'blue'],
            ['route' => 'pos.products', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4', 'label' => 'Products', 'color' => 'emerald'],
            ['route' => 'pos.customers', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', 'label' => 'Customers', 'color' => 'pink'],
            ['route' => 'pos.reports', 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z', 'label' => 'Reports', 'color' => 'amber'],
        ]; @endphp
        @foreach($regNav as $nav)
        <a href="{{ route($nav['route']) }}" class="ls-glass p-3 text-center group hover:shadow-md transition-all rounded-xl">
            <div class="w-9 h-9 mx-auto rounded-xl bg-{{ $nav['color'] }}-100 dark:bg-{{ $nav['color'] }}-900/20 flex items-center justify-center mb-1.5 group-hover:scale-110 transition-transform">
                <svg class="w-4 h-4 text-{{ $nav['color'] }}-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="{{ $nav['icon'] }}"/></svg>
            </div>
            <p class="text-[10px] font-bold text-gray-700 dark:text-gray-300">{{ $nav['label'] }}</p>
        </a>
        @endforeach
    </div>
    @endif

    @include('pos.dashboard-styles._common-sections')
</div>
