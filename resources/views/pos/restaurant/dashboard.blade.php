<x-pos-layout>
<style>
.r-stat { position: relative; overflow: hidden; border-radius: 14px; }
.r-stat::before { content: ''; position: absolute; top: -40%; right: -40%; width: 80%; height: 80%; background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); pointer-events: none; }
.r-glass { background: rgba(255,255,255,0.8); backdrop-filter: blur(12px); border: 1px solid rgba(0,0,0,0.05); border-radius: 14px; }
.dark .r-glass { background: rgba(17,24,39,0.8); border: 1px solid rgba(255,255,255,0.06); }
.stat-val { font-variant-numeric: tabular-nums; letter-spacing: -0.02em; }
.r-progress { transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1); }
@keyframes rSlide { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.r-anim { animation: rSlide 0.3s ease forwards; }
.r-d1 { animation-delay: 0ms; }
.r-d2 { animation-delay: 60ms; }
.r-d3 { animation-delay: 120ms; }
.r-d4 { animation-delay: 180ms; }
.r-d5 { animation-delay: 240ms; }
.r-d6 { animation-delay: 300ms; }
.r-row:hover { background: rgba(124,58,237,0.02); }
.dark .r-row:hover { background: rgba(124,58,237,0.06); }
.tile-card { transition: all 0.2s ease; }
.tile-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px -5px rgba(0,0,0,0.1), 0 4px 10px -5px rgba(0,0,0,0.05); }
.tile-icon { transition: all 0.2s ease; }
.tile-card:hover .tile-icon { transform: scale(1.1); }
</style>

@php
    $posUser = auth('pos')->user();
    $isAdmin = $posUser && $posUser->pos_role === 'pos_admin';
@endphp

<div class="w-full overflow-x-hidden" x-data="rDash()" x-init="init()">
    <div class="space-y-5 w-full">

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 r-anim r-d1">
            <div>
                <h1 class="text-xl font-extrabold text-gray-900 dark:text-white tracking-tight">Welcome Back<span class="text-purple-500">.</span></h1>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ now()->format('l, d M Y') }} — {{ $company->name ?? 'Restaurant' }}</p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <button @click="refreshDashboard()" class="p-2 rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-purple-600 transition shadow-sm" title="Refresh">
                    <svg class="w-4 h-4" :class="refreshing ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 r-anim r-d2">
            <a href="{{ route('pos.restaurant.pos') }}" class="tile-card r-glass p-4 text-center group cursor-pointer">
                <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center mb-2.5 shadow-lg shadow-purple-500/20">
                    <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                </div>
                <p class="text-[11px] font-bold text-gray-900 dark:text-white">POS Screen</p>
                <p class="text-[9px] text-gray-400 mt-0.5">Start selling</p>
            </a>

            <a href="{{ route('pos.transactions') }}" class="tile-card r-glass p-4 text-center group cursor-pointer">
                <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center mb-2.5 shadow-lg shadow-blue-500/20">
                    <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                </div>
                <p class="text-[11px] font-bold text-gray-900 dark:text-white">Orders</p>
                <p class="text-[9px] text-gray-400 mt-0.5">View history</p>
            </a>

            <a href="{{ route('pos.restaurant.tables') }}" class="tile-card r-glass p-4 text-center group cursor-pointer">
                <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center mb-2.5 shadow-lg shadow-amber-500/20">
                    <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </div>
                <p class="text-[11px] font-bold text-gray-900 dark:text-white">Tables</p>
                <p class="text-[9px] text-gray-400 mt-0.5">{{ $occupiedTables }}/{{ $totalTables }} occupied</p>
            </a>

            <a href="{{ route('pos.restaurant.kds') }}" class="tile-card r-glass p-4 text-center group cursor-pointer">
                <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-orange-500 to-red-500 flex items-center justify-center mb-2.5 shadow-lg shadow-orange-500/20">
                    <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </div>
                <p class="text-[11px] font-bold text-gray-900 dark:text-white">Kitchen</p>
                <p class="text-[9px] text-gray-400 mt-0.5">KDS display</p>
            </a>

            <a href="{{ route('pos.products') }}" class="tile-card r-glass p-4 text-center group cursor-pointer">
                <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center mb-2.5 shadow-lg shadow-emerald-500/20">
                    <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
                <p class="text-[11px] font-bold text-gray-900 dark:text-white">Menu</p>
                <p class="text-[9px] text-gray-400 mt-0.5">Products</p>
            </a>

            <a href="{{ route('pos.restaurant.ingredients') }}" class="tile-card r-glass p-4 text-center group cursor-pointer">
                <div class="tile-icon w-12 h-12 mx-auto rounded-2xl bg-gradient-to-br from-pink-500 to-rose-600 flex items-center justify-center mb-2.5 shadow-lg shadow-pink-500/20">
                    <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                </div>
                <p class="text-[11px] font-bold text-gray-900 dark:text-white">Ingredients</p>
                <p class="text-[9px] text-gray-400 mt-0.5">{{ $lowStockItems->count() > 0 ? $lowStockItems->count() . ' low stock' : 'All stocked' }}</p>
            </a>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 r-anim r-d3">
            <div class="r-stat bg-gradient-to-br from-emerald-500 to-emerald-700 p-3.5 shadow-md">
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-[8px] font-bold uppercase tracking-wider text-emerald-100/80">Today's Revenue</span>
                        @if($yesterdaySales > 0)
                        @php $changePercent = round(($todaySales - $yesterdaySales) / $yesterdaySales * 100); @endphp
                        <span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full bg-white/15 text-white">{{ $changePercent >= 0 ? '+' : '' }}{{ $changePercent }}%</span>
                        @endif
                    </div>
                    <p class="text-lg font-extrabold text-white stat-val leading-tight">Rs. {{ number_format($todaySales) }}</p>
                    <div class="mt-1.5 w-full bg-white/10 rounded-full h-1">
                        <div class="bg-white/40 h-1 rounded-full r-progress" style="width: {{ min(100, $yesterdaySales > 0 ? round($todaySales / $yesterdaySales * 100) : 50) }}%"></div>
                    </div>
                    <p class="text-[7px] text-emerald-200/50 mt-0.5">vs yesterday</p>
                </div>
            </div>

            <div class="r-stat bg-gradient-to-br from-blue-500 to-indigo-700 p-3.5 shadow-md">
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-[8px] font-bold uppercase tracking-wider text-blue-100/80">Total Orders</span>
                        <span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full bg-white/15 text-amber-200">{{ $heldCount }} held</span>
                    </div>
                    <p class="text-lg font-extrabold text-white stat-val leading-tight">{{ $todayOrders }}</p>
                    <div class="mt-1.5 w-full bg-white/10 rounded-full h-1">
                        <div class="bg-white/40 h-1 rounded-full r-progress" style="width: {{ min(100, $todayOrders * 5) }}%"></div>
                    </div>
                    <p class="text-[7px] text-blue-200/50 mt-0.5">today's count</p>
                </div>
            </div>

            <div class="r-stat bg-gradient-to-br from-purple-500 to-violet-700 p-3.5 shadow-md">
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-[8px] font-bold uppercase tracking-wider text-purple-100/80">Avg. Order</span>
                        @if($peakHour)
                        <span class="text-[8px] font-medium px-1.5 py-0.5 rounded-full bg-white/15 text-purple-200">Peak: {{ $peakHour }}</span>
                        @endif
                    </div>
                    <p class="text-lg font-extrabold text-white stat-val leading-tight">Rs. {{ $todayOrders > 0 ? number_format($todaySales / $todayOrders) : 0 }}</p>
                    <div class="mt-1.5 w-full bg-white/10 rounded-full h-1">
                        <div class="bg-white/40 h-1 rounded-full r-progress" style="width: 60%"></div>
                    </div>
                    <p class="text-[7px] text-purple-200/50 mt-0.5">per transaction</p>
                </div>
            </div>

            <div class="r-stat bg-gradient-to-br from-amber-500 to-orange-600 p-3.5 shadow-md">
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-[8px] font-bold uppercase tracking-wider text-amber-100/80">Tables</span>
                    </div>
                    <p class="text-lg font-extrabold text-white stat-val leading-tight">{{ $occupiedTables }}<span class="text-sm text-white/50 font-medium">/{{ $totalTables }}</span></p>
                    <div class="mt-1.5 w-full bg-white/10 rounded-full h-1">
                        <div class="bg-white/40 h-1 rounded-full r-progress" style="width: {{ $totalTables > 0 ? round($occupiedTables / $totalTables * 100) : 0 }}%"></div>
                    </div>
                    <p class="text-[7px] text-amber-200/50 mt-0.5">occupied now</p>
                </div>
            </div>
        </div>

        @if($isAdmin)
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 r-anim r-d4">
            <div class="r-glass p-3.5 border-l-4 border-l-emerald-500 !rounded-l-none">
                <div class="flex items-center justify-between">
                    <div class="min-w-0">
                        <p class="text-[8px] text-gray-400 font-bold uppercase tracking-wider">Gross Profit</p>
                        <p class="text-base font-extrabold stat-val mt-0.5 {{ ($todayProfit ?? 0) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600' }}">Rs. {{ number_format($todayProfit ?? 0) }}</p>
                    </div>
                    <div class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                </div>
            </div>
            <div class="r-glass p-3.5">
                <div class="flex items-center justify-between">
                    <div class="min-w-0">
                        <p class="text-[8px] text-gray-400 font-bold uppercase tracking-wider">Total Cost</p>
                        <p class="text-base font-extrabold text-gray-900 dark:text-white stat-val mt-0.5">Rs. {{ number_format($todayCost ?? 0) }}</p>
                    </div>
                    <div class="w-8 h-8 rounded-lg bg-gray-50 dark:bg-gray-800 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg>
                    </div>
                </div>
            </div>
            <div class="r-glass p-3.5">
                <div class="flex items-center justify-between">
                    <div class="min-w-0">
                        <p class="text-[8px] text-gray-400 font-bold uppercase tracking-wider">Margin</p>
                        <p class="text-base font-extrabold stat-val mt-0.5 {{ ($todayProfit ?? 0) >= 0 ? 'text-indigo-600 dark:text-indigo-400' : 'text-red-600' }}">{{ $todaySales > 0 ? round(($todayProfit ?? 0) / $todaySales * 100) : 0 }}%</p>
                    </div>
                    <div class="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-900/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/></svg>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 r-anim r-d4">
            <div class="r-glass p-3 flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-cyan-50 dark:bg-cyan-900/20 flex items-center justify-center flex-shrink-0">
                    <svg class="w-3.5 h-3.5 text-cyan-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[7px] text-gray-400 font-bold uppercase tracking-wider">Tax Collected</p>
                    <p class="text-xs font-extrabold text-gray-900 dark:text-white stat-val">Rs. {{ number_format($todayTax) }}</p>
                </div>
            </div>
            <div class="r-glass p-3 flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-orange-50 dark:bg-orange-900/20 flex items-center justify-center flex-shrink-0">
                    <svg class="w-3.5 h-3.5 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[7px] text-gray-400 font-bold uppercase tracking-wider">Discounts</p>
                    <p class="text-xs font-extrabold text-orange-600 stat-val">Rs. {{ number_format($todayDiscount) }}</p>
                </div>
            </div>
            <div class="r-glass p-3 flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-green-50 dark:bg-green-900/20 flex items-center justify-center flex-shrink-0">
                    <svg class="w-3.5 h-3.5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[7px] text-gray-400 font-bold uppercase tracking-wider">Completed</p>
                    <p class="text-xs font-extrabold text-green-600 stat-val">{{ $completedCount }}</p>
                </div>
            </div>
            <div class="r-glass p-3 flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg {{ $lowStockItems->count() > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20' }} flex items-center justify-center flex-shrink-0">
                    <svg class="w-3.5 h-3.5 {{ $lowStockItems->count() > 0 ? 'text-red-600' : 'text-green-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[7px] text-gray-400 font-bold uppercase tracking-wider">Low Stock</p>
                    <p class="text-xs font-extrabold {{ $lowStockItems->count() > 0 ? 'text-red-600' : 'text-green-600' }} stat-val">{{ $lowStockItems->count() }}</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 r-anim r-d5">
            <div class="lg:col-span-2 r-glass p-4">
                <div class="flex items-center gap-2 mb-3">
                    <span class="w-1 h-3.5 rounded-full bg-purple-600"></span>
                    <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide">Revenue — Last 7 Days</h2>
                </div>
                <div style="height: 170px;"><canvas id="salesChart"></canvas></div>
            </div>

            <div class="r-glass p-4">
                <div class="flex items-center gap-2 mb-3">
                    <span class="w-1 h-3.5 rounded-full bg-blue-600"></span>
                    <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide">Order Types</h2>
                </div>
                <div style="height: 170px;"><canvas id="orderTypeChart"></canvas></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 r-anim r-d6">
            <div class="r-glass overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800/50 flex items-center gap-2">
                    <span class="w-1 h-3.5 rounded-full bg-amber-500"></span>
                    <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide">Top Selling Items</h2>
                </div>
                <div class="p-2.5 space-y-0.5">
                    @forelse($topProducts->take(5) as $idx => $p)
                    <div class="flex items-center gap-2 py-1.5 px-2 rounded-lg r-row transition">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center text-[9px] font-extrabold flex-shrink-0 {{ $idx < 3 ? 'bg-gradient-to-br from-purple-500 to-violet-600 text-white' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' }}">{{ $idx + 1 }}</span>
                        <div class="flex-1 min-w-0">
                            <p class="text-[11px] font-semibold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p>
                        </div>
                        <span class="text-[9px] text-gray-400 bg-gray-50 dark:bg-gray-800 px-1.5 py-0.5 rounded font-mono flex-shrink-0">{{ $p->total_qty }}x</span>
                        <span class="text-[11px] font-bold text-gray-900 dark:text-white stat-val flex-shrink-0">Rs. {{ number_format($p->total_revenue) }}</span>
                    </div>
                    @empty
                    <p class="text-[11px] text-gray-400 py-6 text-center">No sales data yet</p>
                    @endforelse
                </div>
            </div>

            <div class="r-glass overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800/50 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-1 h-3.5 rounded-full {{ $lowStockItems->count() > 0 ? 'bg-red-500' : 'bg-green-500' }}"></span>
                        <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide">Stock Alerts</h2>
                    </div>
                    @if($lowStockItems->count() > 0)
                    <span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">{{ $lowStockItems->count() }} items</span>
                    @endif
                </div>
                <div class="p-2.5 space-y-0.5">
                    @forelse($lowStockItems->take(5) as $ing)
                    <div class="flex items-center gap-2 py-1.5 px-2 rounded-lg r-row transition">
                        <span class="w-2 h-2 rounded-full flex-shrink-0 {{ $ing->current_stock <= 0 ? 'bg-red-500' : 'bg-amber-500' }}"></span>
                        <div class="flex-1 min-w-0">
                            <p class="text-[11px] font-semibold text-gray-900 dark:text-white truncate">{{ $ing->name }}</p>
                        </div>
                        <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-md flex-shrink-0 {{ $ing->current_stock <= 0 ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' }}">
                            {{ number_format($ing->current_stock, 1) }} {{ $ing->unit }}
                        </span>
                    </div>
                    @empty
                    <div class="text-center py-6">
                        <svg class="w-7 h-7 mx-auto text-green-400 mb-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p class="text-[11px] text-green-600 font-semibold">All ingredients in stock</p>
                    </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="r-glass overflow-hidden r-anim r-d6">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800/50 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="w-1 h-3.5 rounded-full bg-indigo-500"></span>
                    <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide">Recent Transactions</h2>
                </div>
                <a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-purple-600 hover:text-purple-700 dark:text-purple-400 transition">VIEW ALL</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-gray-50/60 dark:bg-gray-800/30">
                            <th class="text-left text-[9px] text-gray-400 font-bold uppercase tracking-wider py-2 px-4">Order #</th>
                            <th class="text-left text-[9px] text-gray-400 font-bold uppercase tracking-wider py-2 px-3 hidden sm:table-cell">Type</th>
                            <th class="text-left text-[9px] text-gray-400 font-bold uppercase tracking-wider py-2 px-3">Table</th>
                            <th class="text-right text-[9px] text-gray-400 font-bold uppercase tracking-wider py-2 px-3">Amount</th>
                            <th class="text-center text-[9px] text-gray-400 font-bold uppercase tracking-wider py-2 px-3">Status</th>
                            <th class="text-right text-[9px] text-gray-400 font-bold uppercase tracking-wider py-2 px-4 hidden lg:table-cell">Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentOrders->take(8) as $ro)
                        <tr class="border-b border-gray-50 dark:border-gray-800/40 r-row transition-colors">
                            <td class="py-2 px-4 text-[11px] font-bold text-gray-900 dark:text-white">{{ $ro->order_number }}</td>
                            <td class="py-2 px-3 hidden sm:table-cell"><span class="text-[9px] px-1.5 py-0.5 rounded bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 capitalize font-bold">{{ str_replace('_', ' ', $ro->order_type) }}</span></td>
                            <td class="py-2 px-3 text-[11px] text-gray-500 dark:text-gray-400">{{ $ro->table ? 'T-' . $ro->table->table_number : '—' }}</td>
                            <td class="py-2 px-3 text-right text-[11px] font-bold text-gray-900 dark:text-white stat-val">Rs. {{ number_format($ro->total_amount) }}</td>
                            <td class="py-2 px-3 text-center">
                                <span class="text-[8px] px-1.5 py-0.5 rounded font-bold uppercase inline-block
                                    {{ $ro->status === 'completed' ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400' : '' }}
                                    {{ $ro->status === 'held' ? 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400' : '' }}
                                    {{ $ro->status === 'preparing' ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400' : '' }}
                                    {{ $ro->status === 'ready' ? 'bg-purple-50 text-purple-700 dark:bg-purple-900/20 dark:text-purple-400' : '' }}
                                    {{ $ro->status === 'cancelled' ? 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400' : '' }}
                                ">{{ ucfirst($ro->status) }}</span>
                            </td>
                            <td class="py-2 px-4 text-right text-[9px] text-gray-400 hidden lg:table-cell">{{ $ro->created_at->diffForHumans() }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="py-8 text-center text-[11px] text-gray-400">No orders today</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($isAdmin)
        <div x-data="{ showSettings: false, mgrPin: '', cashierLimit: {{ $company->cashier_discount_limit ?? 10 }}, managerLimit: {{ $company->manager_discount_limit ?? 50 }}, saving: false, saved: false }" class="r-glass overflow-hidden r-anim r-d6">
            <button @click="showSettings = !showSettings" class="flex items-center justify-between w-full px-4 py-3 hover:bg-gray-50/50 dark:hover:bg-gray-800/20 transition">
                <div class="flex items-center gap-2">
                    <span class="w-1 h-3.5 rounded-full bg-gray-400"></span>
                    <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide">Role & Discount Settings</h2>
                </div>
                <svg class="w-3.5 h-3.5 text-gray-400 transition-transform duration-200" :class="showSettings ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="showSettings" x-transition class="px-4 pb-4 space-y-3 border-t border-gray-100 dark:border-gray-800/50 pt-3">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="text-[8px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Cashier Discount Limit (%)</label>
                        <input type="number" x-model.number="cashierLimit" min="0" max="100" class="w-full text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition">
                    </div>
                    <div>
                        <label class="text-[8px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Manager Discount Limit (%)</label>
                        <input type="number" x-model.number="managerLimit" min="0" max="100" class="w-full text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition">
                    </div>
                    <div>
                        <label class="text-[8px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Manager Override PIN</label>
                        <input type="password" x-model="mgrPin" maxlength="6" placeholder="{{ $company->manager_override_pin ? '******' : 'Set 4-6 digit PIN' }}" class="w-full text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition">
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button @click="async function() { saving = true; saved = false; try { const res = await fetch('{{ route('pos.restaurant.save-manager-pin') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ pin: mgrPin || undefined, cashier_discount_limit: cashierLimit, manager_discount_limit: managerLimit }) }); const d = await res.json(); if (d.success) { saved = true; mgrPin = ''; setTimeout(() => saved = false, 3000); } } catch(e) {} saving = false; }()" :disabled="saving" class="px-4 py-2 text-[11px] font-bold text-white bg-gradient-to-r from-purple-600 to-violet-600 rounded-lg hover:from-purple-700 hover:to-violet-700 disabled:opacity-50 shadow-sm shadow-purple-600/20 transition">
                        <span x-text="saving ? 'Saving...' : 'Save Settings'"></span>
                    </button>
                    <span x-show="saved" x-transition class="text-[11px] text-green-600 font-semibold">Settings saved!</span>
                </div>
            </div>
        </div>
        @endif

    </div>
</div>

<script>
function rDash() {
    return {
        refreshing: false,
        init() {
            this.$nextTick(() => {
                this.renderSalesChart();
                this.renderOrderTypeChart();
            });
            setInterval(() => this.refreshDashboard(), 120000);
        },
        async refreshDashboard() {
            this.refreshing = true;
            try { window.location.reload(); } catch(e) {}
        },
        renderSalesChart() {
            const ctx = document.getElementById('salesChart');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: @json($salesChartLabels),
                    datasets: [{
                        label: 'Revenue (Rs.)',
                        data: @json($salesChartData),
                        backgroundColor: function(context) {
                            const chart = context.chart;
                            const {ctx: c, chartArea} = chart;
                            if (!chartArea) return 'rgba(124, 58, 237, 0.15)';
                            const g = c.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                            g.addColorStop(0, 'rgba(124, 58, 237, 0.05)');
                            g.addColorStop(1, 'rgba(124, 58, 237, 0.25)');
                            return g;
                        },
                        borderColor: 'rgba(124, 58, 237, 0.8)',
                        borderWidth: 2,
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 600, easing: 'easeOutQuart' },
                    plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1e1b4b', titleFont: { size: 10, weight: '600' }, bodyFont: { size: 10 }, padding: 8, cornerRadius: 6, displayColors: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.03)', drawBorder: false }, ticks: { font: { size: 9, weight: '500' }, padding: 6 }, border: { display: false } },
                        x: { grid: { display: false }, ticks: { font: { size: 9, weight: '500' }, padding: 4 }, border: { display: false } }
                    }
                }
            });
        },
        renderOrderTypeChart() {
            const ctx = document.getElementById('orderTypeChart');
            if (!ctx) return;
            const data = @json($orderTypeCounts);
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(data).map(k => k.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())),
                    datasets: [{
                        data: Object.values(data),
                        backgroundColor: ['#7c3aed', '#3b82f6', '#f59e0b', '#10b981'],
                        borderWidth: 0,
                        spacing: 2,
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 600, easing: 'easeOutQuart' },
                    cutout: '68%',
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 10, usePointStyle: true, pointStyle: 'circle', font: { size: 9, weight: '500' } } },
                        tooltip: { backgroundColor: '#1e1b4b', titleFont: { size: 10, weight: '600' }, bodyFont: { size: 10 }, padding: 8, cornerRadius: 6, displayColors: true }
                    }
                }
            });
        },
    };
}
</script>
</x-pos-layout>
