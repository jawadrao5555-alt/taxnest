<style>
@keyframes fadeSlide { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
.t-anim { animation: fadeSlide 0.35s ease forwards; }
.t-d1 { animation-delay: 0ms; } .t-d2 { animation-delay: 50ms; } .t-d3 { animation-delay: 100ms; } .t-d4 { animation-delay: 150ms; } .t-d5 { animation-delay: 200ms; }
.t-card { background: white; border: 1px solid #f3f4f6; border-radius: 12px; transition: all 0.2s; }
.dark .t-card { background: rgb(17,24,39); border-color: rgb(31,41,55); }
.t-card:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.06); }
.t-kpi { border-left: 3px solid; padding-left: 12px; }
.t-metric { font-variant-numeric: tabular-nums; }
.t-strip { display: flex; gap: 2px; height: 4px; border-radius: 4px; overflow: hidden; }
.t-strip-seg { height: 100%; transition: width 0.6s ease; }
.glass-card { background: rgba(255,255,255,0.7); backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.4); border-radius: 14px; }
.dark .glass-card { background: rgba(17,24,39,0.7); border: 1px solid rgba(255,255,255,0.05); }
</style>

<div class="space-y-4 w-full">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 t-anim t-d1">
        <div>
            <h1 class="text-lg font-extrabold text-gray-900 dark:text-white">Dashboard</h1>
            <p class="text-[11px] text-gray-400 mt-0.5">{{ now()->format('l, d M Y') }} — {{ $company->name ?? 'Business' }}</p>
        </div>
        @include('pos.dashboard-styles._style-picker')
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-0.5 bg-gray-100 dark:bg-gray-800 rounded-2xl overflow-hidden t-anim t-d2">
        <div class="bg-white dark:bg-gray-900 p-4">
            <div class="flex items-center gap-2 mb-1"><span class="w-2 h-2 rounded-full bg-emerald-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Revenue</span></div>
            <p class="text-2xl font-black text-gray-900 dark:text-white t-metric">Rs.{{ number_format($todaySales ?? $todayStats->revenue ?? 0) }}</p>
            <p class="text-[9px] text-gray-400 mt-1">Today's total sales</p>
        </div>
        <div class="bg-white dark:bg-gray-900 p-4">
            <div class="flex items-center gap-2 mb-1"><span class="w-2 h-2 rounded-full bg-blue-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Orders</span></div>
            <p class="text-2xl font-black text-gray-900 dark:text-white t-metric">{{ $todayOrders ?? $todayStats->count ?? 0 }}</p>
            <p class="text-[9px] text-gray-400 mt-1">Completed today</p>
        </div>
        <div class="bg-white dark:bg-gray-900 p-4">
            <div class="flex items-center gap-2 mb-1"><span class="w-2 h-2 rounded-full bg-amber-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Avg Ticket</span></div>
            <p class="text-2xl font-black text-gray-900 dark:text-white t-metric">Rs.{{ number_format($todayStats->avg_ticket ?? (($todayOrders ?? 0) > 0 ? ($todaySales ?? 0) / ($todayOrders ?? 1) : 0)) }}</p>
            <p class="text-[9px] text-gray-400 mt-1">Per transaction</p>
        </div>
        <div class="bg-white dark:bg-gray-900 p-4">
            @if($isRestaurant)
            <div class="flex items-center gap-2 mb-1"><span class="w-2 h-2 rounded-full bg-purple-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Tables</span></div>
            <p class="text-2xl font-black text-gray-900 dark:text-white t-metric">{{ $occupiedTables ?? 0 }}<span class="text-base text-gray-300">/{{ $totalTables ?? 0 }}</span></p>
            <p class="text-[9px] text-gray-400 mt-1">Currently occupied</p>
            @else
            <div class="flex items-center gap-2 mb-1"><span class="w-2 h-2 rounded-full bg-purple-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Monthly</span></div>
            <p class="text-2xl font-black text-gray-900 dark:text-white t-metric">Rs.{{ number_format($monthSales ?? $monthStats->revenue ?? 0) }}</p>
            <p class="text-[9px] text-gray-400 mt-1">This month</p>
            @endif
        </div>
    </div>

    @if(isset($isAdmin) && $isAdmin && $isRestaurant)
    <div class="grid grid-cols-3 gap-3 t-anim t-d3">
        <div class="t-card p-3 t-kpi border-l-emerald-500">
            <p class="text-[8px] text-gray-400 font-bold uppercase">Gross Profit</p>
            <p class="text-sm font-extrabold {{ ($todayProfit ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }} t-metric">Rs.{{ number_format($todayProfit ?? 0) }}</p>
        </div>
        <div class="t-card p-3 t-kpi border-l-gray-400">
            <p class="text-[8px] text-gray-400 font-bold uppercase">Total Cost</p>
            <p class="text-sm font-extrabold text-gray-900 dark:text-white t-metric">Rs.{{ number_format($todayCost ?? 0) }}</p>
        </div>
        <div class="t-card p-3 t-kpi border-l-indigo-500">
            <p class="text-[8px] text-gray-400 font-bold uppercase">Margin</p>
            <p class="text-sm font-extrabold text-indigo-600 t-metric">{{ ($todaySales ?? 0) > 0 ? round(($todayProfit ?? 0) / ($todaySales ?? 1) * 100) : 0 }}%</p>
        </div>
    </div>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 t-anim t-d3">
        <div class="t-card p-3">
            <div class="flex items-center gap-2"><div class="w-6 h-6 rounded bg-cyan-50 dark:bg-cyan-900/20 flex items-center justify-center"><svg class="w-3 h-3 text-cyan-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg></div><p class="text-[8px] text-gray-400 font-bold uppercase">Tax</p></div>
            <p class="text-sm font-extrabold text-gray-900 dark:text-white mt-1 t-metric">Rs.{{ number_format($todayTax ?? $todayStats->tax ?? 0) }}</p>
        </div>
        <div class="t-card p-3">
            <div class="flex items-center gap-2"><div class="w-6 h-6 rounded bg-orange-50 dark:bg-orange-900/20 flex items-center justify-center"><svg class="w-3 h-3 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg></div><p class="text-[8px] text-gray-400 font-bold uppercase">Discounts</p></div>
            <p class="text-sm font-extrabold text-orange-600 mt-1 t-metric">Rs.{{ number_format($todayDiscount ?? 0) }}</p>
        </div>
        <div class="t-card p-3">
            <div class="flex items-center gap-2"><div class="w-6 h-6 rounded bg-green-50 dark:bg-green-900/20 flex items-center justify-center"><svg class="w-3 h-3 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><p class="text-[8px] text-gray-400 font-bold uppercase">Completed</p></div>
            <p class="text-sm font-extrabold text-green-600 mt-1 t-metric">{{ $completedCount ?? $todayStats->count ?? 0 }}</p>
        </div>
        <div class="t-card p-3">
            <div class="flex items-center gap-2"><div class="w-6 h-6 rounded {{ ($lowStockItems ?? collect())->count() > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20' }} flex items-center justify-center"><svg class="w-3 h-3 {{ ($lowStockItems ?? collect())->count() > 0 ? 'text-red-600' : 'text-green-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg></div><p class="text-[8px] text-gray-400 font-bold uppercase">Alerts</p></div>
            <p class="text-sm font-extrabold {{ ($lowStockItems ?? collect())->count() > 0 ? 'text-red-600' : 'text-green-600' }} mt-1">{{ ($lowStockItems ?? collect())->count() }}</p>
        </div>
    </div>

    @if($isRestaurant && isset($salesChartLabels))
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 t-anim t-d4">
        <div class="lg:col-span-2 t-card p-4">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">Revenue — Last 7 Days</h2>
            <div style="height: 170px;"><canvas id="salesChart"></canvas></div>
        </div>
        @if(isset($orderTypeCounts))
        <div class="t-card p-4">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">Order Types</h2>
            <div style="height: 170px;"><canvas id="orderTypeChart"></canvas></div>
        </div>
        @endif
    </div>
    @endif

    @if(!$isRestaurant && isset($paymentBreakdown))
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 t-anim t-d4">
        <div class="t-card p-4">
            <h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">Payment Methods</h3>
            @forelse($paymentBreakdown as $pb)
            <div class="flex items-center justify-between py-2 border-b border-gray-50 dark:border-gray-800 last:border-0">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full {{ $pb->payment_method === 'cash' ? 'bg-emerald-500' : 'bg-blue-500' }}"></span>
                    <span class="text-[11px] font-semibold text-gray-700 dark:text-gray-300">{{ ucwords(str_replace('_', ' ', $pb->payment_method)) }}</span>
                </div>
                <span class="text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($pb->total) }}</span>
            </div>
            @empty
            <p class="text-[11px] text-gray-400 py-4 text-center">No sales today</p>
            @endforelse
        </div>
        <div class="lg:col-span-2 t-card p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide">Recent Transactions</h3>
                <a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-amber-600 hover:text-amber-700">VIEW ALL</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead><tr class="text-left text-[9px] font-bold text-gray-400 uppercase border-b border-gray-100 dark:border-gray-800"><th class="pb-2 pr-3">Invoice</th><th class="pb-2 pr-3">Customer</th><th class="pb-2 pr-3 text-right">Amount</th><th class="pb-2">Time</th></tr></thead>
                    <tbody>
                        @forelse($recentTransactions as $txn)
                        <tr class="border-b border-gray-50 dark:border-gray-800/50 last:border-0">
                            <td class="py-2 pr-3 text-[11px] font-bold text-amber-600">{{ $txn->invoice_number }}</td>
                            <td class="py-2 pr-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                            <td class="py-2 pr-3 text-right text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount) }}</td>
                            <td class="py-2 text-[10px] text-gray-400">{{ $txn->created_at->diffForHumans() }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="py-6 text-center text-[11px] text-gray-400">No transactions</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    @if($isRestaurant)
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 t-anim t-d5">
        <div class="t-card overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Top Sellers</h2></div>
            <div class="p-2 space-y-0.5">
                @forelse(($topProducts ?? collect())->take(5) as $idx => $p)
                <div class="flex items-center gap-2 py-1.5 px-2 rounded-lg hover:bg-amber-50/50 dark:hover:bg-amber-900/10 transition">
                    <span class="w-5 h-5 rounded flex items-center justify-center text-[9px] font-extrabold {{ $idx < 3 ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-400' }}">{{ $idx + 1 }}</span>
                    <p class="flex-1 text-[11px] font-semibold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p>
                    <span class="text-[9px] text-gray-400 font-mono">{{ $p->total_qty }}x</span>
                    <span class="text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($p->total_revenue) }}</span>
                </div>
                @empty
                <p class="text-[11px] text-gray-400 py-6 text-center">No data</p>
                @endforelse
            </div>
        </div>
        <div class="t-card overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                <div class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full {{ ($lowStockItems ?? collect())->count() > 0 ? 'bg-red-500' : 'bg-green-500' }}"></span><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Stock Alerts</h2></div>
                @if(($lowStockItems ?? collect())->count() > 0)<span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full bg-red-100 text-red-600">{{ $lowStockItems->count() }}</span>@endif
            </div>
            <div class="p-2 space-y-0.5">
                @forelse(($lowStockItems ?? collect())->take(5) as $ing)
                <div class="flex items-center gap-2 py-1.5 px-2 rounded-lg hover:bg-red-50/50 transition">
                    <span class="w-2 h-2 rounded-full {{ $ing->current_stock <= 0 ? 'bg-red-500' : 'bg-amber-500' }}"></span>
                    <p class="flex-1 text-[11px] font-semibold text-gray-900 dark:text-white truncate">{{ $ing->name }}</p>
                    <span class="text-[9px] font-bold px-1.5 py-0.5 rounded {{ $ing->current_stock <= 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }}">{{ number_format($ing->current_stock, 1) }} {{ $ing->unit }}</span>
                </div>
                @empty
                <div class="text-center py-6"><p class="text-[11px] text-green-600 font-semibold">All stocked</p></div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="t-card overflow-hidden t-anim t-d5">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Orders</h2>
            <a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-amber-600">VIEW ALL</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full"><thead><tr class="bg-gray-50/60 dark:bg-gray-800/30"><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Order</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-3 hidden sm:table-cell">Type</th><th class="text-right text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Amount</th><th class="text-center text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Status</th></tr></thead>
            <tbody>
                @forelse(($recentOrders ?? $recentTransactions ?? collect())->take(6) as $ro)
                <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition">
                    <td class="py-2 px-4 text-[11px] font-bold text-amber-600">{{ $ro->invoice_number ?? ('#' . $ro->id) }}</td>
                    <td class="py-2 px-3 hidden sm:table-cell"><span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-500">{{ ucwords(str_replace('_', ' ', $ro->order_type ?? $ro->payment_method ?? '-')) }}</span></td>
                    <td class="py-2 px-3 text-right text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($ro->total_amount) }}</td>
                    <td class="py-2 px-3 text-center"><span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full {{ ($ro->status ?? '') === 'completed' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' }}">{{ ucfirst($ro->status ?? 'pending') }}</span></td>
                </tr>
                @empty
                <tr><td colspan="4" class="py-6 text-center text-[11px] text-gray-400">No orders</td></tr>
                @endforelse
            </tbody></table>
        </div>
    </div>
    @endif
</div>
