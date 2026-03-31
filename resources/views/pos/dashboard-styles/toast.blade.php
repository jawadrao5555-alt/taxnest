<style>
@keyframes tFade { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
.t-anim { animation: tFade 0.3s ease forwards; }
.t-d1{animation-delay:0ms}.t-d2{animation-delay:50ms}.t-d3{animation-delay:100ms}.t-d4{animation-delay:150ms}.t-d5{animation-delay:200ms}
</style>

<div class="space-y-4 w-full">
    <div class="flex items-center justify-between t-anim t-d1">
        <div>
            <h1 class="text-lg font-extrabold text-gray-900 dark:text-white">Dashboard</h1>
            <p class="text-[11px] text-gray-400">{{ now()->format('D, d M Y') }} — {{ $company->name ?? 'Business' }}</p>
        </div>
        @include('pos.dashboard-styles._style-picker')
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 overflow-hidden t-anim t-d2">
        <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-gray-100 dark:divide-gray-800">
            <div class="p-4 sm:p-5">
                <div class="flex items-center gap-1.5 mb-1"><span class="w-2 h-2 rounded-full bg-amber-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Revenue</span></div>
                <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white" style="font-variant-numeric:tabular-nums">Rs.{{ number_format($todaySales ?? $todayStats->revenue ?? 0) }}</p>
                @php $yest=$yesterdaySales??0;$today=$todaySales??$todayStats->revenue??0;$pct=$yest>0?round(($today-$yest)/$yest*100):0; @endphp
                @if($pct!=0)<p class="text-[9px] font-bold mt-1 {{ $pct>=0?'text-green-600':'text-red-500' }}">{{ $pct>=0?'+':'' }}{{ $pct }}% vs yesterday</p>@endif
            </div>
            <div class="p-4 sm:p-5">
                <div class="flex items-center gap-1.5 mb-1"><span class="w-2 h-2 rounded-full bg-blue-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Orders</span></div>
                <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white">{{ $todayOrders ?? $todayStats->count ?? 0 }}</p>
                <p class="text-[9px] text-gray-400 mt-1">{{ $completedCount ?? $todayStats->count ?? 0 }} completed</p>
            </div>
            <div class="p-4 sm:p-5">
                <div class="flex items-center gap-1.5 mb-1"><span class="w-2 h-2 rounded-full bg-purple-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Avg Ticket</span></div>
                <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayStats->avg_ticket ?? (($todayOrders ?? 0) > 0 ? ($todaySales ?? 0) / ($todayOrders ?? 1) : 0)) }}</p>
                <p class="text-[9px] text-gray-400 mt-1">Per transaction</p>
            </div>
            <div class="p-4 sm:p-5">
                @if($isRestaurant)
                <div class="flex items-center gap-1.5 mb-1"><span class="w-2 h-2 rounded-full bg-emerald-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Tables</span></div>
                <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white">{{ $occupiedTables ?? 0 }}<span class="text-base text-gray-300 dark:text-gray-600">/{{ $totalTables ?? 0 }}</span></p>
                <p class="text-[9px] text-gray-400 mt-1">{{ ($totalTables ?? 0) > 0 ? round(($occupiedTables ?? 0) / ($totalTables ?? 1) * 100) : 0 }}% occupied</p>
                @else
                <div class="flex items-center gap-1.5 mb-1"><span class="w-2 h-2 rounded-full bg-emerald-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Monthly</span></div>
                <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white">Rs.{{ number_format($monthSales ?? $monthStats->revenue ?? 0) }}</p>
                <p class="text-[9px] text-gray-400 mt-1">{{ $monthStats->count ?? 0 }} orders</p>
                @endif
            </div>
        </div>
        <div class="h-1 flex">
            <div class="flex-1 bg-amber-400"></div>
            <div class="flex-1 bg-blue-400"></div>
            <div class="flex-1 bg-purple-400"></div>
            <div class="flex-1 bg-emerald-400"></div>
        </div>
    </div>

    <div class="flex flex-wrap gap-2 t-anim t-d2">
        @if($isRestaurant)
        <a href="{{ route('pos.restaurant.pos') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-amber-500 text-white text-[11px] font-bold hover:bg-amber-600 transition shadow-sm"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>POS Screen</a>
        <a href="{{ route('pos.transactions') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-[11px] font-bold hover:bg-gray-200 dark:hover:bg-gray-700 transition"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>Orders</a>
        <a href="{{ route('pos.restaurant.tables') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-[11px] font-bold hover:bg-gray-200 dark:hover:bg-gray-700 transition"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>Tables</a>
        <a href="{{ route('pos.restaurant.kds') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-[11px] font-bold hover:bg-gray-200 dark:hover:bg-gray-700 transition"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>Kitchen</a>
        <a href="{{ route('pos.products') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-[11px] font-bold hover:bg-gray-200 dark:hover:bg-gray-700 transition"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>Menu</a>
        <a href="{{ route('pos.restaurant.ingredients') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-[11px] font-bold hover:bg-gray-200 dark:hover:bg-gray-700 transition"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>Ingredients</a>
        @else
        <a href="{{ route('pos.invoice.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-amber-500 text-white text-[11px] font-bold hover:bg-amber-600 transition shadow-sm"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>New Sale</a>
        <a href="{{ route('pos.transactions') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-[11px] font-bold hover:bg-gray-200 dark:hover:bg-gray-700 transition">Orders</a>
        <a href="{{ route('pos.products') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-[11px] font-bold hover:bg-gray-200 dark:hover:bg-gray-700 transition">Products</a>
        <a href="{{ route('pos.customers') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-[11px] font-bold hover:bg-gray-200 dark:hover:bg-gray-700 transition">Customers</a>
        <a href="{{ route('pos.reports') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-[11px] font-bold hover:bg-gray-200 dark:hover:bg-gray-700 transition">Reports</a>
        @endif
    </div>

    @if(isset($isAdmin) && $isAdmin && $isRestaurant)
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 p-4 t-anim t-d3">
        <div class="flex items-center gap-6 flex-wrap">
            <div class="flex items-center gap-3 border-l-3 border-l-emerald-500 pl-3">
                <div><p class="text-[8px] text-gray-400 font-bold uppercase">Gross Profit</p><p class="text-base font-black {{ ($todayProfit ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">Rs.{{ number_format($todayProfit ?? 0) }}</p></div>
            </div>
            <div class="w-px h-8 bg-gray-100 dark:bg-gray-800"></div>
            <div class="flex items-center gap-3 border-l-3 border-l-gray-300 pl-3">
                <div><p class="text-[8px] text-gray-400 font-bold uppercase">Total Cost</p><p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayCost ?? 0) }}</p></div>
            </div>
            <div class="w-px h-8 bg-gray-100 dark:bg-gray-800"></div>
            <div class="flex items-center gap-3 border-l-3 border-l-amber-500 pl-3">
                <div><p class="text-[8px] text-gray-400 font-bold uppercase">Margin</p><p class="text-base font-black text-amber-600">{{ ($todaySales ?? 0) > 0 ? round(($todayProfit ?? 0) / ($todaySales ?? 1) * 100) : 0 }}%</p></div>
            </div>
            <div class="w-px h-8 bg-gray-100 dark:bg-gray-800"></div>
            <div class="flex items-center gap-3 border-l-3 border-l-cyan-500 pl-3">
                <div><p class="text-[8px] text-gray-400 font-bold uppercase">Tax</p><p class="text-base font-black text-cyan-600">Rs.{{ number_format($todayTax ?? $todayStats->tax ?? 0) }}</p></div>
            </div>
            <div class="w-px h-8 bg-gray-100 dark:bg-gray-800"></div>
            <div class="flex items-center gap-3 border-l-3 border-l-orange-500 pl-3">
                <div><p class="text-[8px] text-gray-400 font-bold uppercase">Discounts</p><p class="text-base font-black text-orange-600">Rs.{{ number_format($todayDiscount ?? 0) }}</p></div>
            </div>
        </div>
    </div>
    @endif

    @if($isRestaurant && isset($salesChartLabels))
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 t-anim t-d3">
        <div class="lg:col-span-3 bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 p-4">
            <div class="flex items-center justify-between mb-3"><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide">Revenue Trend</h2><span class="text-[9px] text-gray-400">Last 7 days</span></div>
            <div style="height: 200px;"><canvas id="salesChart"></canvas></div>
        </div>
        <div class="lg:col-span-2 space-y-4">
            @if(isset($orderTypeCounts))
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 p-4">
                <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">Order Mix</h2>
                <div style="height: 140px;"><canvas id="orderTypeChart"></canvas></div>
            </div>
            @endif
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 p-4">
                <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">Stock Status</h2>
                @if(($lowStockItems ?? collect())->count() > 0)
                <div class="space-y-2">
                    @foreach(($lowStockItems ?? collect())->take(3) as $ing)
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full {{ $ing->current_stock <= 0 ? 'bg-red-500' : 'bg-amber-500' }}"></span><span class="text-[11px] font-semibold text-gray-700 dark:text-gray-300 truncate">{{ $ing->name }}</span></div>
                        <span class="text-[9px] font-bold px-2 py-0.5 rounded-full {{ $ing->current_stock <= 0 ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' }}">{{ number_format($ing->current_stock, 1) }} {{ $ing->unit }}</span>
                    </div>
                    @endforeach
                </div>
                @else
                <div class="flex items-center gap-2 py-2"><svg class="w-5 h-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span class="text-[11px] text-green-600 font-semibold">All ingredients in stock</span></div>
                @endif
            </div>
        </div>
    </div>
    @endif

    @if(!$isRestaurant && isset($paymentBreakdown))
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 t-anim t-d3">
        <div class="lg:col-span-2 bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 p-4">
            <h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">Payment Breakdown</h3>
            @forelse($paymentBreakdown as $pb)
            <div class="flex items-center gap-3 py-2.5 border-b border-gray-50 dark:border-gray-800 last:border-0">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center {{ $pb->payment_method === 'cash' ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-blue-50 dark:bg-blue-900/20' }}">
                    @if($pb->payment_method === 'cash')<svg class="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>@else<svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>@endif
                </div>
                <div class="flex-1"><p class="text-[11px] font-bold text-gray-900 dark:text-white">{{ ucwords(str_replace('_', ' ', $pb->payment_method)) }}</p><p class="text-[9px] text-gray-400">{{ $pb->count }} txns</p></div>
                <p class="text-sm font-black text-gray-900 dark:text-white">Rs.{{ number_format($pb->total) }}</p>
            </div>
            @empty
            <p class="text-[11px] text-gray-400 py-4 text-center">No sales today</p>
            @endforelse
        </div>
        <div class="lg:col-span-3 bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                <h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Transactions</h3>
                <a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-amber-600 hover:text-amber-700">View All →</a>
            </div>
            <table class="w-full"><thead><tr class="text-left text-[9px] font-bold text-gray-400 uppercase bg-gray-50/50 dark:bg-gray-800/30"><th class="py-2 px-4">Invoice</th><th class="py-2 px-3">Customer</th><th class="py-2 px-3 text-right">Amount</th><th class="py-2 px-3">Time</th></tr></thead><tbody>
                @forelse($recentTransactions as $txn)
                <tr class="border-b border-gray-50 dark:border-gray-800/50 last:border-0 hover:bg-amber-50/30 dark:hover:bg-amber-900/5 transition">
                    <td class="py-2.5 px-4 text-[11px] font-bold text-amber-600">{{ $txn->invoice_number }}</td>
                    <td class="py-2.5 px-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                    <td class="py-2.5 px-3 text-right text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount) }}</td>
                    <td class="py-2.5 px-3 text-[10px] text-gray-400">{{ $txn->created_at->diffForHumans() }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="py-6 text-center text-[11px] text-gray-400">No transactions yet</td></tr>
                @endforelse
            </tbody></table>
        </div>
    </div>
    @endif

    @if($isRestaurant)
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 t-anim t-d4">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-amber-500"></span><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Top Selling Items</h2></div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-gray-50 dark:divide-gray-800">
            @forelse(($topProducts ?? collect())->take(6) as $idx => $p)
            <div class="flex items-center gap-3 py-3 px-4 hover:bg-amber-50/30 dark:hover:bg-amber-900/5 transition">
                <span class="w-7 h-7 rounded-lg flex items-center justify-center text-[10px] font-black flex-shrink-0 {{ $idx < 3 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-gray-100 text-gray-400 dark:bg-gray-800' }}">{{ $idx + 1 }}</span>
                <div class="flex-1 min-w-0"><p class="text-[11px] font-bold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p><p class="text-[9px] text-gray-400">{{ $p->total_qty }} sold</p></div>
                <p class="text-[11px] font-black text-gray-900 dark:text-white flex-shrink-0">Rs.{{ number_format($p->total_revenue) }}</p>
            </div>
            @empty
            <div class="col-span-2 py-8 text-center text-[11px] text-gray-400">No sales data</div>
            @endforelse
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 overflow-hidden t-anim t-d5">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Orders</h2>
            <a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-amber-600">View All →</a>
        </div>
        <table class="w-full"><thead><tr class="bg-gray-50/60 dark:bg-gray-800/30"><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Order</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-3 hidden sm:table-cell">Type</th><th class="text-right text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Amount</th><th class="text-center text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Status</th></tr></thead><tbody>
            @forelse(($recentOrders ?? $recentTransactions ?? collect())->take(8) as $ro)
            <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-amber-50/20 dark:hover:bg-amber-900/5 transition">
                <td class="py-2.5 px-4 text-[11px] font-bold text-amber-600">{{ $ro->invoice_number ?? ('#' . $ro->id) }}</td>
                <td class="py-2.5 px-3 hidden sm:table-cell"><span class="text-[9px] font-bold px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500">{{ ucwords(str_replace('_', ' ', $ro->order_type ?? $ro->payment_method ?? '-')) }}</span></td>
                <td class="py-2.5 px-3 text-right text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($ro->total_amount) }}</td>
                <td class="py-2.5 px-3 text-center"><span class="text-[8px] font-bold px-2 py-0.5 rounded-full {{ ($ro->status ?? '') === 'completed' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' }}">{{ ucfirst($ro->status ?? 'pending') }}</span></td>
            </tr>
            @empty
            <tr><td colspan="4" class="py-8 text-center text-[11px] text-gray-400">No orders today</td></tr>
            @endforelse
        </tbody></table>
    </div>
    @endif
</div>
