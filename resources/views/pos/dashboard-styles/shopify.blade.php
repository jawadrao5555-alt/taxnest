<style>
@keyframes shFade { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
.sh-anim { animation: shFade 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards; }
.sh-d1{animation-delay:0ms}.sh-d2{animation-delay:80ms}.sh-d3{animation-delay:160ms}.sh-d4{animation-delay:240ms}.sh-d5{animation-delay:320ms}
.sh-hero { background: linear-gradient(145deg, #0f172a 0%, #1e293b 60%, #334155 100%); border-radius: 24px; position: relative; overflow: hidden; }
.sh-hero::before { content: ''; position: absolute; top: -50%; right: -30%; width: 70%; height: 140%; background: radial-gradient(circle, rgba(99,102,241,0.08) 0%, transparent 60%); }
.sh-hero::after { content: ''; position: absolute; bottom: -40%; left: -20%; width: 50%; height: 100%; background: radial-gradient(circle, rgba(34,197,94,0.06) 0%, transparent 60%); }
.sh-minimal { background: white; border: 1px solid #f1f5f9; border-radius: 14px; transition: all 0.2s; }
.dark .sh-minimal { background: rgb(15,23,42); border-color: rgb(30,41,59); }
.sh-minimal:hover { border-color: #cbd5e1; box-shadow: 0 1px 8px rgba(0,0,0,0.04); }
.sh-green { color: #16a34a; }
.sh-link { font-size: 11px; font-weight: 700; color: #6366f1; transition: color 0.2s; }
.sh-link:hover { color: #4f46e5; }
.dark .sh-link { color: #818cf8; }
</style>

<div class="space-y-5 w-full">
    <div class="sh-hero p-8 sm:p-10 sh-anim sh-d1">
        <div class="relative z-10">
            <div class="flex items-start justify-between gap-4 mb-10">
                <div>
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-[0.2em]">{{ now()->format('l, d M Y') }}</p>
                    <p class="text-sm text-slate-400 mt-2">{{ $company->name ?? 'Business' }}</p>
                </div>
                @include('pos.dashboard-styles._style-picker')
            </div>

            <div class="mb-2">
                <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Today's Revenue</p>
                <p class="text-5xl sm:text-6xl font-black text-white mt-2 tracking-tight" style="font-variant-numeric:tabular-nums;letter-spacing:-0.03em">Rs.{{ number_format($todaySales ?? $todayStats->revenue ?? 0) }}</p>
                @php $yest=$yesterdaySales??0;$today=$todaySales??$todayStats->revenue??0;$pct=$yest>0?round(($today-$yest)/$yest*100):0; @endphp
                @if($pct != 0)
                <div class="flex items-center gap-2 mt-3">
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[10px] font-bold {{ $pct >= 0 ? 'bg-emerald-500/15 text-emerald-400' : 'bg-red-500/15 text-red-400' }}">
                        <svg class="w-3 h-3 {{ $pct < 0 ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z"/></svg>
                        {{ abs($pct) }}% from yesterday
                    </span>
                </div>
                @endif
            </div>

            <div class="flex gap-10 mt-8 pt-6 border-t border-white/5">
                <div><p class="text-3xl font-black text-white">{{ $todayOrders ?? $todayStats->count ?? 0 }}</p><p class="text-[9px] text-slate-500 font-bold uppercase tracking-wider mt-1">Orders</p></div>
                <div><p class="text-3xl font-black text-white">Rs.{{ number_format($todayStats->avg_ticket ?? (($todayOrders ?? 0) > 0 ? ($todaySales ?? 0) / ($todayOrders ?? 1) : 0)) }}</p><p class="text-[9px] text-slate-500 font-bold uppercase tracking-wider mt-1">Avg Ticket</p></div>
                @if($isRestaurant)
                <div><p class="text-3xl font-black text-white">{{ $occupiedTables ?? 0 }}<span class="text-xl text-slate-600">/{{ $totalTables ?? 0 }}</span></p><p class="text-[9px] text-slate-500 font-bold uppercase tracking-wider mt-1">Tables</p></div>
                @else
                <div><p class="text-3xl font-black text-white">Rs.{{ number_format(($monthSales ?? $monthStats->revenue ?? 0) / 1000) }}k</p><p class="text-[9px] text-slate-500 font-bold uppercase tracking-wider mt-1">Monthly</p></div>
                @endif
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3 overflow-x-auto pb-1 sh-anim sh-d2">
        @if($isRestaurant)
        <a href="{{ route('pos.restaurant.pos') }}" class="flex-shrink-0 px-5 py-2.5 rounded-xl bg-indigo-600 text-white text-[11px] font-bold hover:bg-indigo-700 transition shadow-sm">POS Screen</a>
        <a href="{{ route('pos.transactions') }}" class="flex-shrink-0 px-5 py-2.5 rounded-xl text-[11px] font-bold text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition">Orders</a>
        <a href="{{ route('pos.restaurant.tables') }}" class="flex-shrink-0 px-5 py-2.5 rounded-xl text-[11px] font-bold text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition">Tables</a>
        <a href="{{ route('pos.restaurant.kds') }}" class="flex-shrink-0 px-5 py-2.5 rounded-xl text-[11px] font-bold text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition">Kitchen</a>
        <a href="{{ route('pos.products') }}" class="flex-shrink-0 px-5 py-2.5 rounded-xl text-[11px] font-bold text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition">Menu</a>
        <a href="{{ route('pos.restaurant.ingredients') }}" class="flex-shrink-0 px-5 py-2.5 rounded-xl text-[11px] font-bold text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition">Ingredients</a>
        @else
        <a href="{{ route('pos.invoice.create') }}" class="flex-shrink-0 px-5 py-2.5 rounded-xl bg-indigo-600 text-white text-[11px] font-bold hover:bg-indigo-700 transition shadow-sm">New Sale</a>
        <a href="{{ route('pos.transactions') }}" class="flex-shrink-0 px-5 py-2.5 rounded-xl text-[11px] font-bold text-gray-600 dark:text-gray-400 hover:text-gray-900 transition">Orders</a>
        <a href="{{ route('pos.products') }}" class="flex-shrink-0 px-5 py-2.5 rounded-xl text-[11px] font-bold text-gray-600 dark:text-gray-400 hover:text-gray-900 transition">Products</a>
        <a href="{{ route('pos.customers') }}" class="flex-shrink-0 px-5 py-2.5 rounded-xl text-[11px] font-bold text-gray-600 dark:text-gray-400 hover:text-gray-900 transition">Customers</a>
        <a href="{{ route('pos.reports') }}" class="flex-shrink-0 px-5 py-2.5 rounded-xl text-[11px] font-bold text-gray-600 dark:text-gray-400 hover:text-gray-900 transition">Reports</a>
        @endif
    </div>

    @if(isset($isAdmin) && $isAdmin && $isRestaurant)
    <div class="sh-minimal p-5 sh-anim sh-d2">
        <div class="flex items-center gap-8 flex-wrap">
            <div><p class="text-[9px] text-gray-400 font-bold uppercase tracking-wider">Profit</p><p class="text-xl font-black {{ ($todayProfit ?? 0) >= 0 ? 'sh-green' : 'text-red-600' }} mt-0.5">Rs.{{ number_format($todayProfit ?? 0) }}</p></div>
            <div class="w-px h-10 bg-gray-100 dark:bg-gray-800"></div>
            <div><p class="text-[9px] text-gray-400 font-bold uppercase tracking-wider">Cost</p><p class="text-xl font-black text-gray-900 dark:text-white mt-0.5">Rs.{{ number_format($todayCost ?? 0) }}</p></div>
            <div class="w-px h-10 bg-gray-100 dark:bg-gray-800"></div>
            <div><p class="text-[9px] text-gray-400 font-bold uppercase tracking-wider">Margin</p><p class="text-xl font-black text-indigo-600 mt-0.5">{{ ($todaySales ?? 0) > 0 ? round(($todayProfit ?? 0) / ($todaySales ?? 1) * 100) : 0 }}%</p></div>
            <div class="w-px h-10 bg-gray-100 dark:bg-gray-800"></div>
            <div><p class="text-[9px] text-gray-400 font-bold uppercase tracking-wider">Tax</p><p class="text-xl font-black text-gray-900 dark:text-white mt-0.5">Rs.{{ number_format($todayTax ?? $todayStats->tax ?? 0) }}</p></div>
            <div class="w-px h-10 bg-gray-100 dark:bg-gray-800"></div>
            <div><p class="text-[9px] text-gray-400 font-bold uppercase tracking-wider">Discounts</p><p class="text-xl font-black text-orange-500 mt-0.5">Rs.{{ number_format($todayDiscount ?? 0) }}</p></div>
        </div>
    </div>
    @endif

    @if($isRestaurant && isset($salesChartLabels))
    <div class="sh-minimal p-6 sh-anim sh-d3">
        <div class="flex items-center justify-between mb-4"><h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider">Revenue — Last 7 Days</h2></div>
        <div style="height: 220px;"><canvas id="salesChart"></canvas></div>
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sh-anim sh-d4">
        @if($isRestaurant && isset($orderTypeCounts))
        <div class="sh-minimal p-5">
            <h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider mb-4">Order Types</h2>
            <div style="height: 160px;"><canvas id="orderTypeChart"></canvas></div>
        </div>
        @endif

        @if($isRestaurant)
        <div class="sh-minimal overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800/50"><h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider">Top Sellers</h2></div>
            <div class="divide-y divide-gray-50 dark:divide-gray-800/50">
                @forelse(($topProducts ?? collect())->take(5) as $idx => $p)
                <div class="flex items-center gap-4 px-5 py-3 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition">
                    <span class="text-[10px] font-black text-gray-300 dark:text-gray-600 w-4">{{ $idx + 1 }}</span>
                    <div class="flex-1 min-w-0"><p class="text-[12px] font-bold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p><p class="text-[9px] text-gray-400 mt-0.5">{{ $p->total_qty }} sold</p></div>
                    <p class="text-[12px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($p->total_revenue) }}</p>
                </div>
                @empty<div class="py-8 text-center text-[11px] text-gray-400">No data</div>@endforelse
            </div>
        </div>
        @endif

        @if(!$isRestaurant && isset($paymentBreakdown))
        <div class="sh-minimal p-5">
            <h3 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider mb-4">Payment Split</h3>
            @forelse($paymentBreakdown as $pb)
            <div class="flex items-center gap-3 py-3 border-b border-gray-50 dark:border-gray-800/50 last:border-0">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center {{ $pb->payment_method === 'cash' ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-indigo-50 dark:bg-indigo-900/20' }}">
                    @if($pb->payment_method === 'cash')<svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>@else<svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>@endif
                </div>
                <div class="flex-1"><p class="text-[12px] font-bold text-gray-900 dark:text-white">{{ ucwords(str_replace('_', ' ', $pb->payment_method)) }}</p><p class="text-[9px] text-gray-400">{{ $pb->count }} transactions</p></div>
                <p class="text-sm font-black text-gray-900 dark:text-white">Rs.{{ number_format($pb->total) }}</p>
            </div>
            @empty<p class="text-[11px] text-gray-400 py-6 text-center">No sales today</p>@endforelse
        </div>
        @endif
    </div>

    @if($isRestaurant)
    <div class="sh-minimal overflow-hidden sh-anim sh-d4">
        <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800/50 flex items-center justify-between bg-{{ ($lowStockItems ?? collect())->count() > 0 ? 'amber-50/30 dark:bg-transparent' : 'transparent' }}">
            <h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider">Inventory</h2>
            @if(($lowStockItems ?? collect())->count() > 0)<span class="text-[9px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">{{ $lowStockItems->count() }} low</span>@endif
        </div>
        @if(($lowStockItems ?? collect())->count() > 0)
        <div class="divide-y divide-gray-50 dark:divide-gray-800/50">
            @foreach(($lowStockItems ?? collect())->take(4) as $ing)
            <div class="flex items-center gap-3 px-5 py-3">
                <span class="w-2 h-2 rounded-full {{ $ing->current_stock <= 0 ? 'bg-red-500' : 'bg-amber-500' }}"></span>
                <p class="flex-1 text-[12px] font-bold text-gray-900 dark:text-white">{{ $ing->name }}</p>
                <span class="text-[10px] font-bold {{ $ing->current_stock <= 0 ? 'text-red-600' : 'text-amber-600' }}">{{ number_format($ing->current_stock, 1) }} {{ $ing->unit }}</span>
            </div>
            @endforeach
        </div>
        @else
        <div class="py-8 text-center"><p class="text-[12px] sh-green font-bold">✓ All ingredients in stock</p></div>
        @endif
    </div>
    @endif

    <div class="sh-minimal overflow-hidden sh-anim sh-d5">
        <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800/50 flex items-center justify-between">
            <h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider">Recent {{ $isRestaurant ? 'Orders' : 'Transactions' }}</h2>
            <a href="{{ route('pos.transactions') }}" class="sh-link">View all →</a>
        </div>
        <table class="w-full"><thead><tr class="text-left text-[9px] font-bold text-gray-400 uppercase tracking-wider bg-gray-50/40 dark:bg-gray-800/20"><th class="py-2.5 px-5">{{ $isRestaurant ? 'Order' : 'Invoice' }}</th><th class="py-2.5 px-3 hidden sm:table-cell">{{ $isRestaurant ? 'Type' : 'Customer' }}</th><th class="py-2.5 px-3 text-right">Amount</th><th class="py-2.5 px-3 text-center">Status</th></tr></thead><tbody>
            @php $txnList = $isRestaurant ? ($recentOrders ?? $recentTransactions ?? collect()) : ($recentTransactions ?? collect()); @endphp
            @forelse($txnList->take(8) as $ro)
            <tr class="border-b border-gray-50 dark:border-gray-800/30 hover:bg-gray-50/50 dark:hover:bg-gray-800/20 transition">
                <td class="py-3 px-5 text-[12px] font-bold text-indigo-600 dark:text-indigo-400">{{ $ro->invoice_number ?? ('#' . $ro->id) }}</td>
                <td class="py-3 px-3 hidden sm:table-cell text-[11px] text-gray-500 dark:text-gray-400">{{ $isRestaurant ? ucwords(str_replace('_', ' ', $ro->order_type ?? '-')) : ($ro->customer_name ?? 'Walk-in') }}</td>
                <td class="py-3 px-3 text-right text-[12px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($ro->total_amount) }}</td>
                <td class="py-3 px-3 text-center"><span class="text-[9px] font-bold px-2.5 py-1 rounded-lg {{ ($ro->status ?? '') === 'completed' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400' : 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400' }}">{{ ucfirst($ro->status ?? ($ro->created_at->diffForHumans())) }}</span></td>
            </tr>
            @empty<tr><td colspan="4" class="py-10 text-center text-[12px] text-gray-400">No {{ $isRestaurant ? 'orders' : 'transactions' }} yet</td></tr>@endforelse
        </tbody></table>
    </div>
</div>
