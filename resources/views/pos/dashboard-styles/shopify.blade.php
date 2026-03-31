<style>
@keyframes shFade { from { opacity: 0; } to { opacity: 1; } }
@keyframes shSlide { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
.sh-anim { animation: shSlide 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards; }
.sh-d1 { animation-delay: 0ms; } .sh-d2 { animation-delay: 80ms; } .sh-d3 { animation-delay: 160ms; } .sh-d4 { animation-delay: 240ms; } .sh-d5 { animation-delay: 320ms; }
.sh-hero { background: linear-gradient(145deg, #0f172a 0%, #1e293b 50%, #334155 100%); border-radius: 24px; position: relative; overflow: hidden; }
.sh-hero::before { content: ''; position: absolute; top: -50%; right: -30%; width: 70%; height: 140%; background: radial-gradient(circle, rgba(99,102,241,0.08) 0%, transparent 60%); }
.sh-hero::after { content: ''; position: absolute; bottom: -40%; left: -20%; width: 50%; height: 100%; background: radial-gradient(circle, rgba(168,85,247,0.06) 0%, transparent 60%); }
.sh-glass { background: rgba(255,255,255,0.9); backdrop-filter: blur(20px); border: 1px solid rgba(0,0,0,0.04); border-radius: 16px; }
.dark .sh-glass { background: rgba(15,23,42,0.9); border-color: rgba(255,255,255,0.06); }
.sh-gauge { width: 80px; height: 80px; position: relative; }
.sh-gauge svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.sh-gauge-track { fill: none; stroke: rgba(255,255,255,0.1); stroke-width: 6; }
.sh-gauge-fill { fill: none; stroke-width: 6; stroke-linecap: round; transition: stroke-dashoffset 1s ease; }
.sh-gauge-text { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; }
.sh-activity { border-left: 2px solid #e5e7eb; }
.dark .sh-activity { border-left-color: rgb(31,41,55); }
.sh-dot { width: 8px; height: 8px; border-radius: 50%; position: absolute; left: -5px; }
</style>

<div class="space-y-5 w-full">
    <div class="sh-hero p-6 sm:p-8 sh-anim sh-d1">
        <div class="relative z-10">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-6">
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]">{{ now()->format('l, d M Y') }}</p>
                    <h1 class="text-2xl sm:text-3xl font-black text-white mt-2">Welcome back.</h1>
                    <p class="text-sm text-slate-400 mt-1">{{ $company->name ?? 'Business' }}</p>
                </div>
                @include('pos.dashboard-styles._style-picker')
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-8">
                <div>
                    <p class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">Revenue</p>
                    <p class="text-2xl font-black text-white mt-1">Rs.{{ number_format($todaySales ?? $todayStats->revenue ?? 0) }}</p>
                    @php $yest = $yesterdaySales ?? 0; $today = $todaySales ?? $todayStats->revenue ?? 0; $pct = $yest > 0 ? round(($today - $yest) / $yest * 100) : 0; @endphp
                    @if($pct != 0)
                    <span class="inline-flex items-center gap-0.5 mt-1 text-[9px] font-bold {{ $pct >= 0 ? 'text-emerald-400' : 'text-red-400' }}">
                        <svg class="w-3 h-3 {{ $pct < 0 ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z"/></svg>
                        {{ abs($pct) }}%
                    </span>
                    @endif
                </div>
                <div>
                    <p class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">Orders</p>
                    <p class="text-2xl font-black text-white mt-1">{{ $todayOrders ?? $todayStats->count ?? 0 }}</p>
                    <p class="text-[9px] text-slate-500 mt-1">{{ $heldCount ?? 0 }} held</p>
                </div>
                <div>
                    <p class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">Avg Ticket</p>
                    <p class="text-2xl font-black text-white mt-1">Rs.{{ number_format($todayStats->avg_ticket ?? (($todayOrders ?? 0) > 0 ? ($todaySales ?? 0) / ($todayOrders ?? 1) : 0)) }}</p>
                    @if(isset($peakHour))<p class="text-[9px] text-slate-500 mt-1">Peak {{ $peakHour }}</p>@endif
                </div>
                @if($isRestaurant)
                <div>
                    <p class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">Tables</p>
                    <p class="text-2xl font-black text-white mt-1">{{ $occupiedTables ?? 0 }}<span class="text-lg text-slate-500">/{{ $totalTables ?? 0 }}</span></p>
                </div>
                @else
                <div>
                    <p class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">Monthly</p>
                    <p class="text-2xl font-black text-white mt-1">Rs.{{ number_format($monthSales ?? $monthStats->revenue ?? 0) }}</p>
                </div>
                @endif
            </div>
        </div>
    </div>

    @if(isset($isAdmin) && $isAdmin && $isRestaurant)
    <div class="grid grid-cols-3 gap-3 sh-anim sh-d2">
        <div class="sh-glass p-4 border-l-4 border-l-emerald-500">
            <p class="text-[8px] text-gray-400 font-bold uppercase">Gross Profit</p>
            <p class="text-lg font-black {{ ($todayProfit ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">Rs.{{ number_format($todayProfit ?? 0) }}</p>
        </div>
        <div class="sh-glass p-4">
            <p class="text-[8px] text-gray-400 font-bold uppercase">Cost</p>
            <p class="text-lg font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayCost ?? 0) }}</p>
        </div>
        <div class="sh-glass p-4">
            <p class="text-[8px] text-gray-400 font-bold uppercase">Margin</p>
            <p class="text-lg font-black text-indigo-600">{{ ($todaySales ?? 0) > 0 ? round(($todayProfit ?? 0) / ($todaySales ?? 1) * 100) : 0 }}%</p>
        </div>
    </div>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sh-anim sh-d2">
        <div class="sh-glass p-4">
            <div class="flex items-center gap-2 mb-2"><div class="w-7 h-7 rounded-lg bg-cyan-50 dark:bg-cyan-900/20 flex items-center justify-center"><svg class="w-3.5 h-3.5 text-cyan-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg></div><p class="text-[8px] text-gray-400 font-bold uppercase">Tax</p></div>
            <p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayTax ?? $todayStats->tax ?? 0) }}</p>
        </div>
        <div class="sh-glass p-4">
            <div class="flex items-center gap-2 mb-2"><div class="w-7 h-7 rounded-lg bg-orange-50 dark:bg-orange-900/20 flex items-center justify-center"><svg class="w-3.5 h-3.5 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg></div><p class="text-[8px] text-gray-400 font-bold uppercase">Discounts</p></div>
            <p class="text-base font-black text-orange-600">Rs.{{ number_format($todayDiscount ?? 0) }}</p>
        </div>
        <div class="sh-glass p-4">
            <div class="flex items-center gap-2 mb-2"><div class="w-7 h-7 rounded-lg bg-green-50 dark:bg-green-900/20 flex items-center justify-center"><svg class="w-3.5 h-3.5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><p class="text-[8px] text-gray-400 font-bold uppercase">Completed</p></div>
            <p class="text-base font-black text-green-600">{{ $completedCount ?? $todayStats->count ?? 0 }}</p>
        </div>
        <div class="sh-glass p-4">
            <div class="flex items-center gap-2 mb-2"><div class="w-7 h-7 rounded-lg {{ ($lowStockItems ?? collect())->count() > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20' }} flex items-center justify-center"><svg class="w-3.5 h-3.5 {{ ($lowStockItems ?? collect())->count() > 0 ? 'text-red-600' : 'text-green-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg></div><p class="text-[8px] text-gray-400 font-bold uppercase">Alerts</p></div>
            <p class="text-base font-black {{ ($lowStockItems ?? collect())->count() > 0 ? 'text-red-600' : 'text-green-600' }}">{{ ($lowStockItems ?? collect())->count() }}</p>
        </div>
    </div>

    @if($isRestaurant && isset($salesChartLabels))
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 sh-anim sh-d3">
        <div class="lg:col-span-2 sh-glass p-4">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase mb-3">Revenue — Last 7 Days</h2>
            <div style="height: 180px;"><canvas id="salesChart"></canvas></div>
        </div>
        @if(isset($orderTypeCounts))
        <div class="sh-glass p-4">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase mb-3">Order Types</h2>
            <div style="height: 180px;"><canvas id="orderTypeChart"></canvas></div>
        </div>
        @endif
    </div>
    @endif

    @if(!$isRestaurant && isset($paymentBreakdown))
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 sh-anim sh-d3">
        <div class="sh-glass p-4">
            <h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase mb-3">Payment Split</h3>
            @forelse($paymentBreakdown as $pb)
            <div class="flex items-center justify-between py-2.5 border-b border-gray-100/50 dark:border-gray-800/50 last:border-0">
                <div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full {{ $pb->payment_method === 'cash' ? 'bg-emerald-500' : 'bg-indigo-500' }}"></span><span class="text-[11px] font-semibold text-gray-700 dark:text-gray-300">{{ ucwords(str_replace('_', ' ', $pb->payment_method)) }}</span></div>
                <span class="text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($pb->total) }}</span>
            </div>
            @empty
            <p class="text-[11px] text-gray-400 py-4 text-center">No sales today</p>
            @endforelse
        </div>
        <div class="lg:col-span-2 sh-glass p-4">
            <div class="flex items-center justify-between mb-3"><h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Transactions</h3><a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-indigo-600 dark:text-indigo-400">VIEW ALL</a></div>
            <div class="overflow-x-auto"><table class="w-full"><thead><tr class="text-left text-[9px] font-bold text-gray-400 uppercase border-b border-gray-100 dark:border-gray-800"><th class="pb-2 pr-3">Invoice</th><th class="pb-2 pr-3">Customer</th><th class="pb-2 pr-3 text-right">Amount</th><th class="pb-2">Time</th></tr></thead><tbody>
                @forelse($recentTransactions as $txn)
                <tr class="border-b border-gray-50 dark:border-gray-800/50 last:border-0 hover:bg-indigo-50/30 dark:hover:bg-indigo-900/5 transition">
                    <td class="py-2.5 pr-3 text-[11px] font-bold text-indigo-600 dark:text-indigo-400">{{ $txn->invoice_number }}</td>
                    <td class="py-2.5 pr-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                    <td class="py-2.5 pr-3 text-right text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount) }}</td>
                    <td class="py-2.5 text-[10px] text-gray-400">{{ $txn->created_at->diffForHumans() }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="py-6 text-center text-[11px] text-gray-400">No transactions</td></tr>
                @endforelse
            </tbody></table></div>
        </div>
    </div>
    @endif

    @if($isRestaurant)
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 sh-anim sh-d4">
        <div class="sh-glass overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800/50"><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Top Sellers</h2></div>
            <div class="p-2 space-y-0.5">@forelse(($topProducts ?? collect())->take(5) as $idx => $p)<div class="flex items-center gap-2 py-1.5 px-2 rounded-lg hover:bg-indigo-50/50 dark:hover:bg-indigo-900/10 transition"><span class="w-5 h-5 rounded-md flex items-center justify-center text-[9px] font-extrabold {{ $idx < 3 ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400' : 'bg-gray-100 text-gray-400' }}">{{ $idx + 1 }}</span><p class="flex-1 text-[11px] font-semibold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p><span class="text-[9px] text-gray-400 font-mono">{{ $p->total_qty }}x</span><span class="text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($p->total_revenue) }}</span></div>@empty<p class="text-[11px] text-gray-400 py-6 text-center">No data</p>@endforelse</div>
        </div>
        <div class="sh-glass overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800/50 flex items-center justify-between"><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Stock Alerts</h2>@if(($lowStockItems ?? collect())->count() > 0)<span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">{{ $lowStockItems->count() }}</span>@endif</div>
            <div class="p-2 space-y-0.5">@forelse(($lowStockItems ?? collect())->take(5) as $ing)<div class="flex items-center gap-2 py-1.5 px-2 rounded-lg hover:bg-red-50/50 transition"><span class="w-2 h-2 rounded-full {{ $ing->current_stock <= 0 ? 'bg-red-500' : 'bg-amber-500' }}"></span><p class="flex-1 text-[11px] font-semibold text-gray-900 dark:text-white truncate">{{ $ing->name }}</p><span class="text-[9px] font-bold px-1.5 py-0.5 rounded {{ $ing->current_stock <= 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }}">{{ number_format($ing->current_stock, 1) }} {{ $ing->unit }}</span></div>@empty<div class="text-center py-6"><p class="text-[11px] text-green-600 font-semibold">All stocked</p></div>@endforelse</div>
        </div>
    </div>
    <div class="sh-glass overflow-hidden sh-anim sh-d5">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800/50 flex items-center justify-between"><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Orders</h2><a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-indigo-600 dark:text-indigo-400">VIEW ALL</a></div>
        <div class="overflow-x-auto"><table class="w-full"><thead><tr class="bg-gray-50/60 dark:bg-gray-800/30"><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Order</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-3 hidden sm:table-cell">Type</th><th class="text-right text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Amount</th><th class="text-center text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Status</th></tr></thead><tbody>
            @forelse(($recentOrders ?? $recentTransactions ?? collect())->take(6) as $ro)
            <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-indigo-50/30 transition"><td class="py-2 px-4 text-[11px] font-bold text-indigo-600 dark:text-indigo-400">{{ $ro->invoice_number ?? ('#' . $ro->id) }}</td><td class="py-2 px-3 hidden sm:table-cell"><span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-500">{{ ucwords(str_replace('_', ' ', $ro->order_type ?? $ro->payment_method ?? '-')) }}</span></td><td class="py-2 px-3 text-right text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($ro->total_amount) }}</td><td class="py-2 px-3 text-center"><span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full {{ ($ro->status ?? '') === 'completed' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' }}">{{ ucfirst($ro->status ?? 'pending') }}</span></td></tr>
            @empty
            <tr><td colspan="4" class="py-6 text-center text-[11px] text-gray-400">No orders</td></tr>
            @endforelse
        </tbody></table></div>
    </div>
    @endif
</div>
