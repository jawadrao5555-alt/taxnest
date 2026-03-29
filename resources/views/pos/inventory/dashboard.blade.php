<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center shadow-lg shadow-purple-500/20">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
                Inventory Dashboard
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Real-time overview of stock levels, movements, and alerts</p>
        </div>
        <a href="{{ route('pos.inventory.adjust') }}" class="w-full sm:w-auto inline-flex items-center justify-center px-5 py-2.5 bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-700 hover:to-violet-700 text-white text-sm font-semibold rounded-xl transition shadow-lg shadow-purple-500/20">
            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Adjust Stock
        </a>
    </div>

    <div class="flex flex-wrap gap-2 mb-6">
        <a href="{{ route('pos.inventory.dashboard') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-purple-600 text-white shadow-sm">Dashboard</a>
        <a href="{{ route('pos.inventory.stock') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Stock Levels</a>
        <a href="{{ route('pos.inventory.movements') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Movements</a>
        <a href="{{ route('pos.inventory.low-stock') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700 {{ $lowStockItems->count() > 0 ? 'relative' : '' }}">
            Low Stock Alerts
            @if($lowStockItems->count() > 0)
            <span class="ml-1 inline-flex items-center justify-center w-5 h-5 text-[10px] font-bold bg-red-500 text-white rounded-full animate-pulse">{{ $lowStockItems->count() }}</span>
            @endif
        </a>
        <a href="{{ route('pos.inventory.adjust') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Adjust Stock</a>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="group bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-5 relative overflow-hidden hover:shadow-xl hover:-translate-y-0.5 transition-all duration-300">
            <div class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-bl from-purple-500/10 to-transparent rounded-bl-full group-hover:from-purple-500/20 transition-colors"></div>
            <div class="absolute bottom-0 left-0 w-full h-1 bg-gradient-to-r from-purple-500 to-violet-500 transform scale-x-0 group-hover:scale-x-100 transition-transform origin-left duration-500"></div>
            <div class="flex items-center gap-3 mb-3">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-purple-100 to-purple-50 dark:from-purple-900/30 dark:to-purple-800/20 flex items-center justify-center shadow-sm shadow-purple-500/10">
                    <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
            </div>
            <p class="text-3xl font-black text-gray-900 dark:text-white">{{ number_format($totalProducts) }}</p>
            <p class="text-[11px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mt-1">Total Products</p>
        </div>
        <div class="group bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-5 relative overflow-hidden hover:shadow-xl hover:-translate-y-0.5 transition-all duration-300">
            <div class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-bl from-emerald-500/10 to-transparent rounded-bl-full group-hover:from-emerald-500/20 transition-colors"></div>
            <div class="absolute bottom-0 left-0 w-full h-1 bg-gradient-to-r from-emerald-500 to-teal-500 transform scale-x-0 group-hover:scale-x-100 transition-transform origin-left duration-500"></div>
            <div class="flex items-center gap-3 mb-3">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-100 to-emerald-50 dark:from-emerald-900/30 dark:to-emerald-800/20 flex items-center justify-center shadow-sm shadow-emerald-500/10">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
            <p class="text-2xl font-black text-emerald-600">PKR {{ number_format($totalStockValue, 0) }}</p>
            <p class="text-[11px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mt-1">Stock Value</p>
        </div>
        <div class="group bg-white dark:bg-gray-900 rounded-2xl border {{ $lowStockItems->count() > 0 ? 'border-amber-200 dark:border-amber-800/50' : 'border-gray-100 dark:border-gray-700' }} shadow-lg p-5 relative overflow-hidden hover:shadow-xl hover:-translate-y-0.5 transition-all duration-300">
            <div class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-bl from-amber-500/10 to-transparent rounded-bl-full group-hover:from-amber-500/20 transition-colors"></div>
            <div class="absolute bottom-0 left-0 w-full h-1 bg-gradient-to-r from-amber-500 to-orange-500 {{ $lowStockItems->count() > 0 ? 'scale-x-100' : 'scale-x-0 group-hover:scale-x-100' }} transform transition-transform origin-left duration-500"></div>
            <div class="flex items-center gap-3 mb-3">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-100 to-amber-50 dark:from-amber-900/30 dark:to-amber-800/20 flex items-center justify-center shadow-sm shadow-amber-500/10">
                    <svg class="w-5 h-5 text-amber-600 {{ $lowStockItems->count() > 0 ? 'animate-pulse' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
            </div>
            <p class="text-3xl font-black {{ $lowStockItems->count() > 0 ? 'text-amber-600' : 'text-gray-900 dark:text-white' }}">{{ $lowStockItems->count() }}</p>
            <p class="text-[11px] font-semibold {{ $lowStockItems->count() > 0 ? 'text-amber-500' : 'text-gray-400 dark:text-gray-500' }} uppercase tracking-wider mt-1">Low Stock</p>
        </div>
        <div class="group bg-white dark:bg-gray-900 rounded-2xl border {{ $outOfStockCount > 0 ? 'border-red-200 dark:border-red-800/50' : 'border-gray-100 dark:border-gray-700' }} shadow-lg p-5 relative overflow-hidden hover:shadow-xl hover:-translate-y-0.5 transition-all duration-300">
            <div class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-bl from-red-500/10 to-transparent rounded-bl-full group-hover:from-red-500/20 transition-colors"></div>
            <div class="absolute bottom-0 left-0 w-full h-1 bg-gradient-to-r from-red-500 to-rose-500 {{ $outOfStockCount > 0 ? 'scale-x-100' : 'scale-x-0 group-hover:scale-x-100' }} transform transition-transform origin-left duration-500"></div>
            <div class="flex items-center gap-3 mb-3">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-red-100 to-red-50 dark:from-red-900/30 dark:to-red-800/20 flex items-center justify-center shadow-sm shadow-red-500/10">
                    <svg class="w-5 h-5 text-red-600 {{ $outOfStockCount > 0 ? 'animate-pulse' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                </div>
            </div>
            <p class="text-3xl font-black {{ $outOfStockCount > 0 ? 'text-red-600' : 'text-gray-900 dark:text-white' }}">{{ $outOfStockCount }}</p>
            <p class="text-[11px] font-semibold {{ $outOfStockCount > 0 ? 'text-red-500' : 'text-gray-400 dark:text-gray-500' }} uppercase tracking-wider mt-1">Out of Stock</p>
        </div>
    </div>

    @if($lowStockItems->count() > 0)
    <div class="bg-gradient-to-r from-amber-50 to-orange-50 dark:from-amber-900/20 dark:to-orange-900/20 border border-amber-200 dark:border-amber-700 rounded-2xl p-5 mb-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-amber-500/20 flex items-center justify-center">
                    <svg class="w-4 h-4 text-amber-600 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <h3 class="text-sm font-bold text-amber-800 dark:text-amber-300">Low Stock Alerts</h3>
            </div>
            <a href="{{ route('pos.inventory.low-stock') }}" class="text-xs font-semibold text-amber-700 hover:text-amber-900 transition">View All &rarr;</a>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach($lowStockItems->take(6) as $item)
            <div class="flex items-center justify-between bg-white/80 dark:bg-gray-900/80 rounded-xl p-3 border border-amber-100 dark:border-amber-800/50 backdrop-blur-sm">
                <span class="text-sm font-medium text-gray-900 dark:text-white truncate mr-2">{{ $item->product->name ?? 'Unknown' }}</span>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <div class="w-16 bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                        @php $pct = $item->min_stock_level > 0 ? min(($item->quantity / $item->min_stock_level) * 100, 100) : 0; @endphp
                        <div class="h-1.5 rounded-full {{ $pct < 30 ? 'bg-red-500' : ($pct < 70 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ $pct }}%"></div>
                    </div>
                    <span class="text-xs font-bold {{ $item->quantity <= 0 ? 'text-red-600' : 'text-amber-600' }}">{{ number_format($item->quantity, 0) }}</span>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-5 mb-6">
        <div class="flex items-center gap-2 mb-5">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </div>
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">Stock Health Overview</h3>
        </div>
        @php
            $healthPct = $totalTracked > 0 ? round(($healthyCount / $totalTracked) * 100) : 100;
            $lowPct = $totalTracked > 0 ? round(($lowStockItems->count() / $totalTracked) * 100) : 0;
            $outPct = $totalTracked > 0 ? round(($outOfStockCount / $totalTracked) * 100) : 0;
        @endphp
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-center">
            <div class="flex flex-col items-center justify-center">
                <div class="relative w-28 h-28" x-data="{ pct: 0 }" x-init="setTimeout(() => pct = {{ $healthPct }}, 200)">
                    <svg class="w-28 h-28 -rotate-90" viewBox="0 0 120 120">
                        <circle cx="60" cy="60" r="50" fill="none" stroke-width="10" class="stroke-gray-100 dark:stroke-gray-800"/>
                        <circle cx="60" cy="60" r="50" fill="none" stroke-width="10" stroke-linecap="round" class="stroke-emerald-500 transition-all duration-1000" :stroke-dasharray="`${pct * 3.14} 314`"/>
                    </svg>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <span class="text-2xl font-black text-gray-900 dark:text-white" x-text="pct + '%'">0%</span>
                    </div>
                </div>
                <p class="text-xs font-semibold text-gray-500 mt-2">Healthy Stock</p>
            </div>
            <div class="sm:col-span-3 space-y-3">
                <div class="flex items-center gap-3">
                    <div class="w-3 h-3 rounded-full bg-emerald-500 flex-shrink-0"></div>
                    <div class="flex-1">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">In Stock</span>
                            <span class="text-xs font-bold text-emerald-600">{{ $healthyCount }} products</span>
                        </div>
                        <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-2">
                            <div class="h-2 rounded-full bg-emerald-500 transition-all duration-700" style="width: {{ $healthPct }}%"></div>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-3 h-3 rounded-full bg-amber-500 flex-shrink-0"></div>
                    <div class="flex-1">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">Low Stock</span>
                            <span class="text-xs font-bold text-amber-600">{{ $lowStockItems->count() }} products</span>
                        </div>
                        <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-2">
                            <div class="h-2 rounded-full bg-amber-500 transition-all duration-700" style="width: {{ $lowPct }}%"></div>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-3 h-3 rounded-full bg-red-500 flex-shrink-0"></div>
                    <div class="flex-1">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">Out of Stock</span>
                            <span class="text-xs font-bold text-red-600">{{ $outOfStockCount }} products</span>
                        </div>
                        <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-2">
                            <div class="h-2 rounded-full bg-red-500 transition-all duration-700" style="width: {{ $outPct }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-5">
            <div class="flex items-center justify-between mb-5">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                        <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
                    </div>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white">Recent Movements</h3>
                </div>
                <a href="{{ route('pos.inventory.movements') }}" class="text-xs font-semibold text-purple-600 hover:text-purple-800 transition">View All &rarr;</a>
            </div>
            <div class="space-y-3">
                @forelse($recentMovements as $m)
                <div class="flex items-center justify-between text-sm border-b border-gray-50 dark:border-gray-800 pb-3 last:border-0 last:pb-0">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center {{ in_array($m->type, ['sale', 'adjustment_out', 'return_out', 'transfer_out']) ? 'bg-red-100 dark:bg-red-900/30' : 'bg-emerald-100 dark:bg-emerald-900/30' }}">
                            @if(in_array($m->type, ['sale', 'adjustment_out', 'return_out', 'transfer_out']))
                            <svg class="w-4 h-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 13l-5 5m0 0l-5-5m5 5V6"/></svg>
                            @else
                            <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"/></svg>
                            @endif
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white text-sm">{{ $m->product->name ?? 'Unknown' }}</p>
                            <p class="text-xs text-gray-400">{{ ucwords(str_replace('_', ' ', $m->type)) }} &middot; {{ $m->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                    <span class="font-bold text-sm {{ in_array($m->type, ['sale', 'adjustment_out', 'return_out', 'transfer_out']) ? 'text-red-500' : 'text-emerald-600' }}">
                        {{ in_array($m->type, ['sale', 'adjustment_out', 'return_out', 'transfer_out']) ? '-' : '+' }}{{ number_format($m->quantity, 0) }}
                    </span>
                </div>
                @empty
                <div class="text-center py-8">
                    <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-6 h-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
                    </div>
                    <p class="text-sm text-gray-400">No movements recorded yet</p>
                </div>
                @endforelse
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-5">
            <div class="flex items-center justify-between mb-5">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                        <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    </div>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white">Top Selling Products (30 Days)</h3>
                </div>
            </div>
            <div class="space-y-3">
                @forelse($topMovers as $i => $m)
                <div class="flex items-center justify-between text-sm border-b border-gray-50 dark:border-gray-800 pb-3 last:border-0 last:pb-0">
                    <div class="flex items-center gap-3">
                        <span class="w-7 h-7 rounded-lg bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center text-xs font-bold text-white shadow-sm">{{ $i + 1 }}</span>
                        <span class="font-semibold text-gray-900 dark:text-white">{{ $m->product->name ?? 'Unknown' }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-16 bg-gray-100 dark:bg-gray-800 rounded-full h-1.5 hidden sm:block">
                            @php $maxSold = $topMovers->first()->total_sold ?? 1; $soldPct = min(($m->total_sold / $maxSold) * 100, 100); @endphp
                            <div class="h-1.5 rounded-full bg-gradient-to-r from-purple-500 to-violet-600" style="width: {{ $soldPct }}%"></div>
                        </div>
                        <span class="font-bold text-purple-600 dark:text-purple-400 text-sm">{{ number_format($m->total_sold, 0) }}</span>
                    </div>
                </div>
                @empty
                <div class="text-center py-8">
                    <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-6 h-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    </div>
                    <p class="text-sm text-gray-400">No sales data yet</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
</x-pos-layout>
