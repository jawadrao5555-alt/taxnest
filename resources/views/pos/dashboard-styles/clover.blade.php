<style>
@keyframes clSlide { from { opacity: 0; transform: translateX(-8px); } to { opacity: 1; transform: translateX(0); } }
.cl-anim { animation: clSlide 0.3s ease forwards; }
.cl-d1 { animation-delay: 0ms; } .cl-d2 { animation-delay: 60ms; } .cl-d3 { animation-delay: 120ms; } .cl-d4 { animation-delay: 180ms; } .cl-d5 { animation-delay: 240ms; }
.cl-card { background: white; border-radius: 16px; border: 1px solid #e5e7eb; transition: all 0.2s; }
.dark .cl-card { background: rgb(17,24,39); border-color: rgb(31,41,55); }
.cl-card:hover { border-color: #a7f3d0; box-shadow: 0 4px 20px rgba(34,197,94,0.08); }
.cl-hero { background: linear-gradient(135deg, #064e3b 0%, #065f46 40%, #047857 100%); border-radius: 20px; position: relative; overflow: hidden; }
.cl-hero::before { content: ''; position: absolute; top: -50%; right: -30%; width: 60%; height: 120%; background: radial-gradient(circle, rgba(52,211,153,0.15) 0%, transparent 70%); }
.cl-bar { height: 6px; border-radius: 3px; background: #f3f4f6; overflow: hidden; }
.dark .cl-bar { background: rgb(31,41,55); }
.cl-bar-fill { height: 100%; border-radius: 3px; transition: width 0.8s ease; }
.cl-tab { padding: 6px 14px; border-radius: 8px; font-size: 10px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
</style>

<div class="space-y-5 w-full">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 cl-anim cl-d1">
        <div>
            <h1 class="text-lg font-black text-gray-900 dark:text-white">{{ $company->name ?? 'Business' }}</h1>
            <p class="text-[11px] text-gray-400">{{ now()->format('l, d M Y') }}</p>
        </div>
        @include('pos.dashboard-styles._style-picker')
    </div>

    <div class="cl-hero p-6 cl-anim cl-d2">
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-[10px] font-bold text-emerald-300/70 uppercase tracking-widest">Today's Revenue</p>
                <p class="text-3xl font-black text-white mt-1">Rs. {{ number_format($todaySales ?? $todayStats->revenue ?? 0) }}</p>
                @php $changePercent = ($yesterdaySales ?? 0) > 0 ? round((($todaySales ?? $todayStats->revenue ?? 0) - ($yesterdaySales ?? 0)) / ($yesterdaySales ?? 1) * 100) : 0; @endphp
                @if($changePercent != 0)
                <div class="flex items-center gap-1 mt-2">
                    <span class="text-[11px] font-bold {{ $changePercent >= 0 ? 'text-emerald-300' : 'text-red-300' }}">
                        <svg class="w-3 h-3 inline {{ $changePercent >= 0 ? '' : 'rotate-180' }}" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z"/></svg>
                        {{ abs($changePercent) }}% vs yesterday
                    </span>
                </div>
                @endif
            </div>
            <div class="grid grid-cols-3 gap-4">
                <div class="text-center"><p class="text-2xl font-black text-white">{{ $todayOrders ?? $todayStats->count ?? 0 }}</p><p class="text-[8px] text-emerald-300/60 uppercase font-bold">Orders</p></div>
                <div class="text-center"><p class="text-2xl font-black text-white">{{ number_format($todayStats->avg_ticket ?? (($todayOrders ?? 0) > 0 ? ($todaySales ?? 0) / ($todayOrders ?? 1) : 0)) }}</p><p class="text-[8px] text-emerald-300/60 uppercase font-bold">Avg Ticket</p></div>
                @if($isRestaurant)
                <div class="text-center"><p class="text-2xl font-black text-white">{{ $occupiedTables ?? 0 }}<span class="text-lg text-white/40">/{{ $totalTables ?? 0 }}</span></p><p class="text-[8px] text-emerald-300/60 uppercase font-bold">Tables</p></div>
                @else
                <div class="text-center"><p class="text-2xl font-black text-white">{{ number_format(($monthSales ?? $monthStats->revenue ?? 0) / 1000) }}k</p><p class="text-[8px] text-emerald-300/60 uppercase font-bold">Monthly</p></div>
                @endif
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 cl-anim cl-d3">
        <div class="cl-card p-4">
            <div class="flex items-center justify-between mb-2"><p class="text-[9px] font-bold text-gray-400 uppercase">Tax Collected</p><div class="w-6 h-6 rounded-lg bg-cyan-50 dark:bg-cyan-900/20 flex items-center justify-center"><svg class="w-3 h-3 text-cyan-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg></div></div>
            <p class="text-lg font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayTax ?? $todayStats->tax ?? 0) }}</p>
            <div class="cl-bar mt-2"><div class="cl-bar-fill bg-cyan-500" style="width: {{ min(100, ($todayTax ?? 0) > 0 ? 60 : 0) }}%"></div></div>
        </div>
        <div class="cl-card p-4">
            <div class="flex items-center justify-between mb-2"><p class="text-[9px] font-bold text-gray-400 uppercase">Discounts</p><div class="w-6 h-6 rounded-lg bg-orange-50 dark:bg-orange-900/20 flex items-center justify-center"><svg class="w-3 h-3 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg></div></div>
            <p class="text-lg font-black text-orange-600">Rs.{{ number_format($todayDiscount ?? 0) }}</p>
            <div class="cl-bar mt-2"><div class="cl-bar-fill bg-orange-500" style="width: {{ min(100, ($todayDiscount ?? 0) > 0 ? 40 : 0) }}%"></div></div>
        </div>
        <div class="cl-card p-4">
            <div class="flex items-center justify-between mb-2"><p class="text-[9px] font-bold text-gray-400 uppercase">Completed</p><div class="w-6 h-6 rounded-lg bg-green-50 dark:bg-green-900/20 flex items-center justify-center"><svg class="w-3 h-3 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div></div>
            <p class="text-lg font-black text-green-600">{{ $completedCount ?? $todayStats->count ?? 0 }}</p>
            <div class="cl-bar mt-2"><div class="cl-bar-fill bg-green-500" style="width: {{ min(100, ($completedCount ?? 0) * 5) }}%"></div></div>
        </div>
        <div class="cl-card p-4">
            <div class="flex items-center justify-between mb-2"><p class="text-[9px] font-bold text-gray-400 uppercase">Low Stock</p><div class="w-6 h-6 rounded-lg {{ ($lowStockItems ?? collect())->count() > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20' }} flex items-center justify-center"><svg class="w-3 h-3 {{ ($lowStockItems ?? collect())->count() > 0 ? 'text-red-600' : 'text-green-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg></div></div>
            <p class="text-lg font-black {{ ($lowStockItems ?? collect())->count() > 0 ? 'text-red-600' : 'text-green-600' }}">{{ ($lowStockItems ?? collect())->count() }}</p>
            <div class="cl-bar mt-2"><div class="cl-bar-fill {{ ($lowStockItems ?? collect())->count() > 0 ? 'bg-red-500' : 'bg-green-500' }}" style="width: {{ ($lowStockItems ?? collect())->count() > 0 ? min(100, ($lowStockItems ?? collect())->count() * 20) : 100 }}%"></div></div>
        </div>
    </div>

    @if(isset($isAdmin) && $isAdmin && $isRestaurant)
    <div class="grid grid-cols-3 gap-3 cl-anim cl-d3">
        <div class="cl-card p-3 border-l-4 !border-l-emerald-500 !rounded-l-sm">
            <p class="text-[8px] text-gray-400 font-bold uppercase">Gross Profit</p>
            <p class="text-base font-black {{ ($todayProfit ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">Rs.{{ number_format($todayProfit ?? 0) }}</p>
        </div>
        <div class="cl-card p-3 border-l-4 !border-l-gray-300 !rounded-l-sm">
            <p class="text-[8px] text-gray-400 font-bold uppercase">Total Cost</p>
            <p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayCost ?? 0) }}</p>
        </div>
        <div class="cl-card p-3 border-l-4 !border-l-indigo-500 !rounded-l-sm">
            <p class="text-[8px] text-gray-400 font-bold uppercase">Margin</p>
            <p class="text-base font-black text-indigo-600">{{ ($todaySales ?? 0) > 0 ? round(($todayProfit ?? 0) / ($todaySales ?? 1) * 100) : 0 }}%</p>
        </div>
    </div>
    @endif

    @if($isRestaurant && isset($salesChartLabels))
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 cl-anim cl-d4">
        <div class="lg:col-span-2 cl-card p-4">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase mb-3">Revenue — Last 7 Days</h2>
            <div style="height: 180px;"><canvas id="salesChart"></canvas></div>
        </div>
        @if(isset($orderTypeCounts))
        <div class="cl-card p-4">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase mb-3">Order Types</h2>
            <div style="height: 180px;"><canvas id="orderTypeChart"></canvas></div>
        </div>
        @endif
    </div>
    @endif

    @if(!$isRestaurant && isset($paymentBreakdown))
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 cl-anim cl-d4">
        <div class="cl-card p-4">
            <h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase mb-3">Payment Methods</h3>
            @forelse($paymentBreakdown as $pb)
            <div class="flex items-center justify-between py-2 border-b border-gray-50 dark:border-gray-800 last:border-0">
                <span class="text-[11px] font-semibold text-gray-700 dark:text-gray-300">{{ ucwords(str_replace('_', ' ', $pb->payment_method)) }}</span>
                <span class="text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($pb->total) }}</span>
            </div>
            @empty
            <p class="text-[11px] text-gray-400 py-4 text-center">No sales</p>
            @endforelse
        </div>
        <div class="lg:col-span-2 cl-card p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Transactions</h3>
                <a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-emerald-600">VIEW ALL</a>
            </div>
            <div class="overflow-x-auto"><table class="w-full"><thead><tr class="text-left text-[9px] font-bold text-gray-400 uppercase border-b border-gray-100 dark:border-gray-800"><th class="pb-2 pr-3">Invoice</th><th class="pb-2 pr-3">Customer</th><th class="pb-2 pr-3 text-right">Amount</th><th class="pb-2">Time</th></tr></thead><tbody>
                @forelse($recentTransactions as $txn)
                <tr class="border-b border-gray-50 dark:border-gray-800/50 last:border-0 hover:bg-emerald-50/30 dark:hover:bg-emerald-900/5 transition">
                    <td class="py-2 pr-3 text-[11px] font-bold text-emerald-600">{{ $txn->invoice_number }}</td>
                    <td class="py-2 pr-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                    <td class="py-2 pr-3 text-right text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount) }}</td>
                    <td class="py-2 text-[10px] text-gray-400">{{ $txn->created_at->diffForHumans() }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="py-6 text-center text-[11px] text-gray-400">No transactions</td></tr>
                @endforelse
            </tbody></table></div>
        </div>
    </div>
    @endif

    @if($isRestaurant)
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 cl-anim cl-d5">
        <div class="cl-card overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800"><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Top Items</h2></div>
            <div class="p-2 space-y-0.5">
                @forelse(($topProducts ?? collect())->take(5) as $idx => $p)
                <div class="flex items-center gap-2 py-1.5 px-2 rounded-lg hover:bg-emerald-50/50 dark:hover:bg-emerald-900/10 transition">
                    <span class="w-5 h-5 rounded flex items-center justify-center text-[9px] font-extrabold {{ $idx < 3 ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-400' }}">{{ $idx + 1 }}</span>
                    <p class="flex-1 text-[11px] font-semibold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p>
                    <span class="text-[9px] text-gray-400 font-mono">{{ $p->total_qty }}x</span>
                    <span class="text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($p->total_revenue) }}</span>
                </div>
                @empty
                <p class="text-[11px] text-gray-400 py-6 text-center">No data</p>
                @endforelse
            </div>
        </div>
        <div class="cl-card overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between"><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Stock Alerts</h2>@if(($lowStockItems ?? collect())->count() > 0)<span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full bg-red-100 text-red-600">{{ $lowStockItems->count() }}</span>@endif</div>
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

    <div class="cl-card overflow-hidden cl-anim cl-d5">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between"><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Orders</h2><a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-emerald-600">VIEW ALL</a></div>
        <div class="overflow-x-auto"><table class="w-full"><thead><tr class="bg-gray-50/60 dark:bg-gray-800/30"><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Order</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-3 hidden sm:table-cell">Type</th><th class="text-right text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Amount</th><th class="text-center text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Status</th></tr></thead><tbody>
            @forelse(($recentOrders ?? $recentTransactions ?? collect())->take(6) as $ro)
            <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-emerald-50/30 transition">
                <td class="py-2 px-4 text-[11px] font-bold text-emerald-600">{{ $ro->invoice_number ?? ('#' . $ro->id) }}</td>
                <td class="py-2 px-3 hidden sm:table-cell"><span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-500">{{ ucwords(str_replace('_', ' ', $ro->order_type ?? $ro->payment_method ?? '-')) }}</span></td>
                <td class="py-2 px-3 text-right text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($ro->total_amount) }}</td>
                <td class="py-2 px-3 text-center"><span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full {{ ($ro->status ?? '') === 'completed' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' }}">{{ ucfirst($ro->status ?? 'pending') }}</span></td>
            </tr>
            @empty
            <tr><td colspan="4" class="py-6 text-center text-[11px] text-gray-400">No orders</td></tr>
            @endforelse
        </tbody></table></div>
    </div>
    @endif
</div>
