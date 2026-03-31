<style>
@keyframes osUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.os-anim { animation: osUp 0.3s ease forwards; }
.os-d1 { animation-delay: 0ms; } .os-d2 { animation-delay: 60ms; } .os-d3 { animation-delay: 120ms; } .os-d4 { animation-delay: 180ms; } .os-d5 { animation-delay: 240ms; }
.os-card { background: white; border: 1px solid #e5e7eb; border-radius: 12px; }
.dark .os-card { background: rgb(17,24,39); border-color: rgb(31,41,55); }
.os-banner { background: linear-gradient(135deg, #0c4a6e 0%, #0369a1 100%); border-radius: 14px; }
.os-stat { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; position: relative; }
.dark .os-stat { background: rgb(17,24,39); border-color: rgb(31,41,55); }
.os-stat-accent { position: absolute; top: 0; left: 0; width: 100%; height: 3px; border-radius: 10px 10px 0 0; }
.os-compare { display: flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; font-size: 9px; font-weight: 700; }
</style>

<div class="space-y-4 w-full">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 os-anim os-d1">
        <div>
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-sky-600 flex items-center justify-center"><svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg></div>
                <div>
                    <h1 class="text-lg font-black text-gray-900 dark:text-white">{{ $company->name ?? 'Business' }}</h1>
                    <p class="text-[10px] text-gray-400">{{ now()->format('l, d M Y') }} — FBR/PRA Compliant POS</p>
                </div>
            </div>
        </div>
        @include('pos.dashboard-styles._style-picker')
    </div>

    <div class="os-banner p-4 flex flex-col sm:flex-row items-center justify-between gap-3 os-anim os-d2">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center flex-shrink-0"><svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg></div>
            <div>
                <p class="text-[10px] font-bold text-sky-200/70 uppercase tracking-wider">Tax Compliance Status</p>
                <p class="text-sm font-bold text-white">Rs. {{ number_format($todayTax ?? $todayStats->tax ?? 0) }} collected today | Rs. {{ number_format($monthTax ?? $monthStats->tax ?? 0) }} this month</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span class="px-3 py-1 rounded-lg text-[10px] font-bold bg-white/10 text-emerald-300">PRA {{ ($company->pra_reporting_enabled ?? false) ? 'Active' : 'Inactive' }}</span>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 os-anim os-d3">
        <div class="os-stat">
            <div class="os-stat-accent bg-emerald-500"></div>
            <div class="flex items-center justify-between mb-1"><p class="text-[9px] font-bold text-gray-400 uppercase">Today's Sales</p>
            @php $yest = $yesterdaySales ?? 0; $today = $todaySales ?? $todayStats->revenue ?? 0; @endphp
            @if($yest > 0)<span class="os-compare {{ $today >= $yest ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">{{ $today >= $yest ? '↑' : '↓' }} {{ abs(round(($today - $yest) / $yest * 100)) }}%</span>@endif
            </div>
            <p class="text-xl font-black text-gray-900 dark:text-white">Rs.{{ number_format($today) }}</p>
            <p class="text-[9px] text-gray-400 mt-1">vs Rs.{{ number_format($yest) }} yesterday</p>
        </div>
        <div class="os-stat">
            <div class="os-stat-accent bg-blue-500"></div>
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Orders Today</p>
            <p class="text-xl font-black text-gray-900 dark:text-white">{{ $todayOrders ?? $todayStats->count ?? 0 }}</p>
            <p class="text-[9px] text-gray-400 mt-1">{{ $heldCount ?? 0 }} held</p>
        </div>
        <div class="os-stat">
            <div class="os-stat-accent bg-purple-500"></div>
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Avg Ticket</p>
            <p class="text-xl font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayStats->avg_ticket ?? (($todayOrders ?? 0) > 0 ? ($todaySales ?? 0) / ($todayOrders ?? 1) : 0)) }}</p>
            @if(isset($peakHour))<p class="text-[9px] text-gray-400 mt-1">Peak: {{ $peakHour }}</p>@endif
        </div>
        @if($isRestaurant)
        <div class="os-stat">
            <div class="os-stat-accent bg-amber-500"></div>
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Tables</p>
            <p class="text-xl font-black text-gray-900 dark:text-white">{{ $occupiedTables ?? 0 }}<span class="text-sm text-gray-300">/{{ $totalTables ?? 0 }}</span></p>
            <p class="text-[9px] text-gray-400 mt-1">{{ ($totalTables ?? 0) > 0 ? round(($occupiedTables ?? 0) / ($totalTables ?? 1) * 100) : 0 }}% occupancy</p>
        </div>
        @else
        <div class="os-stat">
            <div class="os-stat-accent bg-amber-500"></div>
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Monthly Revenue</p>
            <p class="text-xl font-black text-gray-900 dark:text-white">Rs.{{ number_format($monthSales ?? $monthStats->revenue ?? 0) }}</p>
            <p class="text-[9px] text-gray-400 mt-1">{{ $monthStats->count ?? 0 }} orders</p>
        </div>
        @endif
    </div>

    @if(isset($isAdmin) && $isAdmin && $isRestaurant)
    <div class="grid grid-cols-3 gap-3 os-anim os-d3">
        <div class="os-card p-3"><p class="text-[8px] text-gray-400 font-bold uppercase">Gross Profit</p><p class="text-base font-black {{ ($todayProfit ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">Rs.{{ number_format($todayProfit ?? 0) }}</p></div>
        <div class="os-card p-3"><p class="text-[8px] text-gray-400 font-bold uppercase">Cost</p><p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayCost ?? 0) }}</p></div>
        <div class="os-card p-3"><p class="text-[8px] text-gray-400 font-bold uppercase">Margin</p><p class="text-base font-black text-indigo-600">{{ ($todaySales ?? 0) > 0 ? round(($todayProfit ?? 0) / ($todaySales ?? 1) * 100) : 0 }}%</p></div>
    </div>
    @endif

    @if($isRestaurant && isset($salesChartLabels))
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 os-anim os-d4">
        <div class="lg:col-span-2 os-card p-4">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase mb-3">Revenue — Last 7 Days</h2>
            <div style="height: 170px;"><canvas id="salesChart"></canvas></div>
        </div>
        @if(isset($orderTypeCounts))
        <div class="os-card p-4">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase mb-3">Order Types</h2>
            <div style="height: 170px;"><canvas id="orderTypeChart"></canvas></div>
        </div>
        @endif
    </div>
    @endif

    @if(!$isRestaurant && isset($paymentBreakdown))
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 os-anim os-d4">
        <div class="os-card p-4">
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
        <div class="lg:col-span-2 os-card p-4">
            <div class="flex items-center justify-between mb-3"><h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Transactions</h3><a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-sky-600">VIEW ALL</a></div>
            <div class="overflow-x-auto"><table class="w-full"><thead><tr class="text-left text-[9px] font-bold text-gray-400 uppercase border-b border-gray-100 dark:border-gray-800"><th class="pb-2 pr-3">Invoice</th><th class="pb-2 pr-3">Customer</th><th class="pb-2 pr-3 text-right">Amount</th><th class="pb-2">Time</th></tr></thead><tbody>
                @forelse($recentTransactions as $txn)
                <tr class="border-b border-gray-50 dark:border-gray-800/50 last:border-0 hover:bg-sky-50/30 transition">
                    <td class="py-2 pr-3 text-[11px] font-bold text-sky-600">{{ $txn->invoice_number }}</td>
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
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 os-anim os-d5">
        <div class="os-card overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800"><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Top Items</h2></div>
            <div class="p-2 space-y-0.5">@forelse(($topProducts ?? collect())->take(5) as $idx => $p)<div class="flex items-center gap-2 py-1.5 px-2 rounded-lg hover:bg-sky-50/50 transition"><span class="w-5 h-5 rounded flex items-center justify-center text-[9px] font-extrabold {{ $idx < 3 ? 'bg-sky-100 text-sky-700' : 'bg-gray-100 text-gray-400' }}">{{ $idx + 1 }}</span><p class="flex-1 text-[11px] font-semibold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p><span class="text-[9px] text-gray-400 font-mono">{{ $p->total_qty }}x</span><span class="text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($p->total_revenue) }}</span></div>@empty<p class="text-[11px] text-gray-400 py-6 text-center">No data</p>@endforelse</div>
        </div>
        <div class="os-card overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between"><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Stock Alerts</h2>@if(($lowStockItems ?? collect())->count() > 0)<span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full bg-red-100 text-red-600">{{ $lowStockItems->count() }}</span>@endif</div>
            <div class="p-2 space-y-0.5">@forelse(($lowStockItems ?? collect())->take(5) as $ing)<div class="flex items-center gap-2 py-1.5 px-2 rounded-lg hover:bg-red-50/50 transition"><span class="w-2 h-2 rounded-full {{ $ing->current_stock <= 0 ? 'bg-red-500' : 'bg-amber-500' }}"></span><p class="flex-1 text-[11px] font-semibold text-gray-900 dark:text-white truncate">{{ $ing->name }}</p><span class="text-[9px] font-bold px-1.5 py-0.5 rounded {{ $ing->current_stock <= 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }}">{{ number_format($ing->current_stock, 1) }} {{ $ing->unit }}</span></div>@empty<div class="text-center py-6"><p class="text-[11px] text-green-600 font-semibold">All stocked</p></div>@endforelse</div>
        </div>
    </div>
    <div class="os-card overflow-hidden os-anim os-d5">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between"><h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Orders</h2><a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-sky-600">VIEW ALL</a></div>
        <div class="overflow-x-auto"><table class="w-full"><thead><tr class="bg-gray-50/60 dark:bg-gray-800/30"><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Order</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-3 hidden sm:table-cell">Type</th><th class="text-right text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Amount</th><th class="text-center text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Status</th></tr></thead><tbody>
            @forelse(($recentOrders ?? $recentTransactions ?? collect())->take(6) as $ro)
            <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-sky-50/30 transition"><td class="py-2 px-4 text-[11px] font-bold text-sky-600">{{ $ro->invoice_number ?? ('#' . $ro->id) }}</td><td class="py-2 px-3 hidden sm:table-cell"><span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-500">{{ ucwords(str_replace('_', ' ', $ro->order_type ?? $ro->payment_method ?? '-')) }}</span></td><td class="py-2 px-3 text-right text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($ro->total_amount) }}</td><td class="py-2 px-3 text-center"><span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full {{ ($ro->status ?? '') === 'completed' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' }}">{{ ucfirst($ro->status ?? 'pending') }}</span></td></tr>
            @empty
            <tr><td colspan="4" class="py-6 text-center text-[11px] text-gray-400">No orders</td></tr>
            @endforelse
        </tbody></table></div>
    </div>
    @endif
</div>
