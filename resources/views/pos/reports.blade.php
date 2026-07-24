<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
            {{ ($tab ?? 'pra') === 'local' ? 'Local Reports' : 'POS Reports' }}
        </h1>
        <a href="{{ route('pos.reports.csv', array_filter(['tab' => $tab, 'cashier' => $selectedCashier, 'from' => request('from'), 'to' => request('to')])) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            Download CSV
        </a>
    </div>

    @include('pos.partials.mode-tabs', ['baseUrl' => route('pos.reports')])

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 mb-6">
        <form method="GET" action="{{ route('pos.reports') }}" class="flex flex-col sm:flex-row items-start sm:items-end gap-3">
            <input type="hidden" name="tab" value="{{ $tab }}">
            @if(request()->filled('from'))<input type="hidden" name="from" value="{{ request('from') }}">@endif
            @if(request()->filled('to'))<input type="hidden" name="to" value="{{ request('to') }}">@endif
            <div class="w-full sm:w-auto">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">View Sales By</label>
                <select name="cashier" onchange="this.form.submit()" class="w-full sm:w-56 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                    <option value="all" {{ $selectedCashier === 'all' ? 'selected' : '' }}>All Company Sales</option>
                    @if($isCashier)
                    <option value="{{ $user->id }}" {{ $selectedCashier == $user->id ? 'selected' : '' }}>My Sales Only</option>
                    @else
                    @foreach($teamMembers as $member)
                    <option value="{{ $member->id }}" {{ $selectedCashier == $member->id ? 'selected' : '' }}>
                        {{ $member->name }} ({{ $member->pos_role === 'pos_admin' ? 'Admin' : ($member->pos_role === 'pos_manager' ? 'Manager' : 'Cashier') }})
                    </option>
                    @endforeach
                    @endif
                </select>
            </div>
            @if($selectedCashier !== 'all')
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300">
                    @if($isCashier && $selectedCashier == $user->id)
                        Showing: My Sales
                    @else
                        Showing: {{ $teamMembers->firstWhere('id', $selectedCashier)?->name ?? 'Staff' }}
                    @endif
                </span>
                <a href="{{ route('pos.reports', array_filter(['tab' => $tab, 'cashier' => 'all', 'from' => request('from'), 'to' => request('to')])) }}" class="text-xs text-gray-500 hover:text-purple-600 underline">Clear</a>
            </div>
            @endif
        </form>
    </div>

    {{-- ═══ Sales Analytics — date-range deep dive (owner request Jul 2026) ═══ --}}
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
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Sales Analytics</h2>
                <p class="text-xs text-gray-500">{{ \Carbon\Carbon::parse($ra->from)->format('d M Y') }} — {{ \Carbon\Carbon::parse($ra->to)->format('d M Y') }} ({{ \Carbon\Carbon::parse($ra->from)->diffInDays(\Carbon\Carbon::parse($ra->to)) + 1 }} days)</p>
            </div>
            <form method="GET" action="{{ route('pos.reports') }}" class="flex flex-wrap items-end gap-2">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <input type="hidden" name="cashier" value="{{ $selectedCashier }}">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">From</label>
                    <input type="date" name="from" value="{{ $ra->from }}" max="{{ today()->toDateString() }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">To</label>
                    <input type="date" name="to" value="{{ $ra->to }}" max="{{ today()->toDateString() }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                </div>
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition">Apply</button>
                <a href="{{ route('pos.reports.analytics-pdf', ['tab' => $tab, 'cashier' => $selectedCashier, 'from' => $ra->from, 'to' => $ra->to]) }}" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition">PDF</a>
            </form>
        </div>
        <div class="flex flex-wrap gap-2 mb-5">
            @foreach([
                'Today' => [today()->toDateString(), today()->toDateString()],
                'Last 7 Days' => [today()->subDays(6)->toDateString(), today()->toDateString()],
                'This Month' => [today()->startOfMonth()->toDateString(), today()->toDateString()],
                'Last Month' => [today()->subMonthNoOverflow()->startOfMonth()->toDateString(), today()->subMonthNoOverflow()->endOfMonth()->toDateString()],
                'Last 90 Days' => [today()->subDays(89)->toDateString(), today()->toDateString()],
            ] as $label => $preset)
            <a href="{{ route('pos.reports', ['tab' => $tab, 'cashier' => $selectedCashier, 'from' => $preset[0], 'to' => $preset[1]]) }}"
               class="px-3 py-1.5 rounded-full text-xs font-semibold transition {{ $ra->from === $preset[0] && $ra->to === $preset[1] ? 'bg-purple-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-purple-100 hover:text-purple-700 dark:bg-gray-800 dark:text-gray-300' }}">{{ $label }}</a>
            @endforeach
        </div>

        {{-- Summary KPIs + previous-period comparison --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 uppercase font-medium">Revenue</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($ra->summary->revenue) }}</p>
                {!! $raPct($ra->previous->revenue_pct) !!}
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 uppercase font-medium">Bills</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">{{ number_format($ra->summary->bills) }}</p>
                {!! $raPct($ra->previous->bills_pct) !!}
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 uppercase font-medium">Tax</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($ra->summary->tax) }}</p>
                {!! $raPct($ra->previous->tax_pct) !!}
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 uppercase font-medium">Avg Bill</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($ra->summary->avg_bill) }}</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 uppercase font-medium">Discounts</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($ra->summary->discount) }}</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 uppercase font-medium">Customers</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">{{ number_format($ra->summary->unique_customers) }}</p>
            </div>
        </div>
        <p class="text-xs text-gray-500 -mt-3 mb-5">Comparison vs previous {{ \Carbon\Carbon::parse($ra->previous->from)->diffInDays(\Carbon\Carbon::parse($ra->previous->to)) + 1 }} days ({{ \Carbon\Carbon::parse($ra->previous->from)->format('d M') }} — {{ \Carbon\Carbon::parse($ra->previous->to)->format('d M Y') }}: PKR {{ number_format($ra->previous->revenue) }}, {{ $ra->previous->bills }} bills)</p>

        {{-- Profit (ADMIN-ONLY, cost-price based) --}}
        @if($ra->profit !== null)
        <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 mb-5">
            <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                <p class="text-sm font-bold text-gray-900 dark:text-white">Profit Estimate <span class="text-xs font-medium text-gray-500">(admin only — cost price wale products par)</span></p>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $ra->profit->coverage_pct >= 80 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' }}">{{ $ra->profit->coverage_pct }}% items covered</span>
            </div>
            @if($ra->profit->cost <= 0 && $ra->profit->revenue <= 0)
            <p class="text-sm text-gray-500">Kisi bike hue product ka cost price set nahi — Products page par cost price add karein to profit yahan nazar aayega.</p>
            @else
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div>
                    <p class="text-xs text-gray-500 uppercase font-medium">Sales (costed items)</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-white">PKR {{ number_format($ra->profit->revenue) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase font-medium">Cost</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-white">PKR {{ number_format($ra->profit->cost) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase font-medium">Gross Profit</p>
                    <p class="text-lg font-bold {{ $ra->profit->profit >= 0 ? 'text-emerald-600' : 'text-red-500' }}">PKR {{ number_format($ra->profit->profit) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase font-medium">Margin</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $ra->profit->margin_pct !== null ? $ra->profit->margin_pct . '%' : '—' }}</p>
                </div>
            </div>
            @endif
        </div>
        @endif

        {{-- Charts: category pie + daily trend + hourly --}}
        @if($ra->summary->bills > 0)
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-5">
            <div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Category Share</h4>
                <div class="relative h-64"><canvas id="raCategoryPie"></canvas></div>
            </div>
            <div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Daily Revenue Trend</h4>
                <div class="relative h-64"><canvas id="raDailyTrend"></canvas></div>
            </div>
        </div>
        <div class="mb-5">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Hourly Sales Pattern</h4>
            <div class="relative h-48"><canvas id="raHourly"></canvas></div>
        </div>
        @endif

        {{-- Category breakdown w/ product drill-down --}}
        <div x-data="{ open: null }" class="mb-5">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Category Breakdown <span class="text-xs font-normal text-gray-500">(row par click karein — products drill-down)</span></h4>
            <div class="overflow-x-auto">
                <table class="w-full text-sm table-cards">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                            <th class="pb-2">Category</th>
                            <th class="pb-2 text-right">Qty</th>
                            <th class="pb-2 text-right">Revenue</th>
                            <th class="pb-2 text-right">Tax</th>
                            @if($ra->is_admin_view)<th class="pb-2 text-right">Profit</th>@endif
                            <th class="pb-2 text-right">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($ra->categories as $catName => $cat)
                        <tr class="border-b border-gray-50 dark:border-gray-800 cursor-pointer hover:bg-purple-50/50 dark:hover:bg-purple-900/10 transition" @click="open = open === {{ $loop->index }} ? null : {{ $loop->index }}">
                            <td class="py-2.5 font-medium text-gray-900 dark:text-white">
                                <span class="inline-block w-4 text-purple-500 font-bold" x-text="open === {{ $loop->index }} ? '−' : '+'"></span>{{ $catName }}
                            </td>
                            <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ rtrim(rtrim(number_format($cat->qty, 2), '0'), '.') }}</td>
                            <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($cat->revenue) }}</td>
                            <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">PKR {{ number_format($cat->tax) }}</td>
                            @if($ra->is_admin_view)<td class="py-2.5 text-right {{ $cat->profit === null ? 'text-gray-400' : ($cat->profit >= 0 ? 'text-emerald-600 font-semibold' : 'text-red-500 font-semibold') }}">{{ $cat->profit === null ? '—' : 'PKR ' . number_format($cat->profit) }}</td>@endif
                            <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $cat->share }}%</td>
                        </tr>
                        <tr x-show="open === {{ $loop->index }}" x-cloak>
                            <td colspan="{{ $ra->is_admin_view ? 6 : 5 }}" class="py-2 px-4 bg-gray-50 dark:bg-gray-800/50">
                                <table class="w-full text-xs">
                                    <thead>
                                        <tr class="text-left text-gray-500 uppercase">
                                            <th class="py-1">Product</th>
                                            <th class="py-1 text-right">Qty</th>
                                            <th class="py-1 text-right">Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($cat->products as $pname => $p)
                                        <tr class="border-t border-gray-100 dark:border-gray-700">
                                            <td class="py-1.5 text-gray-900 dark:text-white">{{ $pname }}</td>
                                            <td class="py-1.5 text-right text-gray-700 dark:text-gray-300">{{ rtrim(rtrim(number_format($p->qty, 2), '0'), '.') }}</td>
                                            <td class="py-1.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($p->revenue) }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="{{ $ra->is_admin_view ? 6 : 5 }}" class="py-6 text-center text-gray-400">Is range mein koi sale nahi</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Cashier performance + top customers --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Cashier Performance</h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm table-cards">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                                <th class="pb-2">Cashier</th>
                                <th class="pb-2 text-right">Bills</th>
                                <th class="pb-2 text-right">Revenue</th>
                                <th class="pb-2 text-right">Avg Bill</th>
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
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">No data</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Top Customers</h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm table-cards">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                                <th class="pb-2">#</th>
                                <th class="pb-2">Customer</th>
                                <th class="pb-2 text-right">Visits</th>
                                <th class="pb-2 text-right">Spent</th>
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
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">Walk-in sales only — koi named customer nahi</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Sales by Waiter (Table-se-Bill, Jul 2026): attribution from restaurant_orders
             (waiter = order creator, linked txn on settle) — only shows when the range
             actually has waiter-settled bills. --}}
        @if($ra->waiters->isNotEmpty())
        <div class="mt-6">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Sales by Waiter</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-sm table-cards">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                            <th class="pb-2">Waiter</th>
                            <th class="pb-2 text-right">Orders</th>
                            <th class="pb-2 text-right">Revenue</th>
                            <th class="pb-2 text-right">Avg Bill</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($ra->waiters as $w)
                        <tr class="border-b border-gray-50 dark:border-gray-800">
                            <td class="py-2.5 font-medium text-gray-900 dark:text-white">{{ $w->name }}</td>
                            <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $w->count }}</td>
                            <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($w->revenue) }}</td>
                            <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">PKR {{ number_format($w->avg) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>

    @if($ra->summary->bills > 0)
    @php
        // UTF-8-safe chart payloads (bad UTF-8 in @json kills the page — memory rule).
        $raJson = fn ($v) => json_encode($v, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE) ?: '[]';
        $raCatLabels = $ra->categories->keys()->map(fn ($k) => (string) $k)->values()->all();
        $raCatValues = $ra->categories->values()->map(fn ($c) => round($c->revenue, 2))->all();
        $raDayLabels = collect($ra->daily)->keys()->map(fn ($d) => \Carbon\Carbon::parse($d)->format('d M'))->values()->all();
        $raDayValues = collect($ra->daily)->values()->map(fn ($d) => round($d->revenue, 2))->all();
        $raHourLabels = collect(range(0, 23))->map(fn ($h) => \Carbon\Carbon::createFromTime($h)->format('gA'))->all();
        $raHourValues = collect($ra->hourly)->values()->map(fn ($h) => round($h->revenue, 2))->all();
    @endphp
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Chart === 'undefined') return;
        var palette = ['#7c3aed', '#0A4D5C', '#059669', '#d97706', '#dc2626', '#9333ea', '#0f766e', '#a16207', '#be123c', '#6d28d9', '#115e59', '#92400e'];
        var isDark = document.documentElement.classList.contains('dark');
        var gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
        var textColor = isDark ? '#9ca3af' : '#6b7280';

        var pieEl = document.getElementById('raCategoryPie');
        if (pieEl) {
            new Chart(pieEl, {
                type: 'doughnut',
                data: {
                    labels: {!! $raJson($raCatLabels) !!},
                    datasets: [{ data: {!! $raJson($raCatValues) !!}, backgroundColor: palette, borderWidth: 1 }]
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
                    datasets: [{ label: 'Revenue (PKR)', data: {!! $raJson($raDayValues) !!}, borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,0.12)', fill: true, tension: 0.3, pointRadius: 2 }]
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
                    datasets: [{ label: 'Revenue (PKR)', data: {!! $raJson($raHourValues) !!}, backgroundColor: '#7c3aed', borderRadius: 3 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { color: textColor }, grid: { display: false } }, y: { ticks: { color: textColor }, grid: { color: gridColor }, beginAtZero: true } } }
            });
        }
    });
    </script>
    @endif

    @if(($tab ?? 'pra') === 'local' && isset($localBills))
    {{-- ── Local Invoices list (admin-only tab) ──
         Current-month bills can be promoted to PRA (gets a real POS serial +
         counts toward the monthly bill quota). Previous months are CLOSED —
         view/report only, the promote option disappears. --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Local Invoices</h3>
            <span class="text-xs text-gray-500">Sirf current month ke bills PRA par submit ho sakte hain</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-2">Date</th>
                        <th class="pb-2">Invoice #</th>
                        <th class="pb-2">Customer</th>
                        <th class="pb-2">Method</th>
                        <th class="pb-2 text-right">Total</th>
                        <th class="pb-2">Cashier</th>
                        <th class="pb-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($localBills as $bill)
                    <tr class="border-b border-gray-50 dark:border-gray-800">
                        <td class="py-2.5 text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ $bill->created_at->format('d M Y H:i') }}</td>
                        <td class="py-2.5 font-medium text-gray-900 dark:text-white">{{ $bill->invoice_number }}</td>
                        <td class="py-2.5 text-gray-700 dark:text-gray-300">{{ $bill->customer_name ?: 'Walk-in' }}</td>
                        <td class="py-2.5 text-gray-700 dark:text-gray-300">{{ ucwords(str_replace('_', ' ', $bill->payment_method)) }}</td>
                        <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($bill->total_amount) }}</td>
                        <td class="py-2.5 text-gray-700 dark:text-gray-300">{{ $bill->creator?->name ?? '-' }}</td>
                        <td class="py-2.5 text-right whitespace-nowrap">
                            @if($bill->invoice_mode === 'local')
                                @if($bill->created_at->gte($monthStart))
                                <button type="button"
                                    onclick="promoteLocalBill(this, {{ $bill->id }}, '{{ $bill->invoice_number }}')"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-purple-600 text-white hover:bg-purple-700 transition">
                                    Submit to PRA
                                </button>
                                @else
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-medium bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">Month closed</span>
                                @endif
                            @else
                            {{-- Reporting-OFF final ("local" — no PRA fiscal). Owner rule
                                 (Jul 2026 update): these CAN also be submitted per-bill —
                                 current month only; older months are closed. --}}
                                @if($bill->created_at->gte($monthStart))
                                <form method="POST" action="{{ route('pos.transaction.retry-pra', $bill->id) }}" class="inline" onsubmit="return confirm('Submit {{ $bill->invoice_number }} to PRA? Bill ko POS fiscal serial mil jayega.')">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-purple-600 text-white hover:bg-purple-700 transition">
                                        Submit to PRA
                                    </button>
                                </form>
                                @else
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-medium bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">Month closed</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="py-6 text-center text-gray-400">No local invoices</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($localBills->hasPages())
        <div class="mt-4">{{ $localBills->links() }}</div>
        @endif
    </div>
    <script>
        function promoteLocalBill(btn, id, number) {
            if (!confirm('Bill ' + number + ' ko PRA par submit karein?\n\n• Ye bill FINAL ho jayega aur naya POS serial number milega\n• Monthly bill quota mein count hoga\n• Ye action wapis nahi ho sakta')) return;
            btn.disabled = true; btn.textContent = 'Submitting…';
            fetch('{{ url('/pos/api/provisional-bills') }}/' + id + '/promote', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ send_to_pra: true })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                alert(data.message || (data.success ? 'Done' : 'Failed'));
                if (data.success) { window.location.reload(); }
                else { btn.disabled = false; btn.textContent = 'Submit to PRA'; }
            })
            .catch(function () {
                alert('Network error — dobara koshish karein.');
                btn.disabled = false; btn.textContent = 'Submit to PRA';
            });
        }
    </script>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Payment Method Summary (This Month)</h3>
            <div class="overflow-x-auto -mx-5 px-5">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-2">Method</th>
                        <th class="pb-2 text-right">Count</th>
                        <th class="pb-2 text-right">Revenue</th>
                        <th class="pb-2 text-right">Tax Collected</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($paymentSummary as $ps)
                    <tr class="border-b border-gray-50 dark:border-gray-800">
                        <td class="py-2.5">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $ps->payment_method === 'cash' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' }}">
                                {{ ucwords(str_replace('_', ' ', $ps->payment_method)) }}
                            </span>
                        </td>
                        <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $ps->count }}</td>
                        <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($ps->total) }}</td>
                        <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">PKR {{ number_format($ps->tax) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-400">No data this month</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Top Selling Items (This Month)</h3>
            <div class="overflow-x-auto -mx-5 px-5">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-2">#</th>
                        <th class="pb-2">Item</th>
                        <th class="pb-2 text-right">Qty Sold</th>
                        <th class="pb-2 text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($topItems as $i => $item)
                    <tr class="border-b border-gray-50 dark:border-gray-800">
                        <td class="py-2.5 text-gray-400">{{ $i + 1 }}</td>
                        <td class="py-2.5 text-gray-900 dark:text-white font-medium">{{ $item->item_name }}</td>
                        <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ number_format($item->total_qty) }}</td>
                        <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($item->total_revenue) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-400">No data this month</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Daily Sales (Last 30 Days)</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-2">Date</th>
                        <th class="pb-2 text-right">Transactions</th>
                        <th class="pb-2 text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($dailySales as $day)
                    <tr class="border-b border-gray-50 dark:border-gray-800 {{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}">
                        <td class="py-2.5 text-gray-900 dark:text-white">{{ \Carbon\Carbon::parse($day->date)->format('d M Y (D)') }}</td>
                        <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $day->count }}</td>
                        <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($day->revenue) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="3" class="py-6 text-center text-gray-400">No sales data</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Monthly Trend (Last 6 Months)</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach($monthlyTrend as $mt)
            <div class="text-center p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 mb-1">{{ \Carbon\Carbon::parse($mt->month . '-01')->format('M Y') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $mt->count }}</p>
                <p class="text-xs text-emerald-600 font-medium">PKR {{ number_format($mt->revenue / 1000, 1) }}K</p>
            </div>
            @endforeach
        </div>
    </div>
</div>
@if($hasPinSet ?? false)
@include('pos.partials.pin-modal')
@endif
</x-pos-layout>