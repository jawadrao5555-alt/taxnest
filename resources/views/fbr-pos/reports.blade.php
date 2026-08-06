<x-fbr-pos-layout>
<div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.sales_reports') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.month_overview', ['month' => now()->format('F Y')]) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.todays_revenue') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($todayStats->revenue ?? 0) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ __('pos.n_invoices', ['count' => $todayStats->count ?? 0]) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.todays_tax') }}</p>
            <p class="text-2xl font-bold text-blue-600 mt-1">PKR {{ number_format($todayStats->tax ?? 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.monthly_revenue') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($monthStats->revenue ?? 0) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ __('pos.n_invoices', ['count' => $monthStats->count ?? 0]) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.monthly_tax') }}</p>
            <p class="text-2xl font-bold text-blue-600 mt-1">PKR {{ number_format($monthStats->tax ?? 0) }}</p>
        </div>
    </div>

    {{-- ═══ Sales Analytics — date-range deep dive (owner request Jul 2026, FBR mirror) ═══ --}}
    @php
        $ra = $rangeAnalytics;
        $raPct = function ($pct) {
            if ($pct === null) return '<span class="text-xs font-semibold text-gray-400">—</span>';
            $cls = $pct >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500';
            $arrow = $pct >= 0 ? '▲' : '▼';
            return '<span class="text-xs font-bold ' . $cls . '">' . $arrow . ' ' . abs($pct) . '%</span>';
        };
    @endphp
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-3 mb-4">
            <div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.sales_analytics') }}</h2>
                <p class="text-xs text-gray-500">{{ \Carbon\Carbon::parse($ra->from)->format('d M Y') }} — {{ \Carbon\Carbon::parse($ra->to)->format('d M Y') }} ({{ __('pos.n_days', ['count' => \Carbon\Carbon::parse($ra->from)->diffInDays(\Carbon\Carbon::parse($ra->to)) + 1]) }})</p>
            </div>
            <form method="GET" action="{{ route('fbrpos.reports') }}" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.from_word') }}</label>
                    <input type="date" name="from" value="{{ $ra->from }}" max="{{ today()->toDateString() }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.to_word') }}</label>
                    <input type="date" name="to" value="{{ $ra->to }}" max="{{ today()->toDateString() }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition">{{ __('pos.apply_word') }}</button>
                <a href="{{ route('fbrpos.reports.analytics-pdf', ['from' => $ra->from, 'to' => $ra->to]) }}" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition">{{ __('pos.pdf_word') }}</a>
            </form>
        </div>
        <div class="flex flex-wrap gap-2 mb-5">
            @foreach([
                __('pos.preset_today') => [today()->toDateString(), today()->toDateString()],
                __('pos.preset_last_7_days') => [today()->subDays(6)->toDateString(), today()->toDateString()],
                __('pos.preset_this_month') => [today()->startOfMonth()->toDateString(), today()->toDateString()],
                __('pos.preset_last_month') => [today()->subMonthNoOverflow()->startOfMonth()->toDateString(), today()->subMonthNoOverflow()->endOfMonth()->toDateString()],
                __('pos.preset_last_90_days') => [today()->subDays(89)->toDateString(), today()->toDateString()],
            ] as $label => $preset)
            <a href="{{ route('fbrpos.reports', ['from' => $preset[0], 'to' => $preset[1]]) }}"
               class="px-3 py-1.5 rounded-full text-xs font-semibold transition {{ $ra->from === $preset[0] && $ra->to === $preset[1] ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-blue-100 hover:text-blue-700 dark:bg-gray-800 dark:text-gray-300' }}">{{ $label }}</a>
            @endforeach
        </div>

        {{-- Summary KPIs + previous-period comparison --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.revenue_word') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($ra->summary->revenue) }}</p>
                {!! $raPct($ra->previous->revenue_pct) !!}
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.bills_word') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">{{ number_format($ra->summary->bills) }}</p>
                {!! $raPct($ra->previous->bills_pct) !!}
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.tax_word') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($ra->summary->tax) }}</p>
                {!! $raPct($ra->previous->tax_pct) !!}
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.avg_bill') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($ra->summary->avg_bill) }}</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.discounts_word') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($ra->summary->discount) }}</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.customers_word') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">{{ number_format($ra->summary->unique_customers) }}</p>
            </div>
        </div>
        <p class="text-xs text-gray-500 -mt-3 mb-5">{{ __('pos.comparison_vs_previous', ['days' => \Carbon\Carbon::parse($ra->previous->from)->diffInDays(\Carbon\Carbon::parse($ra->previous->to)) + 1, 'from' => \Carbon\Carbon::parse($ra->previous->from)->format('d M'), 'to' => \Carbon\Carbon::parse($ra->previous->to)->format('d M Y'), 'revenue' => number_format($ra->previous->revenue), 'bills' => $ra->previous->bills]) }}</p>

        {{-- FBR submission health across the range --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
            <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center">
                <p class="text-xl font-bold text-emerald-600">{{ $ra->fbr_health->submitted }}</p>
                <p class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase mt-0.5">{{ __('pos.fbr_submitted') }}</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 text-center">
                <p class="text-xl font-bold text-gray-700 dark:text-gray-300">{{ $ra->fbr_health->pending }}</p>
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase mt-0.5">{{ __('pos.pending_word') }}</p>
            </div>
            <div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-center">
                <p class="text-xl font-bold text-red-600">{{ $ra->fbr_health->failed }}</p>
                <p class="text-xs font-semibold text-red-700 dark:text-red-400 uppercase mt-0.5">{{ __('pos.failed_word') }}</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 text-center">
                <p class="text-xl font-bold text-gray-700 dark:text-gray-300">{{ $ra->fbr_health->local }}</p>
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase mt-0.5">{{ __('pos.local_word') }}</p>
            </div>
        </div>

        {{-- Profit (ADMIN-ONLY, cost-price based) --}}
        @if($ra->profit !== null)
        <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 mb-5">
            <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.profit_estimate') }} <span class="text-xs font-medium text-gray-500">{{ __('pos.profit_estimate_note') }}</span></p>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $ra->profit->coverage_pct >= 80 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' }}">{{ __('pos.pct_items_covered', ['pct' => $ra->profit->coverage_pct]) }}</span>
            </div>
            @if($ra->profit->cost <= 0 && $ra->profit->revenue <= 0)
            <p class="text-sm text-gray-500">{{ __('pos.no_cost_price_set_hint') }}</p>
            @else
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div>
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.sales_costed_items') }}</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-white">PKR {{ number_format($ra->profit->revenue) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.cost_word') }}</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-white">PKR {{ number_format($ra->profit->cost) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.gross_profit') }}</p>
                    <p class="text-lg font-bold {{ $ra->profit->profit >= 0 ? 'text-emerald-600' : 'text-red-500' }}">PKR {{ number_format($ra->profit->profit) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.margin_word') }}</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $ra->profit->margin_pct !== null ? $ra->profit->margin_pct . '%' : '—' }}</p>
                </div>
            </div>
            @endif
        </div>
        @endif

        {{-- Charts: product pie + daily trend + hourly --}}
        @if($ra->summary->bills > 0)
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-5">
            <div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">{{ __('pos.product_share_top10') }}</h4>
                <div class="relative h-64"><canvas id="raProductPie"></canvas></div>
            </div>
            <div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">{{ __('pos.daily_revenue_trend') }}</h4>
                <div class="relative h-64"><canvas id="raDailyTrend"></canvas></div>
            </div>
        </div>
        <div class="mb-5">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">{{ __('pos.hourly_sales_pattern') }}</h4>
            <div class="relative h-48"><canvas id="raHourly"></canvas></div>
        </div>
        @endif

        {{-- Product breakdown (products table has no category column — flat list) --}}
        <div class="mb-5">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">{{ __('pos.product_breakdown') }} <span class="text-xs font-normal text-gray-500">{{ __('pos.top_25_by_revenue') }}</span></h4>
            <div class="overflow-x-auto">
                <table class="w-full text-sm table-cards">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                            <th class="pb-2">{{ __('pos.product_col') }}</th>
                            <th class="pb-2 text-right">{{ __('pos.qty_word') }}</th>
                            <th class="pb-2 text-right">{{ __('pos.revenue_word') }}</th>
                            <th class="pb-2 text-right">{{ __('pos.tax_word') }}</th>
                            @if($ra->is_admin_view)<th class="pb-2 text-right">{{ __('pos.profit_word') }}</th>@endif
                            <th class="pb-2 text-right">{{ __('pos.share_word') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($ra->products as $pname => $p)
                        <tr class="border-b border-gray-50 dark:border-gray-800">
                            <td class="py-2.5 font-medium text-gray-900 dark:text-white">{{ $pname }}</td>
                            <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ rtrim(rtrim(number_format($p->qty, 2), '0'), '.') }}</td>
                            <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($p->revenue) }}</td>
                            <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">PKR {{ number_format($p->tax) }}</td>
                            @if($ra->is_admin_view)<td class="py-2.5 text-right {{ $p->profit === null ? 'text-gray-400' : ($p->profit >= 0 ? 'text-emerald-600 font-semibold' : 'text-red-500 font-semibold') }}">{{ $p->profit === null ? '—' : 'PKR ' . number_format($p->profit) }}</td>@endif
                            <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $p->share }}%</td>
                        </tr>
                        @empty
                        <tr><td colspan="{{ $ra->is_admin_view ? 6 : 5 }}" class="py-6 text-center text-gray-400">{{ __('pos.no_sale_in_range') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Cashier performance + top customers --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">{{ __('pos.cashier_performance') }}</h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm table-cards">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                                <th class="pb-2">{{ __('pos.cashier_word') }}</th>
                                <th class="pb-2 text-right">{{ __('pos.bills_word') }}</th>
                                <th class="pb-2 text-right">{{ __('pos.revenue_word') }}</th>
                                <th class="pb-2 text-right">{{ __('pos.avg_bill') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($ra->cashiers as $c)
                            <tr class="border-b border-gray-50 dark:border-gray-800">
                                <td class="py-2.5 font-medium text-gray-900 dark:text-white">{{ $c->name }}</td>
                                <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $c->count }}</td>
                                <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($c->revenue) }}</td>
                                <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">PKR {{ number_format($c->avg) }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('pos.no_data') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">{{ __('pos.top_customers') }}</h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm table-cards">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                                <th class="pb-2">#</th>
                                <th class="pb-2">{{ __('pos.customer_word') }}</th>
                                <th class="pb-2 text-right">{{ __('pos.visits_word') }}</th>
                                <th class="pb-2 text-right">{{ __('pos.spent_word') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($ra->top_customers as $i => $cu)
                            <tr class="border-b border-gray-50 dark:border-gray-800">
                                <td class="py-2.5 text-gray-400">{{ $i + 1 }}</td>
                                <td class="py-2.5 font-medium text-gray-900 dark:text-white">{{ $cu->name }}</td>
                                <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $cu->count }}</td>
                                <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($cu->revenue) }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('pos.walk_in_only_no_named') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    @if($ra->summary->bills > 0)
    @php
        // UTF-8-safe chart payloads (bad UTF-8 in @json kills the page — memory rule).
        $raJson = fn ($v) => json_encode($v, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE) ?: '[]';
        $raTopTen = $ra->products->take(10);
        $raProdLabels = $raTopTen->keys()->map(fn ($k) => (string) $k)->values()->all();
        $raProdValues = $raTopTen->values()->map(fn ($p) => round($p->revenue, 2))->all();
        $raDayLabels = collect($ra->daily)->keys()->map(fn ($d) => \Carbon\Carbon::parse($d)->format('d M'))->values()->all();
        $raDayValues = collect($ra->daily)->values()->map(fn ($d) => round($d->revenue, 2))->all();
        $raHourLabels = collect(range(0, 23))->map(fn ($h) => \Carbon\Carbon::createFromTime($h)->format('gA'))->all();
        $raHourValues = collect($ra->hourly)->values()->map(fn ($h) => round($h->revenue, 2))->all();
    @endphp
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Chart === 'undefined') return;
        var palette = ['#2563eb', '#0A4D5C', '#059669', '#d97706', '#dc2626', '#1d4ed8', '#0f766e', '#a16207', '#be123c', '#1e40af', '#115e59', '#92400e'];
        var isDark = document.documentElement.classList.contains('dark');
        var gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
        var textColor = isDark ? '#9ca3af' : '#6b7280';

        var pieEl = document.getElementById('raProductPie');
        if (pieEl) {
            new Chart(pieEl, {
                type: 'doughnut',
                data: {
                    labels: {!! $raJson($raProdLabels) !!},
                    datasets: [{ data: {!! $raJson($raProdValues) !!}, backgroundColor: palette, borderWidth: 1 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { color: textColor, boxWidth: 12, font: { size: 11 } } } } }
            });
        }

        var trendEl = document.getElementById('raDailyTrend');
        if (trendEl) {
            new Chart(trendEl, {
                type: 'line',
                data: {
                    labels: {!! $raJson($raDayLabels) !!},
                    datasets: [{ label: @js(__('pos.revenue_pkr')), data: {!! $raJson($raDayValues) !!}, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.12)', fill: true, tension: 0.3, pointRadius: 2 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { color: textColor, maxTicksLimit: 12 }, grid: { color: gridColor } }, y: { ticks: { color: textColor }, grid: { color: gridColor }, beginAtZero: true } } }
            });
        }

        var hourEl = document.getElementById('raHourly');
        if (hourEl) {
            new Chart(hourEl, {
                type: 'bar',
                data: {
                    labels: {!! $raJson($raHourLabels) !!},
                    datasets: [{ label: @js(__('pos.revenue_pkr')), data: {!! $raJson($raHourValues) !!}, backgroundColor: '#2563eb', borderRadius: 3 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { color: textColor }, grid: { display: false } }, y: { ticks: { color: textColor }, grid: { color: gridColor }, beginAtZero: true } } }
            });
        }
    });
    </script>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.daily_sales_month', ['month' => now()->format('F Y')]) }}</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.date_word') }}</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.invoices_word') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.revenue_word') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($dailySales as $day)
                        <tr>
                            <td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">{{ \Carbon\Carbon::parse($day->date)->format('d M Y') }}</td>
                            <td class="px-3 py-2 text-sm text-center text-gray-700 dark:text-gray-300">{{ $day->count }}</td>
                            <td class="px-3 py-2 text-sm text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($day->revenue, 2) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="px-3 py-6 text-center text-gray-400">{{ __('pos.no_sales_data_month') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.payment_methods') }}</h3>
            @php
            // Payment-method label lookup — translate raw DB values to human labels.
            // 'credit' = Udhaar/Khata; card aliases normalised; others prettified.
            $pmLabels = [
                'cash'        => __('pos.cash_word'),
                'debit_card'  => __('pos.card_word') . ' (Debit)',
                'credit_card' => __('pos.card_word') . ' (Credit)',
                'card'        => __('pos.card_word'),
                'credit'      => __('pos.dc_udhaar'),        // Udhaar / Khata
                'bank_transfer' => 'Bank Transfer',
                'online'      => 'Online',
                'qr_payment'  => 'QR Payment',
            ];
            @endphp
            <div class="space-y-3">
                @forelse($paymentBreakdown as $pm)
                @php
                    $pmKey = $pm->payment_method ?? '';
                    $pmLabel = $pmLabels[$pmKey] ?? ucwords(str_replace('_', ' ', $pmKey));
                    $isUdhaar = $pmKey === 'credit';
                @endphp
                <div class="flex items-center justify-between p-3 {{ $isUdhaar ? 'bg-orange-50 dark:bg-orange-900/20' : 'bg-gray-50 dark:bg-gray-800' }} rounded-lg">
                    <div>
                        <p class="font-medium {{ $isUdhaar ? 'text-orange-800 dark:text-orange-300' : 'text-gray-900 dark:text-white' }}">{{ $pmLabel }}</p>
                        <p class="text-xs text-gray-500">{{ __('pos.n_transactions', ['count' => $pm->count]) }}</p>
                        @if($isUdhaar)
                        <p class="text-xs text-orange-600 dark:text-orange-400">{{ __('pos.dc_udhaar_not_in_drawer') }}</p>
                        @endif
                    </div>
                    <p class="font-bold {{ $isUdhaar ? 'text-orange-700 dark:text-orange-400' : 'text-gray-900 dark:text-white' }}">PKR {{ number_format($pm->revenue, 2) }}</p>
                </div>
                @empty
                <p class="text-center text-gray-400 py-6">{{ __('pos.no_payment_data') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
</x-fbr-pos-layout>
