<x-pos-layout>
<style>
.exe-stat { position: relative; overflow: hidden; border-radius: 16px; }
.exe-stat::before { content: ''; position: absolute; top: -50%; right: -50%; width: 100%; height: 100%; background: radial-gradient(circle, rgba(124,58,237,0.04) 0%, transparent 70%); }
.dark .exe-stat::before { background: radial-gradient(circle, rgba(124,58,237,0.08) 0%, transparent 70%); }
.glass-card { background: rgba(255,255,255,0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.4); }
.dark .glass-card { background: rgba(17,24,39,0.7); border: 1px solid rgba(255,255,255,0.05); }
.glow-green { box-shadow: 0 0 8px rgba(16,185,129,0.4); }
.glow-red { box-shadow: 0 0 8px rgba(239,68,68,0.4); }
.stat-value { font-variant-numeric: tabular-nums; letter-spacing: -0.02em; }
.progress-bar-fill { transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1); }
@keyframes slideUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
.slide-up { animation: slideUp 0.35s ease forwards; }
.slide-up-1 { animation-delay: 0ms; }
.slide-up-2 { animation-delay: 50ms; }
.slide-up-3 { animation-delay: 100ms; }
.slide-up-4 { animation-delay: 150ms; }
.slide-up-5 { animation-delay: 200ms; }
.exe-table tr:hover td { background: rgba(124,58,237,0.03); }
.dark .exe-table tr:hover td { background: rgba(124,58,237,0.08); }
.table-row-hover:hover { background: linear-gradient(90deg, rgba(124,58,237,0.02) 0%, transparent 100%); }
.dark .table-row-hover:hover { background: linear-gradient(90deg, rgba(124,58,237,0.06) 0%, transparent 100%); }
</style>

@php
    $posUser = auth('pos')->user();
    $isAdmin = $posUser && $posUser->pos_role === 'pos_admin';
@endphp

<div x-data="rDash()" x-init="init()">
    <div class="max-w-7xl mx-auto space-y-4">

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 slide-up slide-up-1">
            <div>
                <h1 class="text-xl font-extrabold text-gray-900 dark:text-white tracking-tight">Restaurant Dashboard</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ now()->format('l, d M Y') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <button @click="refreshDashboard()" class="p-2 rounded-xl glass-card text-gray-500 hover:text-purple-600 transition" title="Refresh">
                    <svg class="w-4 h-4" :class="refreshing ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
                <a href="{{ route('pos.restaurant.pos') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-purple-600 to-violet-600 text-white text-xs font-bold rounded-xl hover:from-purple-700 hover:to-violet-700 transition shadow-md shadow-purple-500/20">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    POS Screen
                </a>
                <a href="{{ route('pos.restaurant.kds') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-bold text-orange-600 bg-orange-50 dark:bg-orange-900/20 dark:text-orange-400 rounded-xl border border-orange-200 dark:border-orange-800 hover:bg-orange-100 dark:hover:bg-orange-900/30 transition">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    KDS
                </a>
            </div>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 slide-up slide-up-2">
            <div class="exe-stat bg-gradient-to-br from-emerald-500 to-emerald-700 p-4 shadow-lg shadow-emerald-500/15">
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[9px] font-bold uppercase tracking-wider text-emerald-100/70">Today's Revenue</span>
                        @if($yesterdaySales > 0)
                        @php $changePercent = round(($todaySales - $yesterdaySales) / $yesterdaySales * 100); @endphp
                        <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-white/15 text-white">{{ $changePercent >= 0 ? '+' : '' }}{{ $changePercent }}%</span>
                        @endif
                    </div>
                    <p class="text-xl font-extrabold text-white stat-value">Rs. {{ number_format($todaySales) }}</p>
                    <div class="mt-2 w-full bg-white/10 rounded-full h-1">
                        <div class="bg-white/40 h-1 rounded-full progress-bar-fill" style="width: {{ min(100, $yesterdaySales > 0 ? round($todaySales / $yesterdaySales * 100) : 50) }}%"></div>
                    </div>
                    <p class="text-[8px] text-emerald-200/50 mt-1">vs yesterday</p>
                </div>
            </div>

            <div class="exe-stat bg-gradient-to-br from-blue-500 to-indigo-700 p-4 shadow-lg shadow-blue-500/15">
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[9px] font-bold uppercase tracking-wider text-blue-100/70">Total Orders</span>
                        <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-white/15 text-amber-200">{{ $heldCount }} held</span>
                    </div>
                    <p class="text-xl font-extrabold text-white stat-value">{{ $todayOrders }}</p>
                    <div class="mt-2 w-full bg-white/10 rounded-full h-1">
                        <div class="bg-white/40 h-1 rounded-full progress-bar-fill" style="width: {{ min(100, $todayOrders * 5) }}%"></div>
                    </div>
                    <p class="text-[8px] text-blue-200/50 mt-1">today's orders</p>
                </div>
            </div>

            <div class="exe-stat bg-gradient-to-br from-purple-500 to-violet-700 p-4 shadow-lg shadow-purple-500/15">
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[9px] font-bold uppercase tracking-wider text-purple-100/70">Avg. Order</span>
                        @if($peakHour)
                        <span class="text-[9px] font-medium px-1.5 py-0.5 rounded-full bg-white/15 text-purple-200">Peak: {{ $peakHour }}</span>
                        @endif
                    </div>
                    <p class="text-xl font-extrabold text-white stat-value">Rs. {{ $todayOrders > 0 ? number_format($todaySales / $todayOrders) : 0 }}</p>
                    <div class="mt-2 w-full bg-white/10 rounded-full h-1">
                        <div class="bg-white/40 h-1 rounded-full progress-bar-fill" style="width: 60%"></div>
                    </div>
                    <p class="text-[8px] text-purple-200/50 mt-1">per transaction</p>
                </div>
            </div>

            <div class="exe-stat bg-gradient-to-br from-amber-500 to-orange-600 p-4 shadow-lg shadow-amber-500/15">
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[9px] font-bold uppercase tracking-wider text-amber-100/70">Tables</span>
                    </div>
                    <p class="text-xl font-extrabold text-white stat-value">{{ $occupiedTables }}<span class="text-sm text-white/50 font-medium">/{{ $totalTables }}</span></p>
                    <div class="mt-2 w-full bg-white/10 rounded-full h-1">
                        <div class="bg-white/40 h-1 rounded-full progress-bar-fill" style="width: {{ $totalTables > 0 ? round($occupiedTables / $totalTables * 100) : 0 }}%"></div>
                    </div>
                    <p class="text-[8px] text-amber-200/50 mt-1">occupied now</p>
                </div>
            </div>
        </div>

        @if($isAdmin)
        <div class="grid grid-cols-3 gap-3 slide-up slide-up-3">
            <div class="glass-card rounded-2xl p-4 border-l-4 border-l-emerald-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[9px] text-gray-400 font-bold uppercase tracking-wider">Gross Profit</p>
                        <p class="text-lg font-extrabold stat-value mt-0.5 {{ ($todayProfit ?? 0) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600' }}">Rs. {{ number_format($todayProfit ?? 0) }}</p>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center">
                        <svg class="w-4.5 h-4.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                </div>
            </div>
            <div class="glass-card rounded-2xl p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[9px] text-gray-400 font-bold uppercase tracking-wider">Total Cost</p>
                        <p class="text-lg font-extrabold text-gray-900 dark:text-white stat-value mt-0.5">Rs. {{ number_format($todayCost ?? 0) }}</p>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-gray-50 dark:bg-gray-800 flex items-center justify-center">
                        <svg class="w-4.5 h-4.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg>
                    </div>
                </div>
            </div>
            <div class="glass-card rounded-2xl p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[9px] text-gray-400 font-bold uppercase tracking-wider">Margin</p>
                        <p class="text-lg font-extrabold stat-value mt-0.5 {{ ($todayProfit ?? 0) >= 0 ? 'text-indigo-600 dark:text-indigo-400' : 'text-red-600' }}">{{ $todaySales > 0 ? round(($todayProfit ?? 0) / $todaySales * 100) : 0 }}%</p>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-indigo-50 dark:bg-indigo-900/20 flex items-center justify-center">
                        <svg class="w-4.5 h-4.5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/></svg>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 slide-up slide-up-3">
            <div class="glass-card rounded-2xl p-3.5 flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-cyan-50 dark:bg-cyan-900/20 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-cyan-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                </div>
                <div>
                    <p class="text-[8px] text-gray-400 font-bold uppercase">Tax Collected</p>
                    <p class="text-sm font-extrabold text-gray-900 dark:text-white stat-value">Rs. {{ number_format($todayTax) }}</p>
                </div>
            </div>
            <div class="glass-card rounded-2xl p-3.5 flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-orange-50 dark:bg-orange-900/20 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                </div>
                <div>
                    <p class="text-[8px] text-gray-400 font-bold uppercase">Discounts</p>
                    <p class="text-sm font-extrabold text-orange-600 stat-value">Rs. {{ number_format($todayDiscount) }}</p>
                </div>
            </div>
            <div class="glass-card rounded-2xl p-3.5 flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-green-50 dark:bg-green-900/20 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <p class="text-[8px] text-gray-400 font-bold uppercase">Completed</p>
                    <p class="text-sm font-extrabold text-green-600 stat-value">{{ $completedCount }}</p>
                </div>
            </div>
            <div class="glass-card rounded-2xl p-3.5 flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg {{ $lowStockItems->count() > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20' }} flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 {{ $lowStockItems->count() > 0 ? 'text-red-600' : 'text-green-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                </div>
                <div>
                    <p class="text-[8px] text-gray-400 font-bold uppercase">Low Stock</p>
                    <p class="text-sm font-extrabold {{ $lowStockItems->count() > 0 ? 'text-red-600' : 'text-green-600' }} stat-value">{{ $lowStockItems->count() }}</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 slide-up slide-up-4">
            <div class="lg:col-span-2 glass-card rounded-2xl p-5">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide flex items-center gap-2">
                        <span class="w-1.5 h-4 rounded-full bg-purple-600"></span>
                        Revenue — Last 7 Days
                    </h2>
                </div>
                <div style="height: 180px;"><canvas id="salesChart"></canvas></div>
            </div>

            <div class="glass-card rounded-2xl p-5">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide flex items-center gap-2">
                        <span class="w-1.5 h-4 rounded-full bg-blue-600"></span>
                        Order Types
                    </h2>
                </div>
                <div style="height: 180px;"><canvas id="orderTypeChart"></canvas></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 slide-up slide-up-5">
            <div class="glass-card rounded-2xl overflow-hidden">
                <div class="px-5 py-3.5 border-b border-gray-100/50 dark:border-gray-800/50 flex items-center gap-2">
                    <span class="w-1.5 h-4 rounded-full bg-amber-500"></span>
                    <h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide">Top Selling Items</h2>
                </div>
                <div class="p-3 space-y-0.5">
                    @forelse($topProducts->take(5) as $idx => $p)
                    <div class="flex items-center gap-2.5 py-2 px-2.5 rounded-xl table-row-hover transition">
                        <span class="w-6 h-6 rounded-lg flex items-center justify-center text-[10px] font-extrabold {{ $idx < 3 ? 'bg-gradient-to-br from-purple-500 to-violet-600 text-white shadow-sm' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' }}">{{ $idx + 1 }}</span>
                        <div class="flex-1 min-w-0">
                            <p class="text-[11px] font-semibold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p>
                        </div>
                        <span class="text-[9px] text-gray-400 bg-gray-50 dark:bg-gray-800 px-1.5 py-0.5 rounded font-mono">{{ $p->total_qty }}x</span>
                        <span class="text-[11px] font-bold text-gray-900 dark:text-white stat-value">Rs. {{ number_format($p->total_revenue) }}</span>
                    </div>
                    @empty
                    <p class="text-[11px] text-gray-400 py-6 text-center">No sales data yet</p>
                    @endforelse
                </div>
            </div>

            <div class="glass-card rounded-2xl overflow-hidden">
                <div class="px-5 py-3.5 border-b border-gray-100/50 dark:border-gray-800/50 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-1.5 h-4 rounded-full {{ $lowStockItems->count() > 0 ? 'bg-red-500' : 'bg-green-500' }}"></span>
                        <h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide">Stock Alerts</h2>
                    </div>
                    @if($lowStockItems->count() > 0)
                    <span class="text-[9px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">{{ $lowStockItems->count() }} items</span>
                    @endif
                </div>
                <div class="p-3 space-y-0.5">
                    @forelse($lowStockItems->take(5) as $ing)
                    <div class="flex items-center gap-2.5 py-2 px-2.5 rounded-xl table-row-hover transition">
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 {{ $ing->current_stock <= 0 ? 'bg-red-500 glow-red' : 'bg-amber-500' }}"></span>
                        <div class="flex-1 min-w-0">
                            <p class="text-[11px] font-semibold text-gray-900 dark:text-white truncate">{{ $ing->name }}</p>
                        </div>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-lg {{ $ing->current_stock <= 0 ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' }}">
                            {{ number_format($ing->current_stock, 1) }} {{ $ing->unit }}
                        </span>
                    </div>
                    @empty
                    <div class="text-center py-6">
                        <svg class="w-8 h-8 mx-auto text-green-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p class="text-[11px] text-green-600 font-semibold">All ingredients in stock</p>
                    </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="glass-card rounded-2xl overflow-hidden slide-up slide-up-5">
            <div class="px-5 py-3.5 border-b border-gray-100/50 dark:border-gray-800/50 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="w-1.5 h-4 rounded-full bg-indigo-500"></span>
                    <h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide">Recent Transactions</h2>
                </div>
                <a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-purple-600 hover:text-purple-700 dark:text-purple-400 transition">VIEW ALL</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full exe-table">
                    <thead>
                        <tr class="bg-gray-50/50 dark:bg-gray-800/30">
                            <th class="text-left text-[9px] text-gray-400 font-bold uppercase tracking-wider py-2.5 px-5">Order #</th>
                            <th class="text-left text-[9px] text-gray-400 font-bold uppercase tracking-wider py-2.5 px-4 hidden sm:table-cell">Type</th>
                            <th class="text-left text-[9px] text-gray-400 font-bold uppercase tracking-wider py-2.5 px-4">Table</th>
                            <th class="text-right text-[9px] text-gray-400 font-bold uppercase tracking-wider py-2.5 px-4">Amount</th>
                            <th class="text-center text-[9px] text-gray-400 font-bold uppercase tracking-wider py-2.5 px-4">Status</th>
                            <th class="text-right text-[9px] text-gray-400 font-bold uppercase tracking-wider py-2.5 px-5 hidden lg:table-cell">Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentOrders->take(8) as $ro)
                        <tr class="border-b border-gray-50 dark:border-gray-800/50 table-row-hover transition-colors">
                            <td class="py-2.5 px-5 text-[11px] font-bold text-gray-900 dark:text-white">{{ $ro->order_number }}</td>
                            <td class="py-2.5 px-4 hidden sm:table-cell"><span class="text-[9px] px-2 py-0.5 rounded-md bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 capitalize font-bold">{{ str_replace('_', ' ', $ro->order_type) }}</span></td>
                            <td class="py-2.5 px-4 text-[11px] text-gray-600 dark:text-gray-400">{{ $ro->table ? 'T-' . $ro->table->table_number : '—' }}</td>
                            <td class="py-2.5 px-4 text-right text-[11px] font-bold text-gray-900 dark:text-white stat-value">Rs. {{ number_format($ro->total_amount) }}</td>
                            <td class="py-2.5 px-4 text-center">
                                <span class="text-[8px] px-2 py-1 rounded-md font-bold uppercase inline-block
                                    {{ $ro->status === 'completed' ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400' : '' }}
                                    {{ $ro->status === 'held' ? 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400' : '' }}
                                    {{ $ro->status === 'preparing' ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400' : '' }}
                                    {{ $ro->status === 'ready' ? 'bg-purple-50 text-purple-700 dark:bg-purple-900/20 dark:text-purple-400' : '' }}
                                    {{ $ro->status === 'cancelled' ? 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400' : '' }}
                                ">{{ ucfirst($ro->status) }}</span>
                            </td>
                            <td class="py-2.5 px-5 text-right text-[10px] text-gray-400 hidden lg:table-cell">{{ $ro->created_at->diffForHumans() }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="py-8 text-center text-[11px] text-gray-400">No orders today</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($isAdmin)
        <div x-data="{ showSettings: false, mgrPin: '', cashierLimit: {{ $company->cashier_discount_limit ?? 10 }}, managerLimit: {{ $company->manager_discount_limit ?? 50 }}, saving: false, saved: false }" class="glass-card rounded-2xl overflow-hidden slide-up slide-up-5">
            <button @click="showSettings = !showSettings" class="flex items-center justify-between w-full px-5 py-3.5 hover:bg-gray-50/50 dark:hover:bg-gray-800/20 transition">
                <div class="flex items-center gap-2">
                    <span class="w-1.5 h-4 rounded-full bg-gray-400"></span>
                    <h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide">Role & Discount Settings</h2>
                </div>
                <svg class="w-4 h-4 text-gray-400 transition-transform duration-200" :class="showSettings ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="showSettings" x-transition class="px-5 pb-5 space-y-4 border-t border-gray-100/50 dark:border-gray-800/50 pt-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="text-[9px] font-bold text-gray-400 uppercase tracking-wider block mb-1.5">Cashier Discount Limit (%)</label>
                        <input type="number" x-model.number="cashierLimit" min="0" max="100" class="w-full text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition">
                    </div>
                    <div>
                        <label class="text-[9px] font-bold text-gray-400 uppercase tracking-wider block mb-1.5">Manager Discount Limit (%)</label>
                        <input type="number" x-model.number="managerLimit" min="0" max="100" class="w-full text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition">
                    </div>
                    <div>
                        <label class="text-[9px] font-bold text-gray-400 uppercase tracking-wider block mb-1.5">Manager Override PIN</label>
                        <input type="password" x-model="mgrPin" maxlength="6" placeholder="{{ $company->manager_override_pin ? '******' : 'Set 4-6 digit PIN' }}" class="w-full text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition">
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button @click="async function() { saving = true; saved = false; try { const res = await fetch('{{ route('pos.restaurant.save-manager-pin') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ pin: mgrPin || undefined, cashier_discount_limit: cashierLimit, manager_discount_limit: managerLimit }) }); const d = await res.json(); if (d.success) { saved = true; mgrPin = ''; setTimeout(() => saved = false, 3000); } } catch(e) {} saving = false; }()" :disabled="saving" class="px-5 py-2.5 text-xs font-bold text-white bg-gradient-to-r from-purple-600 to-violet-600 rounded-xl hover:from-purple-700 hover:to-violet-700 disabled:opacity-50 shadow-md shadow-purple-600/20 transition">
                        <span x-text="saving ? 'Saving...' : 'Save Settings'"></span>
                    </button>
                    <span x-show="saved" x-transition class="text-xs text-green-600 font-semibold">Settings saved!</span>
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
        autoRefreshTimer: null,
        init() {
            this.$nextTick(() => {
                this.renderSalesChart();
                this.renderOrderTypeChart();
            });
            this.autoRefreshTimer = setInterval(() => this.refreshDashboard(), 120000);
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
                            const gradient = c.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                            gradient.addColorStop(0, 'rgba(124, 58, 237, 0.05)');
                            gradient.addColorStop(1, 'rgba(124, 58, 237, 0.25)');
                            return gradient;
                        },
                        borderColor: 'rgba(124, 58, 237, 0.8)',
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 600, easing: 'easeOutQuart' },
                    plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1e1b4b', titleFont: { size: 11, weight: '600' }, bodyFont: { size: 11 }, padding: 10, cornerRadius: 8, displayColors: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)', drawBorder: false }, ticks: { font: { size: 9, weight: '500' }, padding: 8 }, border: { display: false } },
                        x: { grid: { display: false, drawBorder: false }, ticks: { font: { size: 9, weight: '500' }, padding: 4 }, border: { display: false } }
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
                        spacing: 3,
                        borderRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 600, easing: 'easeOutQuart' },
                    cutout: '68%',
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 12, usePointStyle: true, pointStyle: 'circle', font: { size: 10, weight: '500' } } },
                        tooltip: { backgroundColor: '#1e1b4b', titleFont: { size: 11, weight: '600' }, bodyFont: { size: 11 }, padding: 10, cornerRadius: 8, displayColors: true }
                    }
                }
            });
        },
    };
}
</script>
</x-pos-layout>
