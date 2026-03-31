<style>
@keyframes clSlide { from { opacity: 0; transform: translateX(-8px); } to { opacity: 1; transform: translateX(0); } }
.cl-anim { animation: clSlide 0.3s ease forwards; }
.cl-d1{animation-delay:0ms}.cl-d2{animation-delay:60ms}.cl-d3{animation-delay:120ms}.cl-d4{animation-delay:180ms}.cl-d5{animation-delay:240ms}
.cl-sidebar { background: linear-gradient(180deg, #064e3b 0%, #065f46 50%, #047857 100%); border-radius: 20px; }
.cl-card { background: white; border-radius: 14px; border: 1px solid #e5e7eb; }
.dark .cl-card { background: rgb(17,24,39); border-color: rgb(31,41,55); }
.cl-metric { position: relative; padding: 14px 16px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.05); }
.cl-nav-btn { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 10px; transition: all 0.2s; font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.7); }
.cl-nav-btn:hover { background: rgba(255,255,255,0.1); color: white; }
.cl-progress { height: 4px; border-radius: 2px; background: rgba(255,255,255,0.1); overflow: hidden; }
.cl-progress-fill { height: 100%; border-radius: 2px; background: #34d399; transition: width 0.8s ease; }
</style>

<div class="space-y-4 w-full">
    <div class="flex items-center justify-between cl-anim cl-d1">
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-lg bg-emerald-600 flex items-center justify-center"><svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg></div>
            <div>
                <h1 class="text-lg font-black text-gray-900 dark:text-white">{{ $company->name ?? 'Business' }}</h1>
                <p class="text-[10px] text-gray-400">{{ now()->format('l, d M Y') }}</p>
            </div>
        </div>
        @include('pos.dashboard-styles._style-picker')
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
        <div class="cl-sidebar p-5 lg:row-span-2 cl-anim cl-d2">
            <p class="text-[9px] font-bold text-emerald-300/60 uppercase tracking-widest mb-4">Today's Summary</p>

            <div class="cl-metric mb-3">
                <p class="text-[9px] text-emerald-200/60 font-bold uppercase">Revenue</p>
                <p class="text-2xl font-black text-white mt-1">Rs.{{ number_format($todaySales ?? $todayStats->revenue ?? 0) }}</p>
                @php $yest=$yesterdaySales??0;$today=$todaySales??$todayStats->revenue??0;$pct=$yest>0?round(($today-$yest)/$yest*100):0; @endphp
                <div class="cl-progress mt-2"><div class="cl-progress-fill" style="width: {{ min(100, $yest > 0 ? ($today / $yest * 100) : 50) }}%"></div></div>
                @if($pct!=0)<p class="text-[9px] mt-1 {{ $pct>=0?'text-emerald-300':'text-red-300' }}">{{ $pct>=0?'↑':'↓' }} {{ abs($pct) }}% vs yesterday</p>@endif
            </div>

            <div class="grid grid-cols-2 gap-2 mb-4">
                <div class="cl-metric">
                    <p class="text-[9px] text-emerald-200/60 font-bold">ORDERS</p>
                    <p class="text-lg font-black text-white">{{ $todayOrders ?? $todayStats->count ?? 0 }}</p>
                </div>
                <div class="cl-metric">
                    <p class="text-[9px] text-emerald-200/60 font-bold">AVG</p>
                    <p class="text-lg font-black text-white">Rs.{{ number_format($todayStats->avg_ticket ?? (($todayOrders ?? 0) > 0 ? ($todaySales ?? 0) / ($todayOrders ?? 1) : 0)) }}</p>
                </div>
            </div>

            @if($isRestaurant)
            <div class="cl-metric mb-4">
                <div class="flex items-center justify-between"><p class="text-[9px] text-emerald-200/60 font-bold">TABLES</p><p class="text-sm font-black text-white">{{ $occupiedTables ?? 0 }}/{{ $totalTables ?? 0 }}</p></div>
                <div class="cl-progress mt-1.5"><div class="cl-progress-fill" style="width: {{ ($totalTables ?? 0) > 0 ? round(($occupiedTables ?? 0) / ($totalTables ?? 1) * 100) : 0 }}%"></div></div>
            </div>
            @else
            <div class="cl-metric mb-4">
                <p class="text-[9px] text-emerald-200/60 font-bold">MONTHLY</p>
                <p class="text-lg font-black text-white mt-1">Rs.{{ number_format($monthSales ?? $monthStats->revenue ?? 0) }}</p>
            </div>
            @endif

            @if(isset($isAdmin) && $isAdmin && $isRestaurant)
            <div class="border-t border-white/10 pt-3 mb-4 space-y-2">
                <div class="flex items-center justify-between"><span class="text-[9px] text-emerald-200/60 font-bold">PROFIT</span><span class="text-sm font-black {{ ($todayProfit ?? 0) >= 0 ? 'text-emerald-300' : 'text-red-300' }}">Rs.{{ number_format($todayProfit ?? 0) }}</span></div>
                <div class="flex items-center justify-between"><span class="text-[9px] text-emerald-200/60 font-bold">COST</span><span class="text-sm font-black text-white">Rs.{{ number_format($todayCost ?? 0) }}</span></div>
                <div class="flex items-center justify-between"><span class="text-[9px] text-emerald-200/60 font-bold">MARGIN</span><span class="text-sm font-black text-emerald-300">{{ ($todaySales ?? 0) > 0 ? round(($todayProfit ?? 0) / ($todaySales ?? 1) * 100) : 0 }}%</span></div>
                <div class="flex items-center justify-between"><span class="text-[9px] text-emerald-200/60 font-bold">TAX</span><span class="text-sm font-black text-cyan-300">Rs.{{ number_format($todayTax ?? $todayStats->tax ?? 0) }}</span></div>
            </div>
            @endif

            <div class="border-t border-white/10 pt-3">
                <p class="text-[8px] text-emerald-200/40 font-bold uppercase tracking-widest mb-2">Quick Actions</p>
                @if($isRestaurant)
                <a href="{{ route('pos.restaurant.pos') }}" class="cl-nav-btn"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>POS Screen</a>
                <a href="{{ route('pos.transactions') }}" class="cl-nav-btn"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>Orders</a>
                <a href="{{ route('pos.restaurant.tables') }}" class="cl-nav-btn"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>Tables</a>
                <a href="{{ route('pos.restaurant.kds') }}" class="cl-nav-btn"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>Kitchen</a>
                <a href="{{ route('pos.products') }}" class="cl-nav-btn"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>Menu</a>
                <a href="{{ route('pos.restaurant.ingredients') }}" class="cl-nav-btn"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>Ingredients</a>
                @else
                <a href="{{ route('pos.invoice.create') }}" class="cl-nav-btn"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>New Sale</a>
                <a href="{{ route('pos.transactions') }}" class="cl-nav-btn">Orders</a>
                <a href="{{ route('pos.products') }}" class="cl-nav-btn">Products</a>
                <a href="{{ route('pos.customers') }}" class="cl-nav-btn">Customers</a>
                <a href="{{ route('pos.reports') }}" class="cl-nav-btn">Reports</a>
                @endif
            </div>
        </div>

        <div class="lg:col-span-3 space-y-4">
            @if($isRestaurant && isset($salesChartLabels))
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 cl-anim cl-d3">
                <div class="sm:col-span-2 cl-card p-4">
                    <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">Weekly Revenue</h2>
                    <div style="height: 180px;"><canvas id="salesChart"></canvas></div>
                </div>
                @if(isset($orderTypeCounts))
                <div class="cl-card p-4">
                    <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">Order Types</h2>
                    <div style="height: 180px;"><canvas id="orderTypeChart"></canvas></div>
                </div>
                @endif
            </div>
            @endif

            @if(!$isRestaurant && isset($paymentBreakdown))
            <div class="cl-card p-4 cl-anim cl-d3">
                <h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase mb-3">Payment Methods</h3>
                <div class="flex flex-wrap gap-3">
                    @forelse($paymentBreakdown as $pb)
                    <div class="flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-50 dark:bg-gray-800">
                        <span class="w-3 h-3 rounded-full {{ $pb->payment_method === 'cash' ? 'bg-emerald-500' : 'bg-blue-500' }}"></span>
                        <div><p class="text-[11px] font-bold text-gray-900 dark:text-white">{{ ucwords(str_replace('_', ' ', $pb->payment_method)) }}</p><p class="text-[9px] text-gray-400">{{ $pb->count }} txns — Rs.{{ number_format($pb->total) }}</p></div>
                    </div>
                    @empty<p class="text-[11px] text-gray-400">No sales</p>@endforelse
                </div>
            </div>
            @endif

            @if($isRestaurant)
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 cl-anim cl-d4">
                <div class="cl-card overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 bg-emerald-50/50 dark:bg-emerald-900/10">
                        <h2 class="text-[11px] font-bold text-emerald-700 dark:text-emerald-400 uppercase">🏆 Top Sellers</h2>
                    </div>
                    <div class="p-3 space-y-1">
                        @forelse(($topProducts ?? collect())->take(5) as $idx => $p)
                        <div class="flex items-center gap-2 py-2 px-2 rounded-lg hover:bg-emerald-50/50 dark:hover:bg-emerald-900/10 transition">
                            <span class="w-6 h-6 rounded-full flex items-center justify-center text-[9px] font-black flex-shrink-0 {{ $idx < 3 ? 'bg-emerald-500 text-white' : 'bg-gray-100 text-gray-400 dark:bg-gray-800' }}">{{ $idx + 1 }}</span>
                            <p class="flex-1 text-[11px] font-bold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p>
                            <span class="text-[11px] font-black text-emerald-600 dark:text-emerald-400">Rs.{{ number_format($p->total_revenue) }}</span>
                        </div>
                        @empty<p class="text-[11px] text-gray-400 py-4 text-center">No data</p>@endforelse
                    </div>
                </div>
                <div class="cl-card overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 bg-{{ ($lowStockItems ?? collect())->count() > 0 ? 'red' : 'green' }}-50/50 dark:bg-{{ ($lowStockItems ?? collect())->count() > 0 ? 'red' : 'green' }}-900/10 flex items-center justify-between">
                        <h2 class="text-[11px] font-bold {{ ($lowStockItems ?? collect())->count() > 0 ? 'text-red-700 dark:text-red-400' : 'text-green-700 dark:text-green-400' }} uppercase">📦 Stock Alerts</h2>
                        @if(($lowStockItems ?? collect())->count() > 0)<span class="text-[8px] font-bold px-2 py-0.5 rounded-full bg-red-500 text-white">{{ $lowStockItems->count() }}</span>@endif
                    </div>
                    <div class="p-3 space-y-1">
                        @forelse(($lowStockItems ?? collect())->take(5) as $ing)
                        <div class="flex items-center gap-2 py-2 px-2 rounded-lg transition">
                            <span class="w-2 h-2 rounded-full {{ $ing->current_stock <= 0 ? 'bg-red-500' : 'bg-amber-500' }}"></span>
                            <p class="flex-1 text-[11px] font-bold text-gray-900 dark:text-white truncate">{{ $ing->name }}</p>
                            <span class="text-[9px] font-bold px-2 py-0.5 rounded-full {{ $ing->current_stock <= 0 ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' }}">{{ number_format($ing->current_stock, 1) }} {{ $ing->unit }}</span>
                        </div>
                        @empty
                        <div class="text-center py-6"><svg class="w-8 h-8 mx-auto text-green-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><p class="text-[11px] text-green-600 font-bold">All stocked</p></div>
                        @endforelse
                    </div>
                </div>
            </div>
            @endif

            <div class="cl-card overflow-hidden cl-anim cl-d5">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                    <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent {{ $isRestaurant ? 'Orders' : 'Transactions' }}</h2>
                    <a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-emerald-600 hover:text-emerald-700">VIEW ALL →</a>
                </div>
                <table class="w-full"><thead><tr class="bg-gray-50/60 dark:bg-gray-800/30"><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">{{ $isRestaurant ? 'Order' : 'Invoice' }}</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-3 hidden sm:table-cell">{{ $isRestaurant ? 'Type' : 'Customer' }}</th><th class="text-right text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Amount</th><th class="text-center text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Status</th></tr></thead><tbody>
                    @php $txnList = $isRestaurant ? ($recentOrders ?? $recentTransactions ?? collect()) : ($recentTransactions ?? collect()); @endphp
                    @forelse($txnList->take(8) as $ro)
                    <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-emerald-50/20 dark:hover:bg-emerald-900/5 transition">
                        <td class="py-2.5 px-4 text-[11px] font-bold text-emerald-600">{{ $ro->invoice_number ?? ('#' . $ro->id) }}</td>
                        <td class="py-2.5 px-3 hidden sm:table-cell text-[11px] text-gray-600 dark:text-gray-400">{{ $isRestaurant ? ucwords(str_replace('_', ' ', $ro->order_type ?? '-')) : ($ro->customer_name ?? 'Walk-in') }}</td>
                        <td class="py-2.5 px-3 text-right text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($ro->total_amount) }}</td>
                        <td class="py-2.5 px-3 text-center"><span class="text-[8px] font-bold px-2 py-0.5 rounded-full {{ ($ro->status ?? '') === 'completed' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-amber-100 text-amber-700' }}">{{ ucfirst($ro->status ?? ($ro->created_at->diffForHumans())) }}</span></td>
                    </tr>
                    @empty<tr><td colspan="4" class="py-8 text-center text-[11px] text-gray-400">No data yet</td></tr>@endforelse
                </tbody></table>
            </div>
        </div>
    </div>
</div>
