<style>
@keyframes osUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.os-anim { animation: osUp 0.3s ease forwards; }
.os-d1{animation-delay:0ms}.os-d2{animation-delay:60ms}.os-d3{animation-delay:120ms}.os-d4{animation-delay:180ms}.os-d5{animation-delay:240ms}
.os-banner { background: linear-gradient(135deg, #0c4a6e 0%, #0369a1 50%, #0284c7 100%); border-radius: 16px; position: relative; overflow: hidden; }
.os-banner::before { content: ''; position: absolute; right: 0; top: 0; width: 200px; height: 100%; background: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Ccircle cx='100' cy='100' r='80' fill='none' stroke='rgba(255,255,255,0.05)' stroke-width='40'/%3E%3C/svg%3E") no-repeat center; }
.os-stat-strip { display: flex; gap: 1px; background: #e5e7eb; border-radius: 12px; overflow: hidden; }
.dark .os-stat-strip { background: rgb(31,41,55); }
.os-stat-cell { background: white; padding: 12px 16px; flex: 1; min-width: 0; }
.dark .os-stat-cell { background: rgb(17,24,39); }
.os-card { background: white; border: 1px solid #e5e7eb; border-radius: 12px; }
.dark .os-card { background: rgb(17,24,39); border-color: rgb(31,41,55); }
.os-accent-top { position: relative; }
.os-accent-top::before { content: ''; position: absolute; top: 0; left: 16px; right: 16px; height: 3px; border-radius: 0 0 3px 3px; }
.os-accent-blue::before { background: #0284c7; }
.os-accent-emerald::before { background: #059669; }
.os-accent-amber::before { background: #d97706; }
.os-accent-red::before { background: #dc2626; }
.os-timeline { position: relative; padding-left: 20px; }
.os-timeline::before { content: ''; position: absolute; left: 6px; top: 0; bottom: 0; width: 2px; background: #e5e7eb; }
.dark .os-timeline::before { background: rgb(31,41,55); }
.os-timeline-dot { position: absolute; left: 0; width: 14px; height: 14px; border-radius: 50%; border: 2px solid white; }
.dark .os-timeline-dot { border-color: rgb(17,24,39); }
</style>

<div class="space-y-4 w-full">
    <div class="os-banner p-5 os-anim os-d1">
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl bg-white/10 flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <div>
                    <h1 class="text-xl font-black text-white">{{ $company->name ?? 'Business' }}</h1>
                    <p class="text-[10px] text-sky-200/60">{{ now()->format('l, d M Y') }} — FBR/PRA Compliant</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <p class="text-[9px] text-sky-200/50 font-bold uppercase">Tax Collected</p>
                    <p class="text-lg font-black text-white">Rs.{{ number_format($todayTax ?? $todayStats->tax ?? 0) }}</p>
                </div>
                @include('pos.dashboard-styles._style-picker')
            </div>
        </div>
    </div>

    <div class="os-stat-strip os-anim os-d2">
        <div class="os-stat-cell">
            <p class="text-[9px] font-bold text-sky-600 uppercase">Revenue</p>
            <p class="text-xl font-black text-gray-900 dark:text-white" style="font-variant-numeric:tabular-nums">Rs.{{ number_format($todaySales ?? $todayStats->revenue ?? 0) }}</p>
            @php $yest=$yesterdaySales??0;$today=$todaySales??$todayStats->revenue??0;$pct=$yest>0?round(($today-$yest)/$yest*100):0; @endphp
            @if($pct!=0)<p class="text-[9px] font-bold mt-0.5 {{ $pct>=0?'text-emerald-600':'text-red-500' }}">{{ $pct>=0?'▲':'▼' }} {{ abs($pct) }}%</p>@endif
        </div>
        <div class="os-stat-cell">
            <p class="text-[9px] font-bold text-sky-600 uppercase">Orders</p>
            <p class="text-xl font-black text-gray-900 dark:text-white">{{ $todayOrders ?? $todayStats->count ?? 0 }}</p>
            <p class="text-[9px] text-gray-400 mt-0.5">{{ $heldCount ?? 0 }} held</p>
        </div>
        <div class="os-stat-cell">
            <p class="text-[9px] font-bold text-sky-600 uppercase">Avg Ticket</p>
            <p class="text-xl font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayStats->avg_ticket ?? (($todayOrders ?? 0) > 0 ? ($todaySales ?? 0) / ($todayOrders ?? 1) : 0)) }}</p>
        </div>
        <div class="os-stat-cell">
            @if($isRestaurant)
            <p class="text-[9px] font-bold text-sky-600 uppercase">Tables</p>
            <p class="text-xl font-black text-gray-900 dark:text-white">{{ $occupiedTables ?? 0 }}<span class="text-sm text-gray-300">/{{ $totalTables ?? 0 }}</span></p>
            <p class="text-[9px] text-gray-400 mt-0.5">{{ ($totalTables ?? 0) > 0 ? round(($occupiedTables ?? 0) / ($totalTables ?? 1) * 100) : 0 }}% full</p>
            @else
            <p class="text-[9px] font-bold text-sky-600 uppercase">Monthly</p>
            <p class="text-xl font-black text-gray-900 dark:text-white">Rs.{{ number_format($monthSales ?? $monthStats->revenue ?? 0) }}</p>
            <p class="text-[9px] text-gray-400 mt-0.5">{{ $monthStats->count ?? 0 }} orders</p>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap gap-2 os-anim os-d2">
        @if($isRestaurant)
        @php $navItems = [
            ['route' => 'pos.restaurant.pos', 'label' => '📱 POS Screen', 'primary' => true],
            ['route' => 'pos.transactions', 'label' => '📋 Orders'],
            ['route' => 'pos.restaurant.tables', 'label' => '🪑 Tables'],
            ['route' => 'pos.restaurant.kds', 'label' => '👨‍🍳 Kitchen'],
            ['route' => 'pos.products', 'label' => '📦 Menu'],
            ['route' => 'pos.restaurant.ingredients', 'label' => '🧪 Ingredients'],
        ]; @endphp
        @foreach($navItems as $nav)
        <a href="{{ route($nav['route']) }}" class="px-3 py-2 rounded-lg text-[11px] font-bold transition {{ ($nav['primary'] ?? false) ? 'bg-sky-600 text-white hover:bg-sky-700 shadow-sm' : 'bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:border-sky-300 hover:text-sky-600' }}">{{ $nav['label'] }}</a>
        @endforeach
        @else
        <a href="{{ route('pos.invoice.create') }}" class="px-3 py-2 rounded-lg text-[11px] font-bold bg-sky-600 text-white hover:bg-sky-700 shadow-sm transition">+ New Sale</a>
        <a href="{{ route('pos.transactions') }}" class="px-3 py-2 rounded-lg text-[11px] font-bold bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:border-sky-300 transition">Orders</a>
        <a href="{{ route('pos.products') }}" class="px-3 py-2 rounded-lg text-[11px] font-bold bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:border-sky-300 transition">Products</a>
        <a href="{{ route('pos.customers') }}" class="px-3 py-2 rounded-lg text-[11px] font-bold bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:border-sky-300 transition">Customers</a>
        <a href="{{ route('pos.reports') }}" class="px-3 py-2 rounded-lg text-[11px] font-bold bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:border-sky-300 transition">Reports</a>
        @endif
    </div>

    @if(isset($isAdmin) && $isAdmin && $isRestaurant)
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 os-anim os-d3">
        <div class="os-card os-accent-top os-accent-emerald p-3 pt-5"><p class="text-[8px] text-gray-400 font-bold uppercase">Profit</p><p class="text-base font-black {{ ($todayProfit ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">Rs.{{ number_format($todayProfit ?? 0) }}</p></div>
        <div class="os-card os-accent-top os-accent-blue p-3 pt-5"><p class="text-[8px] text-gray-400 font-bold uppercase">Cost</p><p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayCost ?? 0) }}</p></div>
        <div class="os-card os-accent-top os-accent-amber p-3 pt-5"><p class="text-[8px] text-gray-400 font-bold uppercase">Margin</p><p class="text-base font-black text-amber-600">{{ ($todaySales ?? 0) > 0 ? round(($todayProfit ?? 0) / ($todaySales ?? 1) * 100) : 0 }}%</p></div>
        <div class="os-card os-accent-top os-accent-blue p-3 pt-5"><p class="text-[8px] text-gray-400 font-bold uppercase">Tax</p><p class="text-base font-black text-sky-600">Rs.{{ number_format($todayTax ?? $todayStats->tax ?? 0) }}</p></div>
        <div class="os-card os-accent-top os-accent-red p-3 pt-5"><p class="text-[8px] text-gray-400 font-bold uppercase">Discounts</p><p class="text-base font-black text-red-500">Rs.{{ number_format($todayDiscount ?? 0) }}</p></div>
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 os-anim os-d4">
        @if($isRestaurant && isset($salesChartLabels))
        <div class="lg:col-span-2 os-card p-4">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase mb-3">Revenue — Last 7 Days</h2>
            <div style="height: 180px;"><canvas id="salesChart"></canvas></div>
        </div>
        @endif

        @if($isRestaurant && isset($orderTypeCounts))
        <div class="os-card p-4">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase mb-3">Order Types</h2>
            <div style="height: 180px;"><canvas id="orderTypeChart"></canvas></div>
        </div>
        @endif

        @if(!$isRestaurant && isset($paymentBreakdown))
        <div class="os-card p-4">
            <h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase mb-3">Payments</h3>
            @forelse($paymentBreakdown as $pb)
            <div class="flex items-center justify-between py-2 border-b border-gray-50 dark:border-gray-800 last:border-0">
                <span class="text-[11px] font-bold text-gray-700 dark:text-gray-300">{{ ucwords(str_replace('_', ' ', $pb->payment_method)) }}</span>
                <span class="text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($pb->total) }}</span>
            </div>
            @empty<p class="text-[11px] text-gray-400 py-4 text-center">No sales</p>@endforelse
        </div>
        <div class="lg:col-span-2 os-card overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                <h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Transactions</h3>
                <a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-sky-600">VIEW ALL →</a>
            </div>
            <table class="w-full"><thead><tr class="bg-gray-50/60 dark:bg-gray-800/30 text-left text-[9px] font-bold text-gray-400 uppercase"><th class="py-2 px-4">Invoice</th><th class="py-2 px-3">Customer</th><th class="py-2 px-3 text-right">Amount</th><th class="py-2 px-3">Time</th></tr></thead><tbody>
                @forelse($recentTransactions as $txn)
                <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-sky-50/20 transition">
                    <td class="py-2 px-4 text-[11px] font-bold text-sky-600">{{ $txn->invoice_number }}</td>
                    <td class="py-2 px-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                    <td class="py-2 px-3 text-right text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount) }}</td>
                    <td class="py-2 px-3 text-[10px] text-gray-400">{{ $txn->created_at->diffForHumans() }}</td>
                </tr>
                @empty<tr><td colspan="4" class="py-6 text-center text-[11px] text-gray-400">No transactions</td></tr>@endforelse
            </tbody></table>
        </div>
        @endif
    </div>

    @if($isRestaurant)
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 os-anim os-d5">
        <div class="lg:col-span-2 os-card p-4">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase mb-3">🏆 Best Sellers</h2>
            <div class="space-y-2">
                @forelse(($topProducts ?? collect())->take(5) as $idx => $p)
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center text-[10px] font-black flex-shrink-0 {{ $idx === 0 ? 'bg-sky-600 text-white' : ($idx < 3 ? 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-400' : 'bg-gray-100 text-gray-400 dark:bg-gray-800') }}">{{ $idx + 1 }}</div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[11px] font-bold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p>
                        <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-1.5 mt-1"><div class="bg-sky-500 h-1.5 rounded-full" style="width: {{ $idx === 0 ? 100 : max(20, 100 - $idx * 20) }}%"></div></div>
                    </div>
                    <div class="text-right flex-shrink-0"><p class="text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($p->total_revenue) }}</p><p class="text-[9px] text-gray-400">{{ $p->total_qty }}x</p></div>
                </div>
                @empty<p class="text-[11px] text-gray-400 py-4 text-center">No data</p>@endforelse
            </div>
        </div>

        <div class="lg:col-span-3 os-card overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Order Timeline</h2>
                <a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-sky-600">VIEW ALL →</a>
            </div>
            <div class="p-4">
                <div class="os-timeline space-y-4">
                    @forelse(($recentOrders ?? $recentTransactions ?? collect())->take(6) as $ro)
                    <div class="relative pb-1">
                        <div class="os-timeline-dot {{ ($ro->status ?? '') === 'completed' ? 'bg-emerald-500' : 'bg-amber-500' }}" style="top: 2px;"></div>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-[11px] font-bold text-gray-900 dark:text-white">{{ $ro->invoice_number ?? ('#' . $ro->id) }}</p>
                                <p class="text-[9px] text-gray-400">{{ ucwords(str_replace('_', ' ', $ro->order_type ?? $ro->payment_method ?? '-')) }} · {{ $ro->created_at->diffForHumans() }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($ro->total_amount) }}</p>
                                <span class="text-[8px] font-bold {{ ($ro->status ?? '') === 'completed' ? 'text-emerald-600' : 'text-amber-600' }}">{{ ucfirst($ro->status ?? 'pending') }}</span>
                            </div>
                        </div>
                    </div>
                    @empty<p class="text-[11px] text-gray-400 text-center py-4">No orders today</p>@endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="os-card overflow-hidden os-anim os-d5">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between bg-{{ ($lowStockItems ?? collect())->count() > 0 ? 'amber' : 'emerald' }}-50/50 dark:bg-transparent">
            <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">📦 Inventory Status</h2>
            @if(($lowStockItems ?? collect())->count() > 0)<span class="text-[8px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">{{ $lowStockItems->count() }} alerts</span>@endif
        </div>
        @if(($lowStockItems ?? collect())->count() > 0)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 p-4">
            @foreach(($lowStockItems ?? collect())->take(6) as $ing)
            <div class="flex items-center gap-2 p-2 rounded-lg border {{ $ing->current_stock <= 0 ? 'border-red-200 bg-red-50/50 dark:border-red-900/30 dark:bg-red-900/10' : 'border-amber-200 bg-amber-50/50 dark:border-amber-900/30 dark:bg-amber-900/10' }}">
                <span class="w-2.5 h-2.5 rounded-full {{ $ing->current_stock <= 0 ? 'bg-red-500' : 'bg-amber-500' }}"></span>
                <p class="flex-1 text-[11px] font-bold text-gray-900 dark:text-white truncate">{{ $ing->name }}</p>
                <span class="text-[9px] font-bold {{ $ing->current_stock <= 0 ? 'text-red-600' : 'text-amber-600' }}">{{ number_format($ing->current_stock, 1) }} {{ $ing->unit }}</span>
            </div>
            @endforeach
        </div>
        @else
        <div class="text-center py-8"><svg class="w-8 h-8 mx-auto text-emerald-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><p class="text-[11px] text-emerald-600 font-bold">All ingredients fully stocked</p></div>
        @endif
    </div>
    @endif
</div>
