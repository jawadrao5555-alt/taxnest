<style>
@keyframes lsPop { from { opacity: 0; transform: scale(0.92); } to { opacity: 1; transform: scale(1); } }
@keyframes lsCount { from { opacity: 0; } to { opacity: 1; } }
.ls-anim { animation: lsPop 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
.ls-d1{animation-delay:0ms}.ls-d2{animation-delay:50ms}.ls-d3{animation-delay:100ms}.ls-d4{animation-delay:150ms}.ls-d5{animation-delay:200ms}
.ls-hero { background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 50%, #5b21b6 100%); border-radius: 28px; position: relative; overflow: hidden; }
.ls-tile { border-radius: 20px; padding: 20px; transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); cursor: pointer; position: relative; overflow: hidden; min-height: 140px; display: flex; flex-direction: column; justify-content: space-between; }
.ls-tile:hover { transform: translateY(-6px) scale(1.03); }
.ls-glass { background: rgba(255,255,255,0.9); backdrop-filter: blur(16px); border: 1px solid rgba(0,0,0,0.04); border-radius: 20px; }
.dark .ls-glass { background: rgba(17,24,39,0.9); border-color: rgba(255,255,255,0.06); }
.ls-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 9px; font-weight: 800; letter-spacing: 0.05em; }
</style>

<div class="space-y-5 w-full">
    <div class="ls-hero p-6 sm:p-8 ls-anim ls-d1">
        <div class="relative z-10">
            <div class="flex items-start justify-between gap-4 mb-8">
                <div>
                    <p class="text-[10px] font-bold text-violet-300/60 uppercase tracking-[0.2em]">{{ __("pos.dashboard") }}</p>
                    <h1 class="text-2xl sm:text-3xl font-black text-white mt-1">{{ $company->name ?? __("pos.business_fallback") }}</h1>
                    <p class="text-[11px] text-violet-200/50 mt-1">{{ now()->format('l, d M Y') }}</p>
                </div>
                @include('pos.dashboard-styles._style-picker')
            </div>
            <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-5">
                <p class="text-[9px] font-bold text-violet-200/60 uppercase tracking-wider mb-1">{{ __("pos.todays_revenue") }}</p>
                <div class="flex items-end gap-4 flex-wrap">
                    {{-- Task 988: combined PRA+Local (+exempt) figure — matches the Aaj ka Khaata sum this user sees --}}
                    <p class="text-4xl sm:text-5xl font-black text-white leading-none" style="font-variant-numeric:tabular-nums">Rs.{{ number_format($todayTotalSale ?? $todaySales ?? $todayStats->revenue ?? 0) }}</p>
                    @php $yest=$yesterdayTotalSale??$yesterdaySales??0;$today=$todayTotalSale??$todaySales??$todayStats->revenue??0;$pct=$yest>0?round(($today-$yest)/$yest*100):0; @endphp
                    @if($pct != 0)<span class="ls-badge {{ $pct >= 0 ? 'bg-emerald-500/20 text-emerald-300' : 'bg-red-500/20 text-red-300' }}"><svg class="w-3 h-3 {{ $pct < 0 ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z"/></svg>{{ abs($pct) }}%</span>@endif
                </div>
                <div class="flex gap-8 mt-4">
                    <div><p class="text-2xl font-black text-white">{{ $todayOrders ?? $todayStats->count ?? 0 }}</p><p class="text-[9px] text-violet-200/90 font-bold uppercase">{{ __("pos.orders_word") }}</p></div>
                    {{-- Task 988: Avg. Ticket stat replaced by New Customers (today · this month) --}}
                    <div><p class="text-2xl font-black text-white">{{ number_format($newCustomersToday ?? 0) }}<span class="text-lg text-white/70"> · {{ number_format($newCustomersMonth ?? 0) }} {{ __("pos.period_this_month") }}</span></p><p class="text-[9px] text-violet-200/90 font-bold uppercase">{{ __("pos.new_customers") }}</p></div>
                    @if($isRestaurant)
                    <div><p class="text-2xl font-black text-white">{{ $occupiedTables ?? 0 }}<span class="text-lg text-white/70">/{{ $totalTables ?? 0 }}</span></p><p class="text-[9px] text-violet-200/90 font-bold uppercase">{{ __("pos.tables_word") }}</p></div>
                    @else
                    <div><p class="text-2xl font-black text-white">Rs.{{ number_format(($monthTotalSale ?? $monthSales ?? $monthStats->revenue ?? 0) / 1000) }}k</p><p class="text-[9px] text-violet-200/90 font-bold uppercase">{{ __("pos.monthly_word") }}</p></div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($isRestaurant)
    <div class="grid grid-cols-3 lg:grid-cols-6 gap-3 ls-anim ls-d2">
        <a href="{{ route('pos.invoice.create') }}" class="ls-tile bg-gradient-to-br from-purple-500 to-purple-700 shadow-xl">
            <div class="relative z-10">
                <div class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center mb-3"><svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg></div>
                <p class="text-sm font-black text-white">{{ __("pos.pos_word") }}</p>
                <p class="text-[9px] text-white/80 mt-0.5">{{ __("pos.start_selling") }}</p>
            </div>
        </a>
        <a href="{{ route('pos.transactions') }}" class="ls-tile bg-gradient-to-br from-teal-600 to-teal-800 shadow-xl shadow-teal-600/20">
            <div class="relative z-10">
                <div class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center mb-3"><svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg></div>
                <p class="text-sm font-black text-white">{{ __("pos.orders_word") }}</p>
                <p class="text-[9px] text-white/80 mt-0.5">{{ $todayOrders ?? 0 }} {{ __("pos.today_lc") }}</p>
            </div>
        </a>
        <a href="{{ route('pos.restaurant.tables') }}" class="ls-tile bg-gradient-to-br from-amber-500 to-orange-700 shadow-xl shadow-amber-500/20">
            <div class="relative z-10">
                <div class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center mb-3"><svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 12h16M4 18h16"/></svg></div>
                <p class="text-sm font-black text-white">{{ __("pos.tables_word") }}</p>
                <p class="text-[9px] text-white/80 mt-0.5">{{ $occupiedTables ?? 0 }}/{{ $totalTables ?? 0 }}</p>
            </div>
        </a>
        <a href="{{ route('pos.restaurant.kds') }}" class="ls-tile bg-gradient-to-br from-rose-500 to-pink-700 shadow-xl shadow-rose-500/20">
            <div class="relative z-10">
                <div class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center mb-3"><svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg></div>
                <p class="text-sm font-black text-white">{{ __("pos.kitchen_word") }}</p>
                <p class="text-[9px] text-white/80 mt-0.5">{{ __("pos.kds_view") }}</p>
            </div>
        </a>
        <a href="{{ route('pos.products') }}" class="ls-tile bg-gradient-to-br from-emerald-500 to-teal-700 shadow-xl shadow-emerald-500/20">
            <div class="relative z-10">
                <div class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center mb-3"><svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg></div>
                <p class="text-sm font-black text-white">{{ __("pos.menu_word") }}</p>
                <p class="text-[9px] text-white/80 mt-0.5">{{ __("pos.products_word") }}</p>
            </div>
        </a>
        <a href="{{ route('pos.restaurant.ingredients') }}" class="ls-tile bg-gradient-to-br from-cyan-500 to-sky-700 shadow-xl shadow-cyan-500/20">
            <div class="relative z-10">
                <div class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center mb-3"><svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg></div>
                <p class="text-sm font-black text-white">{{ __("pos.stock_word") }}</p>
                <p class="text-[9px] text-white/80 mt-0.5">{{ ($lowStockItems ?? collect())->count() > 0 ? ($lowStockItems ?? collect())->count() . " " . __("pos.low_lc") : __("pos.ok_word") }}</p>
            </div>
        </a>
    </div>
    @else
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 ls-anim ls-d2">
        <a href="{{ route('pos.invoice.create') }}" class="ls-tile bg-gradient-to-br from-purple-500 to-purple-700 shadow-xl"><div class="relative z-10"><div class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center mb-3"><svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4"/></svg></div><p class="text-sm font-black text-white">{{ __("pos.new_sale") }}</p></div></a>
        <a href="{{ route('pos.transactions') }}" class="ls-tile bg-gradient-to-br from-teal-600 to-teal-800 shadow-xl shadow-teal-600/20"><div class="relative z-10"><div class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center mb-3"><svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg></div><p class="text-sm font-black text-white">{{ __("pos.orders_word") }}</p></div></a>
        <a href="{{ route('pos.products') }}" class="ls-tile bg-gradient-to-br from-emerald-500 to-teal-700 shadow-xl shadow-emerald-500/20"><div class="relative z-10"><div class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center mb-3"><svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg></div><p class="text-sm font-black text-white">{{ __("pos.products_word") }}</p></div></a>
        <a href="{{ route('pos.customers') }}" class="ls-tile bg-gradient-to-br from-pink-500 to-rose-700 shadow-xl shadow-pink-500/20"><div class="relative z-10"><div class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center mb-3"><svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><p class="text-sm font-black text-white">{{ __("pos.customers_word") }}</p></div></a>
        <a href="{{ route('pos.reports') }}" class="ls-tile bg-gradient-to-br from-amber-500 to-orange-700 shadow-xl shadow-amber-500/20"><div class="relative z-10"><div class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center mb-3"><svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg></div><p class="text-sm font-black text-white">{{ __("pos.reports") }}</p></div></a>
    </div>
    @endif

    @if(isset($isAdmin) && $isAdmin && $isRestaurant)
    <div class="ls-glass p-5 ls-anim ls-d3">
        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-3">{{ __("pos.profit_overview") }}</p>
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
            <div><p class="text-lg font-black {{ ($todayProfit ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">Rs.{{ number_format($todayProfit ?? 0) }}</p><p class="text-[9px] text-gray-400 font-bold">{{ __("pos.profit_word") }}</p></div>
            <div><p class="text-lg font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayCost ?? 0) }}</p><p class="text-[9px] text-gray-400 font-bold">{{ __("pos.cost_word") }}</p></div>
            <div><p class="text-lg font-black text-violet-600">{{ ($todaySales ?? 0) > 0 ? round(($todayProfit ?? 0) / ($todaySales ?? 1) * 100) : 0 }}%</p><p class="text-[9px] text-gray-400 font-bold">{{ __("pos.margin_word") }}</p></div>
            <div><p class="text-lg font-black text-cyan-600">Rs.{{ number_format($todayTax ?? $todayStats->tax ?? 0) }}</p><p class="text-[9px] text-gray-400 font-bold">{{ __("pos.tax_word") }}</p></div>
            <div><p class="text-lg font-black text-orange-600">Rs.{{ number_format($todayDiscount ?? 0) }}</p><p class="text-[9px] text-gray-400 font-bold">{{ __("pos.discounts_word") }}</p></div>
        </div>
    </div>
    @endif

    @if($isRestaurant && isset($salesChartLabels))
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 ls-anim ls-d4">
        <div class="ls-glass p-5">
            <h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-4">{{ __("pos.revenue_last_7_days") }}</h2>
            <div style="height: 200px;"><canvas id="salesChart"></canvas></div>
        </div>
        <div class="grid grid-rows-2 gap-4">
            @if(isset($orderTypeCounts))
            <div class="ls-glass p-5">
                <h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">{{ __("pos.order_types") }}</h2>
                <div style="height: 100px;"><canvas id="orderTypeChart"></canvas></div>
            </div>
            @endif
            <div class="ls-glass p-5">
                <div class="flex items-center justify-between mb-3"><h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase">{{ __("pos.stock_word") }}</h2>@if(($lowStockItems ?? collect())->count() > 0)<span class="ls-badge bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">{{ $lowStockItems->count() }} {{ __("pos.low_caps") }}</span>@endif</div>
                @if(($lowStockItems ?? collect())->count() > 0)
                @foreach(($lowStockItems ?? collect())->take(3) as $ing)
                <div class="flex items-center justify-between py-1.5"><span class="text-[11px] font-semibold text-gray-700 dark:text-gray-300">{{ $ing->name }}</span><span class="text-[9px] font-bold {{ $ing->current_stock <= 0 ? 'text-red-600' : 'text-amber-600' }}">{{ number_format($ing->current_stock, 1) }} {{ $ing->unit }}</span></div>
                @endforeach
                @else
                <p class="text-[11px] text-green-600 font-semibold py-2">✓ {{ __("pos.all_stocked") }}</p>
                @endif
            </div>
        </div>
    </div>
    @endif

    @if(!$isRestaurant && isset($paymentBreakdown))
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 ls-anim ls-d4">
        <div class="ls-glass p-5">
            <h3 class="text-xs font-bold text-gray-900 dark:text-white uppercase mb-3">{{ __("pos.payments_word") }}</h3>
            @forelse($paymentBreakdown as $pb)
            <div class="flex items-center justify-between py-2.5 border-b border-gray-100 dark:border-gray-800 last:border-0">
                <span class="text-[11px] font-bold text-gray-700 dark:text-gray-300">{{ \App\Support\PosPaymentLabels::label($pb->payment_method) }}</span>
                <span class="text-sm font-black text-gray-900 dark:text-white">Rs.{{ number_format($pb->total) }}</span>
            </div>
            @empty<p class="text-[11px] text-gray-400 py-4 text-center">{{ __("pos.no_sales") }}</p>@endforelse
        </div>
        <div class="lg:col-span-2 ls-glass overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between"><h3 class="text-xs font-bold text-gray-900 dark:text-white uppercase">{{ __("pos.transactions") }}</h3><a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-violet-600">{{ __("pos.view_all_caps") }}</a></div>
            <table class="w-full"><thead><tr class="text-left text-[9px] font-bold text-gray-400 uppercase bg-gray-50/50 dark:bg-gray-800/30"><th class="py-2.5 px-5">{{ __("pos.invoice_word") }}</th><th class="py-2.5 px-3">{{ __("pos.customer_word") }}</th><th class="py-2.5 px-3 text-right">{{ __("pos.amount_word") }}</th><th class="py-2.5 px-3">{{ __("pos.time_word") }}</th></tr></thead><tbody>
                @forelse($recentTransactions as $txn)
                <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-violet-50/30 transition"><td class="py-2.5 px-5 text-[11px] font-bold text-violet-600">{{ $txn->invoice_number }}</td><td class="py-2.5 px-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? __("pos.walk_in") }}</td><td class="py-2.5 px-3 text-right text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount) }}</td><td class="py-2.5 px-3 text-[10px] text-gray-400">{{ $txn->created_at->diffForHumans() }}</td></tr>
                @empty<tr><td colspan="4" class="py-6 text-center text-[11px] text-gray-400">{{ __("pos.no_transactions") }}</td></tr>@endforelse
            </tbody></table>
        </div>
    </div>
    @endif

    @if($isRestaurant)
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 ls-anim ls-d5">
        <div class="lg:col-span-2 ls-glass overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between"><h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase">{{ __("pos.best_sellers") }}</h2></div>
            <div class="grid grid-cols-1 sm:grid-cols-2">
                @forelse(($topProducts ?? collect())->take(6) as $idx => $p)
                <div class="flex items-center gap-3 p-4 border-b border-r border-gray-50 dark:border-gray-800/50 hover:bg-violet-50/20 dark:hover:bg-violet-900/5 transition">
                    <div class="w-10 h-10 rounded-2xl flex items-center justify-center text-sm font-black flex-shrink-0 {{ $idx < 3 ? 'bg-gradient-to-br from-purple-500 to-purple-600 text-white shadow-lg' : 'bg-gray-100 dark:bg-gray-800 text-gray-400' }}">{{ $idx + 1 }}</div>
                    <div class="flex-1 min-w-0"><p class="text-xs font-bold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p><p class="text-[9px] text-gray-400">{{ $p->total_qty }} {{ __("pos.sold_suffix") }}</p></div>
                    <p class="text-xs font-black text-gray-900 dark:text-white">Rs.{{ number_format($p->total_revenue) }}</p>
                </div>
                @empty<div class="col-span-2 py-8 text-center text-[11px] text-gray-400">{{ __("pos.no_data") }}</div>@endforelse
            </div>
        </div>
        <div class="ls-glass overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between"><h2 class="text-xs font-bold text-gray-900 dark:text-white uppercase">{{ __("pos.orders_word") }}</h2><a href="{{ route('pos.transactions') }}" class="text-[10px] font-bold text-violet-600">{{ __("pos.all_word") }}</a></div>
            <div class="divide-y divide-gray-50 dark:divide-gray-800">
                @forelse(($recentOrders ?? $recentTransactions ?? collect())->take(6) as $ro)
                <div class="flex items-center justify-between px-5 py-3 hover:bg-violet-50/20 transition">
                    <div class="text-violet-600">@include('pos.dashboard-styles._restaurant-order-identity', ['order' => $ro])<p class="text-[9px] text-gray-400">{{ ucwords(str_replace('_', ' ', $ro->order_type ?? $ro->payment_method ?? '-')) }}</p></div>
                    <div class="text-right"><p class="text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($ro->total_amount) }}</p><span class="text-[8px] font-bold {{ ($ro->status ?? '') === 'completed' ? 'text-green-600' : 'text-amber-600' }}">{{ ucfirst($ro->status ?? 'pending') }}</span></div>
                </div>
                @empty<div class="py-8 text-center text-[11px] text-gray-400">{{ __("pos.no_orders") }}</div>@endforelse
            </div>
        </div>
    </div>
    @endif
</div>
