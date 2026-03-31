@if(isset($isAdmin) && $isAdmin && $isRestaurant)
<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 slide-up slide-up-4">
    <div class="glass-card rounded-xl p-3.5 border-l-4 border-l-emerald-500 !rounded-l-none">
        <p class="text-[8px] text-gray-400 font-bold uppercase tracking-wider">Gross Profit</p>
        <p class="text-base font-extrabold stat-val mt-0.5 {{ ($todayProfit ?? 0) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600' }}">Rs. {{ number_format($todayProfit ?? 0) }}</p>
    </div>
    <div class="glass-card rounded-xl p-3.5">
        <p class="text-[8px] text-gray-400 font-bold uppercase tracking-wider">Total Cost</p>
        <p class="text-base font-extrabold text-gray-900 dark:text-white stat-val mt-0.5">Rs. {{ number_format($todayCost ?? 0) }}</p>
    </div>
    <div class="glass-card rounded-xl p-3.5">
        <p class="text-[8px] text-gray-400 font-bold uppercase tracking-wider">Margin</p>
        <p class="text-base font-extrabold stat-val mt-0.5 {{ ($todayProfit ?? 0) >= 0 ? 'text-indigo-600 dark:text-indigo-400' : 'text-red-600' }}">{{ ($todaySales ?? 0) > 0 ? round(($todayProfit ?? 0) / ($todaySales ?? 1) * 100) : 0 }}%</p>
    </div>
</div>
@endif

@if($isRestaurant && (isset($salesChartLabels) || isset($orderTypeCounts)))
<div class="grid grid-cols-1 lg:grid-cols-3 gap-3 slide-up slide-up-4">
    @if(isset($salesChartLabels))
    <div class="lg:col-span-2 glass-card rounded-xl p-4">
        <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">Revenue — Last 7 Days</h2>
        <div style="height: 170px;"><canvas id="salesChart"></canvas></div>
    </div>
    @endif
    @if(isset($orderTypeCounts))
    <div class="glass-card rounded-xl p-4">
        <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">Order Types</h2>
        <div style="height: 170px;"><canvas id="orderTypeChart"></canvas></div>
    </div>
    @endif
</div>
@endif

@if(!$isRestaurant && isset($paymentBreakdown))
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 slide-up slide-up-4">
    <div class="glass-card rounded-2xl p-5">
        <h3 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-4">Payment Split</h3>
        @forelse($paymentBreakdown as $pb)
        <div class="flex items-center justify-between py-2.5 border-b border-gray-100/50 dark:border-gray-800/50 last:border-0">
            <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg flex items-center justify-center {{ $pb->payment_method === 'cash' ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-blue-50 dark:bg-blue-900/20' }}">
                    @if($pb->payment_method === 'cash')
                    <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                    @else
                    <svg class="w-3.5 h-3.5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    @endif
                </div>
                <div>
                    <p class="text-[11px] font-semibold text-gray-900 dark:text-white">{{ ucwords(str_replace('_', ' ', $pb->payment_method)) }}</p>
                    <p class="text-[9px] text-gray-400">{{ $pb->count }} transactions</p>
                </div>
            </div>
            <span class="text-xs font-bold text-gray-900 dark:text-white">Rs.{{ number_format($pb->total) }}</span>
        </div>
        @empty
        <p class="text-[11px] text-gray-400 py-6 text-center">No sales today</p>
        @endforelse
    </div>
    <div class="lg:col-span-2 glass-card rounded-2xl p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide">Recent Transactions</h3>
            <a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-purple-600 hover:text-purple-700 dark:text-purple-400">VIEW ALL</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-left text-[9px] font-bold text-gray-400 uppercase tracking-wider border-b border-gray-100 dark:border-gray-800">
                        <th class="pb-2.5 pr-4">Invoice</th><th class="pb-2.5 pr-4">Customer</th><th class="pb-2.5 pr-4">Method</th><th class="pb-2.5 pr-4 text-right">Amount</th><th class="pb-2.5">Time</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentTransactions as $txn)
                    <tr class="table-row-hover border-b border-gray-50 dark:border-gray-800/50 last:border-0 transition-colors">
                        <td class="py-2.5 pr-4"><a href="{{ route('pos.transaction.show', $txn->id) }}" class="text-[11px] text-purple-600 font-bold">{{ $txn->invoice_number }}</a></td>
                        <td class="py-2.5 pr-4 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                        <td class="py-2.5 pr-4"><span class="inline-flex items-center px-2 py-0.5 rounded-md text-[9px] font-bold uppercase {{ $txn->payment_method === 'cash' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400' : 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400' }}">{{ $txn->payment_method }}</span></td>
                        <td class="py-2.5 pr-4 text-right text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount) }}</td>
                        <td class="py-2.5 text-[10px] text-gray-400">{{ $txn->created_at->diffForHumans() }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="py-8 text-center text-[11px] text-gray-400">No transactions yet</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

@if($isRestaurant)
<div class="grid grid-cols-1 lg:grid-cols-2 gap-3 slide-up slide-up-5">
    <div class="glass-card rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800/50"><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide">Top Selling Items</h2></div>
        <div class="p-2.5 space-y-0.5">
            @forelse(($topProducts ?? collect())->take(5) as $idx => $p)
            <div class="flex items-center gap-2 py-1.5 px-2 rounded-lg r-row transition">
                <span class="w-5 h-5 rounded-md flex items-center justify-center text-[9px] font-extrabold flex-shrink-0 {{ $idx < 3 ? 'bg-gradient-to-br from-purple-500 to-violet-600 text-white' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' }}">{{ $idx + 1 }}</span>
                <p class="flex-1 text-[11px] font-semibold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p>
                <span class="text-[9px] text-gray-400 bg-gray-50 dark:bg-gray-800 px-1.5 py-0.5 rounded font-mono">{{ $p->total_qty }}x</span>
                <span class="text-[11px] font-bold text-gray-900 dark:text-white">Rs. {{ number_format($p->total_revenue) }}</span>
            </div>
            @empty
            <p class="text-[11px] text-gray-400 py-6 text-center">No sales data yet</p>
            @endforelse
        </div>
    </div>
    <div class="glass-card rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800/50 flex items-center justify-between">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide">Stock Alerts</h2>
            @if(($lowStockItems ?? collect())->count() > 0)
            <span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">{{ $lowStockItems->count() }} items</span>
            @endif
        </div>
        <div class="p-2.5 space-y-0.5">
            @forelse(($lowStockItems ?? collect())->take(5) as $ing)
            <div class="flex items-center gap-2 py-1.5 px-2 rounded-lg r-row transition">
                <span class="w-2 h-2 rounded-full flex-shrink-0 {{ $ing->current_stock <= 0 ? 'bg-red-500' : 'bg-amber-500' }}"></span>
                <p class="flex-1 text-[11px] font-semibold text-gray-900 dark:text-white truncate">{{ $ing->name }}</p>
                <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-md {{ $ing->current_stock <= 0 ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' }}">{{ number_format($ing->current_stock, 1) }} {{ $ing->unit }}</span>
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

<div class="glass-card rounded-xl overflow-hidden slide-up slide-up-5">
    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800/50 flex items-center justify-between">
        <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide">Recent Transactions</h2>
        <a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-purple-600 hover:text-purple-700 dark:text-purple-400">VIEW ALL</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="bg-gray-50/60 dark:bg-gray-800/30">
                <th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Order #</th>
                <th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-3 hidden sm:table-cell">Type</th>
                <th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Table</th>
                <th class="text-right text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Amount</th>
                <th class="text-center text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Status</th>
            </tr></thead>
            <tbody>
                @forelse(($recentOrders ?? $recentTransactions ?? collect())->take(8) as $ro)
                <tr class="r-row border-b border-gray-50 dark:border-gray-800/50 transition">
                    <td class="py-2 px-4"><a href="{{ route('pos.transaction.show', $ro->id) }}" class="text-[11px] text-purple-600 font-bold">{{ $ro->invoice_number ?? ('#' . $ro->id) }}</a></td>
                    <td class="py-2 px-3 hidden sm:table-cell"><span class="text-[9px] font-bold px-1.5 py-0.5 rounded-md bg-gray-100 dark:bg-gray-800 text-gray-500">{{ ucwords(str_replace('_', ' ', $ro->order_type ?? $ro->payment_method ?? '-')) }}</span></td>
                    <td class="py-2 px-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $ro->table_number ?? '-' }}</td>
                    <td class="py-2 px-3 text-right text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($ro->total_amount) }}</td>
                    <td class="py-2 px-3 text-center"><span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full {{ ($ro->status ?? '') === 'completed' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' }}">{{ ucfirst($ro->status ?? 'pending') }}</span></td>
                </tr>
                @empty
                <tr><td colspan="5" class="py-8 text-center text-[11px] text-gray-400">No orders today</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif
