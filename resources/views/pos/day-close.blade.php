<x-pos-layout>
<div class="max-w-7xl mx-auto">
    @include('pos.partials.back-link')
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Day Close Report (Z-Report)</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">PRA Compliance — End-of-Day Summary</p>
        </div>
        <form method="GET" action="{{ route('pos.day-close') }}" class="flex items-center gap-2">
            <input type="date" name="date" value="{{ $date }}" max="{{ today()->format('Y-m-d') }}"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
            <button type="submit" class="px-4 py-2 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition">View</button>
        </form>
    </div>

    @if($existingReport)
    <div class="mb-6 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-emerald-100 dark:bg-emerald-900/40 flex items-center justify-center">
                    <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <p class="font-bold text-emerald-800 dark:text-emerald-300">Day Closed — {{ $existingReport->report_number }}</p>
                    <p class="text-xs text-emerald-600 dark:text-emerald-400">Closed on {{ $existingReport->created_at->format('d M Y h:i A') }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('pos.day-close-thermal', $existingReport->id) }}" target="_blank" class="px-4 py-2 bg-gray-800 text-white text-sm font-semibold rounded-lg hover:bg-gray-900 transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4H7v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    Thermal Z-Report
                </a>
                <a href="{{ route('pos.day-close-pdf', $existingReport->id) }}" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Download PDF
                </a>
            </div>
        </div>
    </div>
    @endif

    <div class="text-center mb-6">
        <p class="text-lg font-bold text-gray-900 dark:text-white">{{ \Carbon\Carbon::parse($date)->format('l, d F Y') }}</p>
    </div>

    @php
        // Day can also be closed when ONLY leftover local bills exist (backlog wash).
        $lbPending = ($localWash->prov_count ?? 0) + ($localWash->final_count ?? 0);
    @endphp
    @if($stats->total_invoices > 0 || $lbPending > 0)
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Invoices</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ $stats->total_invoices }}</p>
            <div class="flex flex-wrap gap-2 mt-2">
                <span class="text-xs px-1.5 py-0.5 rounded bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">PRA: {{ $stats->pra_invoices }}</span>
                <span class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">Local: {{ $stats->local_invoices }}</span>
                @if($stats->offline_invoices > 0)
                <span class="text-xs px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">Offline: {{ $stats->offline_invoices }}</span>
                @endif
            </div>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Gross Sales</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($stats->gross_sales) }}</p>
            @if($stats->total_discount > 0)
            <p class="text-xs text-red-500 mt-1">Discount: -PKR {{ number_format($stats->total_discount) }}</p>
            @endif
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Tax</p>
            <p class="text-2xl font-bold text-purple-600 mt-1">PKR {{ number_format($stats->total_tax) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Net Revenue</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1">PKR {{ number_format($stats->total_amount) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                Payment Breakdown
            </h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        </div>
                        <span class="font-medium text-gray-900 dark:text-white">Cash</span>
                    </div>
                    <span class="font-bold text-gray-900 dark:text-white">PKR {{ number_format($stats->cash_amount, 2) }}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        </div>
                        <span class="font-medium text-gray-900 dark:text-white">Card</span>
                    </div>
                    <span class="font-bold text-gray-900 dark:text-white">PKR {{ number_format($stats->card_amount, 2) }}</span>
                </div>
                @if($stats->other_amount > 0)
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                            <svg class="w-4 h-4 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <span class="font-medium text-gray-900 dark:text-white">Other</span>
                    </div>
                    <span class="font-bold text-gray-900 dark:text-white">PKR {{ number_format($stats->other_amount, 2) }}</span>
                </div>
                @endif
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Cashier Breakdown
            </h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Cashier</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Sales</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Tax</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($cashierBreakdown as $name => $data)
                        <tr>
                            <td class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-white">{{ $name }}</td>
                            <td class="px-3 py-2 text-sm text-center text-gray-700 dark:text-gray-300">{{ $data->count }}</td>
                            <td class="px-3 py-2 text-sm text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($data->revenue, 2) }}</td>
                            <td class="px-3 py-2 text-sm text-right text-purple-600">PKR {{ number_format($data->tax, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Invoice Range</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <p class="text-xs text-gray-500 uppercase font-medium">First Invoice</p>
                <p class="font-bold text-gray-900 dark:text-white">{{ $stats->first_invoice->invoice_number ?? '-' }}</p>
                <p class="text-xs text-gray-500">{{ $stats->first_invoice ? $stats->first_invoice->created_at->format('h:i A') : '-' }}</p>
            </div>
            <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <p class="text-xs text-gray-500 uppercase font-medium">Last Invoice</p>
                <p class="font-bold text-gray-900 dark:text-white">{{ $stats->last_invoice->invoice_number ?? '-' }}</p>
                <p class="text-xs text-gray-500">{{ $stats->last_invoice ? $stats->last_invoice->created_at->format('h:i A') : '-' }}</p>
            </div>
        </div>
    </div>

    {{-- ═══ Comprehensive Z-Report analytics (owner request Jul 2026) ═══ --}}

    {{-- Averages + comparison KPIs --}}
    @php
        $cmp = $analytics->comparison;
        $pctBadge = function ($pct) {
            if ($pct === null) return '<span class="text-xs font-semibold text-gray-400">—</span>';
            $cls = $pct >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500';
            $arrow = $pct >= 0 ? '▲' : '▼';
            return '<span class="text-xs font-bold ' . $cls . '">' . $arrow . ' ' . abs($pct) . '%</span>';
        };
    @endphp
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Average Bill</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($analytics->avg_bill) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Unique Customers</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ $analytics->unique_customers }}</p>
            <p class="text-xs text-gray-500 mt-1">Named / saved customers only</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Vs Yesterday</p>
            <div class="flex items-baseline gap-2 mt-1">
                <p class="text-lg font-bold text-gray-900 dark:text-white">PKR {{ number_format($cmp->yesterday->revenue) }}</p>
                {!! $pctBadge($cmp->vs_yesterday_revenue_pct) !!}
            </div>
            <p class="text-xs text-gray-500 mt-1">{{ $cmp->yesterday->invoices }} bills {!! $pctBadge($cmp->vs_yesterday_invoices_pct) !!}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Vs Last {{ \Carbon\Carbon::parse($date)->format('l') }}</p>
            <div class="flex items-baseline gap-2 mt-1">
                <p class="text-lg font-bold text-gray-900 dark:text-white">PKR {{ number_format($cmp->last_week->revenue) }}</p>
                {!! $pctBadge($cmp->vs_last_week_revenue_pct) !!}
            </div>
            <p class="text-xs text-gray-500 mt-1">{{ $cmp->last_week->invoices }} bills {!! $pctBadge($cmp->vs_last_week_invoices_pct) !!}</p>
        </div>
    </div>

    {{-- PRA submission health --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            PRA Submission Health
        </h3>
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center">
                <p class="text-2xl font-bold text-emerald-600">{{ $analytics->pra_health->submitted }}</p>
                <p class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase mt-0.5">Submitted</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 text-center">
                <p class="text-2xl font-bold text-gray-700 dark:text-gray-300">{{ $analytics->pra_health->pending }}</p>
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase mt-0.5">Pending</p>
            </div>
            <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-center">
                <p class="text-2xl font-bold text-amber-600">{{ $analytics->pra_health->offline }}</p>
                <p class="text-xs font-semibold text-amber-700 dark:text-amber-400 uppercase mt-0.5">Offline Queue</p>
            </div>
            <div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-center">
                <p class="text-2xl font-bold text-red-600">{{ $analytics->pra_health->failed }}</p>
                <p class="text-xs font-semibold text-red-700 dark:text-red-400 uppercase mt-0.5">Failed</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 text-center">
                <p class="text-2xl font-bold text-gray-700 dark:text-gray-300">{{ $analytics->pra_health->not_reported }}</p>
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase mt-0.5">Not Reported</p>
            </div>
        </div>
        @if($analytics->pra_health->offline > 0 || $analytics->pra_health->failed > 0)
        <p class="text-xs text-amber-700 dark:text-amber-400 font-semibold mt-3">Kuch bills abhi PRA tak nahi pohnche — offline queue khud retry hoti rahegi; failed bills ko Bills page se Edit &amp; Retry karein.</p>
        @endif
    </div>

    {{-- Category-wise + Top products --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                Category-wise Sales
            </h3>
            @if($analytics->categories->isEmpty())
            <p class="text-sm text-gray-500">No item data for this day.</p>
            @else
            <div class="space-y-3">
                @foreach($analytics->categories as $catName => $cat)
                <div>
                    <div class="flex items-center justify-between text-sm mb-1">
                        <span class="font-medium text-gray-900 dark:text-white">{{ $catName }} <span class="text-xs text-gray-500">× {{ rtrim(rtrim(number_format($cat->qty, 2), '0'), '.') }}</span></span>
                        <span class="font-bold text-gray-900 dark:text-white">PKR {{ number_format($cat->revenue) }} <span class="text-xs font-semibold text-gray-500">({{ $cat->share }}%)</span></span>
                    </div>
                    <div class="w-full h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                        <div class="h-2 bg-purple-600 rounded-full" style="width: {{ min(100, $cat->share) }}%"></div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                Top Products of the Day
            </h3>
            @if($analytics->top_products->isEmpty())
            <p class="text-sm text-gray-500">No item data for this day.</p>
            @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($analytics->top_products as $pname => $p)
                        <tr>
                            <td class="px-3 py-2 text-sm font-bold text-purple-600">{{ $loop->iteration }}</td>
                            <td class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-white">{{ $pname }}</td>
                            <td class="px-3 py-2 text-sm text-center text-gray-700 dark:text-gray-300">{{ rtrim(rtrim(number_format($p->qty, 2), '0'), '.') }}</td>
                            <td class="px-3 py-2 text-sm text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($p->revenue) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>

    {{-- Hourly sales (CSS bar chart — no JS dependency) --}}
    @php
        $maxHourRevenue = max(1, collect($analytics->hourly)->max('revenue'));
        $activeHours = collect($analytics->hourly)->filter(fn ($h) => $h->count > 0);
    @endphp
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
            <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Hourly Sales
        </h3>
        @if($activeHours->isEmpty())
        <p class="text-sm text-gray-500 mt-3">No sales recorded yet.</p>
        @else
        <p class="text-xs text-gray-500 mb-4">Peak hour: <b class="text-gray-700 dark:text-gray-300">{{ \Carbon\Carbon::createFromTime($activeHours->sortByDesc('revenue')->keys()->first())->format('g A') }}</b> — PKR {{ number_format($activeHours->max('revenue')) }}</p>
        <div class="flex items-end gap-1 h-32">
            @foreach($analytics->hourly as $hour => $h)
            <div class="flex-1 flex flex-col items-center justify-end h-full" title="{{ \Carbon\Carbon::createFromTime($hour)->format('g A') }} — {{ $h->count }} bills, PKR {{ number_format($h->revenue) }}">
                <div class="w-full rounded-t {{ $h->revenue > 0 ? 'bg-purple-600' : 'bg-gray-100 dark:bg-gray-800' }}" style="height: {{ $h->revenue > 0 ? max(4, round($h->revenue / $maxHourRevenue * 100)) : 2 }}%"></div>
                <span class="text-[9px] text-gray-400 mt-1 {{ $hour % 3 === 0 ? '' : 'invisible' }}">{{ \Carbon\Carbon::createFromTime($hour)->format('gA') }}</span>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Discounts + restaurant extras --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                Discount Summary
            </h3>
            @if($analytics->discounts->total <= 0)
            <p class="text-sm text-gray-500">No discounts given this day.</p>
            @else
            <div class="grid grid-cols-2 gap-3">
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-xs text-gray-500 uppercase font-medium">Bills With Discount</p>
                    <p class="text-xl font-bold text-gray-900 dark:text-white mt-0.5">{{ $analytics->discounts->bill_count }}</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-xs text-gray-500 uppercase font-medium">Total Discount</p>
                    <p class="text-xl font-bold text-red-500 mt-0.5">PKR {{ number_format($analytics->discounts->total) }}</p>
                    @if($analytics->discounts->item_total > 0)
                    <p class="text-[10px] text-gray-500 mt-0.5">Bill: {{ number_format($analytics->discounts->bill_total) }} + Item: {{ number_format($analytics->discounts->item_total) }}</p>
                    @endif
                </div>
            </div>
            @endif

            @if($analytics->restaurant_enabled && $analytics->deals->isNotEmpty())
            <h4 class="font-semibold text-gray-900 dark:text-white text-sm mt-5 mb-2">Deals Performance</h4>
            <div class="space-y-2">
                @foreach($analytics->deals as $dealName => $deal)
                <div class="flex items-center justify-between p-2.5 bg-gray-50 dark:bg-gray-800 rounded-lg text-sm">
                    <span class="font-medium text-gray-900 dark:text-white">{{ $dealName }} <span class="text-xs text-gray-500">× {{ rtrim(rtrim(number_format($deal->qty, 2), '0'), '.') }}</span></span>
                    <span class="font-bold text-gray-900 dark:text-white">PKR {{ number_format($deal->revenue) }}</span>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        <div class="space-y-6">
            @if($analytics->restaurant_enabled && $analytics->order_types->isNotEmpty())
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h18v6H3zM3 15h18v6H3z"/></svg>
                    Order Type Split
                </h3>
                <div class="grid grid-cols-2 gap-3">
                    @foreach($analytics->order_types as $type => $ot)
                    <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <p class="text-xs text-gray-500 uppercase font-medium">{{ ['dine_in' => 'Dine-In', 'takeaway' => 'Takeaway', 'delivery' => 'Delivery', 'counter' => 'Counter'][$type] ?? ucfirst(str_replace('_', ' ', $type)) }}</p>
                        <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">{{ $ot->count }} <span class="text-xs font-semibold text-gray-500">bills</span></p>
                        <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">PKR {{ number_format($ot->revenue) }}</p>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Cash reconciliation (stored on closed report) --}}
            @if($existingReport && ($existingReport->counted_cash !== null || $existingReport->opening_float !== null))
            @php $variance = (float) $existingReport->cash_variance; @endphp
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    Cash Reconciliation
                </h3>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <p class="text-xs text-gray-500 uppercase font-medium">Opening Float</p>
                        <p class="font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($existingReport->opening_float ?? 0, 2) }}</p>
                    </div>
                    <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <p class="text-xs text-gray-500 uppercase font-medium">Cash Sales</p>
                        <p class="font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($existingReport->cash_amount, 2) }}</p>
                    </div>
                    <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <p class="text-xs text-gray-500 uppercase font-medium">Expected in Drawer</p>
                        <p class="font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($existingReport->expected_cash ?? 0, 2) }}</p>
                    </div>
                    <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <p class="text-xs text-gray-500 uppercase font-medium">Counted Cash</p>
                        <p class="font-bold text-gray-900 dark:text-white mt-0.5">{{ $existingReport->counted_cash !== null ? 'PKR ' . number_format($existingReport->counted_cash, 2) : '— (nahin gina gaya)' }}</p>
                    </div>
                </div>
                @if($existingReport->counted_cash !== null)
                <div class="mt-3 p-3 rounded-lg {{ abs($variance) < 0.01 ? 'bg-emerald-50 dark:bg-emerald-900/20' : ($variance < 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-amber-50 dark:bg-amber-900/20') }}">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-bold {{ abs($variance) < 0.01 ? 'text-emerald-700 dark:text-emerald-400' : ($variance < 0 ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400') }}">
                            {{ abs($variance) < 0.01 ? 'Balanced — no variance' : ($variance < 0 ? 'Short (kami)' : 'Over (zyada)') }}
                        </p>
                        <p class="text-lg font-bold {{ abs($variance) < 0.01 ? 'text-emerald-700 dark:text-emerald-400' : ($variance < 0 ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400') }}">{{ $variance > 0 ? '+' : '' }}{{ number_format($variance, 2) }}</p>
                    </div>
                </div>
                @endif
            </div>
            @endif
        </div>
    </div>

    {{-- Local / provisional bills — comprehensive wash preview (owner request Jul 2026).
         Shows exactly what the day-close wash will touch, INCLUDING backlog bills
         left over from earlier un-closed dates. --}}
    @if(!$existingReport && $lbPending > 0)
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-teal-200 dark:border-teal-800 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-1">Local Bills — Will Be Closed With This Day</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">In bills par PRA fiscal number nahi hai — day close par company policy ke mutabiq archive ya delete honge. Purani dates ke bache hue local bills bhi isi close mein shamil hain.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @if($localWash->prov_count > 0)
            <div class="p-3 bg-teal-50 dark:bg-teal-900/20 rounded-lg border border-teal-100 dark:border-teal-900/40">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase text-teal-700 dark:text-teal-300">Provisional Bills (L-series)</p>
                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold {{ ($company->pos_dayclose_provisional_action ?? 'save') === 'delete' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300' }}">{{ ($company->pos_dayclose_provisional_action ?? 'save') === 'delete' ? 'DELETE' : 'ARCHIVE' }}</span>
                </div>
                <p class="text-xl font-bold text-gray-900 dark:text-white mt-1">{{ $localWash->prov_count }} <span class="text-sm font-semibold text-gray-500">bills — PKR {{ number_format($localWash->prov_amount) }}</span></p>
                @if($localWash->prov_backlog > 0)
                <p class="text-xs text-amber-700 dark:text-amber-400 font-semibold mt-1">{{ $localWash->prov_backlog }} purani date(s) se pending</p>
                @endif
            </div>
            @endif
            @if($localWash->final_count > 0)
            <div class="p-3 bg-teal-50 dark:bg-teal-900/20 rounded-lg border border-teal-100 dark:border-teal-900/40">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase text-teal-700 dark:text-teal-300">Final Bills (Reporting OFF)</p>
                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold {{ ($company->pos_dayclose_final_local_action ?? 'save') === 'delete' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300' }}">{{ ($company->pos_dayclose_final_local_action ?? 'save') === 'delete' ? 'DELETE' : 'ARCHIVE' }}</span>
                </div>
                <p class="text-xl font-bold text-gray-900 dark:text-white mt-1">{{ $localWash->final_count }} <span class="text-sm font-semibold text-gray-500">bills — PKR {{ number_format($localWash->final_amount) }}</span></p>
                @if($localWash->final_backlog > 0)
                <p class="text-xs text-amber-700 dark:text-amber-400 font-semibold mt-1">{{ $localWash->final_backlog }} purani date(s) se pending</p>
                @endif
            </div>
            @endif
        </div>
    </div>
    @endif

    @if(!$existingReport)
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-3">Close Day</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Generate an official Day Close (Z-Report) for {{ \Carbon\Carbon::parse($date)->format('d M Y') }}. Once closed, the report becomes immutable and tamper-proof with SHA-256 hashing.</p>
        <form method="POST" action="{{ route('pos.close-day') }}">
            @csrf
            <input type="hidden" name="date" value="{{ $date }}">
            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Notes (Optional)</label>
                <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="Any additional notes for this day's report..."></textarea>
            </div>

            {{-- Cash reconciliation (optional, Z-report style): live variance preview via Alpine.
                 Rider adjustment (Jul 2026): unsettled rider cash is OUT of the drawer;
                 settlements received today for earlier days' bills are IN. --}}
            @php $rf = $riderFigures ?? ['active' => false, 'cash_out' => 0, 'cash_in' => 0, 'riders' => []]; @endphp
            @php $openingFromDayStart = ($dayOpening ?? null) !== null ? (float) $dayOpening->opening_cash : null; @endphp
            <div class="mb-4 p-4 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700"
                 x-data="{ float: '{{ $openingFromDayStart !== null ? $openingFromDayStart : '' }}', counted: '', cashSales: {{ (float) $stats->cash_amount }},
                           riderOut: {{ (float) ($rf['cash_out'] ?? 0) }}, riderIn: {{ (float) ($rf['cash_in'] ?? 0) }},
                           get expected() { return (parseFloat(this.float) || 0) + this.cashSales - this.riderOut + this.riderIn; },
                           get variance() { return this.counted === '' ? null : (parseFloat(this.counted) || 0) - this.expected; } }">
                <p class="text-sm font-bold text-gray-900 dark:text-white mb-1">Cash Reconciliation (Optional)</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Drawer ka cash gin kar enter karein — system expected cash se compare kar ke kami/zyadati report mein save karega.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Opening Float (subah drawer mein rakha cash)</label>
                        <input type="number" name="opening_float" x-model="float" step="0.01" min="0" placeholder="0.00"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                        @if($openingFromDayStart !== null)
                        <p class="text-[11px] text-teal-700 dark:text-teal-400 font-semibold mt-1">✓ Subah ka opening cash (Rs {{ number_format($openingFromDayStart, 2) }}) khud-ba-khud aa gaya hai</p>
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Counted Cash (ginti ke baad total)</label>
                        <input type="number" name="counted_cash" x-model="counted" step="0.01" min="0" placeholder="0.00"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs">
                    <span class="text-gray-600 dark:text-gray-400">Cash sales today: <b class="text-gray-900 dark:text-white">PKR {{ number_format($stats->cash_amount, 2) }}</b></span>
                    @if(!empty($rf['active']) && ($rf['cash_out'] ?? 0) > 0)
                    <span class="text-amber-700 dark:text-amber-400">Rider ke paas (unsettled): <b>− PKR {{ number_format($rf['cash_out'], 2) }}</b></span>
                    @endif
                    @if(!empty($rf['active']) && ($rf['cash_in'] ?? 0) > 0)
                    <span class="text-emerald-700 dark:text-emerald-400">Rider settlements (purane bills): <b>+ PKR {{ number_format($rf['cash_in'], 2) }}</b></span>
                    @endif
                    <span class="text-gray-600 dark:text-gray-400">Expected in drawer: <b class="text-gray-900 dark:text-white" x-text="'PKR ' + expected.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></b></span>
                    <template x-if="variance !== null">
                        <span class="font-bold" :class="Math.abs(variance) < 0.01 ? 'text-emerald-600' : (variance < 0 ? 'text-red-600' : 'text-amber-600')"
                              x-text="(Math.abs(variance) < 0.01 ? 'Balanced' : (variance < 0 ? 'Short: ' : 'Over: +')) + (Math.abs(variance) < 0.01 ? '' : variance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}))"></span>
                    </template>
                </div>
            </div>
            @php
                $lbFinal = in_array($company->pos_dayclose_final_local_action ?? 'save', ['save','delete'], true) ? ($company->pos_dayclose_final_local_action ?? 'save') : 'save';
                $lbProv  = in_array($company->pos_dayclose_provisional_action ?? 'save', ['save','delete'], true) ? ($company->pos_dayclose_provisional_action ?? 'save') : 'save';
            @endphp
            <div class="mb-4 p-3 rounded-lg bg-teal-50 dark:bg-teal-900/20 border border-teal-200 dark:border-teal-800">
                <div class="text-sm">
                    <span class="font-bold text-teal-800 dark:text-teal-300">Local bills policy (company setting)</span>
                    <p class="text-xs text-teal-700 dark:text-teal-400 mt-0.5">
                        Day-close par final local bills <b>{{ $lbFinal === 'delete' ? 'DELETE' : 'ARCHIVE (save)' }}</b> aur provisional bills <b>{{ $lbProv === 'delete' ? 'DELETE' : 'ARCHIVE (save)' }}</b> honge.
                        PRA ko bheje gaye bills bilkul untouched rahenge. Policy badalni ho to
                        <a href="{{ route('pos.customize') }}" class="underline font-semibold">Customize POS → Local Billing</a>.
                    </p>
                </div>
            </div>
            @if(($openOrders ?? 0) > 0)
            {{-- ZFC 28 Jul 2026: warn BEFORE closing when held orders / occupied
                 tables are still open — they dangle into tomorrow otherwise. --}}
            <div class="mb-4 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border-2 border-amber-400 dark:border-amber-700">
                <div class="text-sm">
                    <span class="font-bold text-amber-800 dark:text-amber-300">⚠️ {{ $openOrders }} open order{{ $openOrders > 1 ? 's' : '' }} abhi settle nahi {{ $openOrders > 1 ? 'hue' : 'hua' }}{{ ($occupiedTables ?? 0) > 0 ? ' — ' . $occupiedTables . ' table' . ($occupiedTables > 1 ? 's' : '') . ' occupied' : '' }}</span>
                    <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">
                        Day close karne se pehle held orders settle ya cancel kar lein — warna yeh orders (aur occupied tables) agle din ke TABLE board par latakte rahenge aur aaj ki sales mein count nahi honge.
                    </p>
                </div>
            </div>
            @endif
            <button type="submit" onclick="return confirm('{{ ($openOrders ?? 0) > 0 ? $openOrders . ' order(s) abhi OPEN hain! Phir bhi day close karna hai?\n\n' : '' }}Are you sure you want to close this day? This action cannot be undone.')"
                class="px-6 py-2.5 bg-purple-600 text-white font-semibold rounded-lg hover:bg-purple-700 transition text-sm flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                Close Day & Generate Z-Report
            </button>
        </form>
    </div>
    @endif

    @else
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-10 text-center mb-6">
        <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        <p class="text-gray-500 dark:text-gray-400">No transactions found for {{ \Carbon\Carbon::parse($date)->format('d M Y') }}</p>
    </div>
    @endif

    {{-- After close: what the wash actually did (stored on the report). OUTSIDE the
         sales gate above — a day closed with ONLY backlog local bills has zero PRA
         sales, yet its wash summary must still show. --}}
    @if($existingReport && is_array($existingReport->local_summary) && collect($existingReport->local_summary)->sum('count') > 0)
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Local Bills Closed With This Day</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @foreach(['provisional' => 'Provisional Bills (L-series)', 'final_local' => 'Final Bills (Reporting OFF)'] as $kind => $label)
                @php $ls = $existingReport->local_summary[$kind] ?? null; @endphp
                @if($ls && ($ls['count'] ?? 0) > 0)
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-bold uppercase text-gray-600 dark:text-gray-300">{{ $label }}</p>
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-bold {{ ($ls['action'] ?? 'save') === 'delete' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300' }}">{{ ($ls['action'] ?? 'save') === 'delete' ? 'DELETED' : 'ARCHIVED' }}</span>
                    </div>
                    <p class="text-xl font-bold text-gray-900 dark:text-white mt-1">{{ $ls['count'] }} <span class="text-sm font-semibold text-gray-500">bills — PKR {{ number_format($ls['amount'] ?? 0) }}</span></p>
                    @if(($ls['backlog'] ?? 0) > 0)
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $ls['backlog'] }} purani date(s) ke bhi shamil thay</p>
                    @endif
                </div>
                @endif
            @endforeach
        </div>
    </div>
    @endif

    {{-- Delivery Riders (Jul 2026): rider day detail stored on the closed report.
         Same placement logic as the wash summary — shows even when PRA sales are zero. --}}
    @if($existingReport && is_array($existingReport->rider_summary) && !empty($existingReport->rider_summary['riders']))
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-1">Delivery Riders — Day Summary</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
            Rider ke paas jo cash close ke waqt tha woh drawer ke expected cash se minus hota hai; purane dinon ke bills ki settlement plus hoti hai.
        </p>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-3 py-2">Rider</th>
                        <th class="px-3 py-2 text-center">Deliveries</th>
                        <th class="px-3 py-2 text-center">Delivered</th>
                        <th class="px-3 py-2 text-center">Returned</th>
                        <th class="px-3 py-2 text-right">Cash Bills</th>
                        <th class="px-3 py-2 text-right">Unsettled at Close</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($existingReport->rider_summary['riders'] as $rr)
                    <tr>
                        <td class="px-3 py-2 font-medium text-gray-900 dark:text-white">{{ $rr['name'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-center text-gray-700 dark:text-gray-300">{{ $rr['deliveries'] ?? 0 }}</td>
                        <td class="px-3 py-2 text-center text-emerald-600 dark:text-emerald-400">{{ $rr['delivered'] ?? 0 }}</td>
                        <td class="px-3 py-2 text-center {{ ($rr['returned'] ?? 0) > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400' }}">{{ $rr['returned'] ?? 0 }}</td>
                        <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300">PKR {{ number_format($rr['cash_total'] ?? 0) }}</td>
                        <td class="px-3 py-2 text-right font-semibold {{ ($rr['cash_pending'] ?? 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ ($rr['cash_pending'] ?? 0) > 0 ? 'PKR ' . number_format($rr['cash_pending']) : 'Clear' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs">
            @if(($existingReport->rider_summary['cash_out'] ?? 0) > 0)
            <span class="text-amber-700 dark:text-amber-400">Rider ke paas at close: <b>− PKR {{ number_format($existingReport->rider_summary['cash_out'], 2) }}</b></span>
            @endif
            @if(($existingReport->rider_summary['cash_in'] ?? 0) > 0)
            <span class="text-emerald-700 dark:text-emerald-400">Purane bills ki settlement aaj: <b>+ PKR {{ number_format($existingReport->rider_summary['cash_in'], 2) }}</b></span>
            @endif
        </div>
    </div>
    @endif

    @if(auth('pos')->user() && !auth('pos')->user()->isPosCashier())
    @php
        $currentCutoff = \App\Services\PosBusinessDay::cutoffFor($company->id);
        $cutoffOptions = [];
        for ($h = 0; $h < 12; $h++) {
            foreach (['00', '30'] as $m) {
                $val = str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . $m;
                $cutoffOptions[$val] = \Carbon\Carbon::createFromFormat('H:i', $val)->format('g:i A');
            }
        }
    @endphp
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6"
         x-data="{ cutoff: '{{ $currentCutoff }}', saving: false, msg: '', ok: true,
            save() {
                this.saving = true; this.msg = '';
                fetch('{{ route('pos.settings.dayclose-cutoff') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ cutoff: this.cutoff }) })
                    .then(r => r.json())
                    .then(d => { this.ok = !!(d && d.success); this.msg = (d && d.message) || (this.ok ? 'Saved.' : 'Setting save nahi hui — dobara koshish karein.'); })
                    .catch(() => { this.ok = false; this.msg = 'Setting save nahi hui — dobara koshish karein.'; })
                    .finally(() => { this.saving = false; });
            } }">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">Din band hone ka waqt</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-xl">
                    Is waqt se pehle ki sales <span class="font-semibold">pichhle din</span> mein shumar hongi — Z-Report, dashboard aur sales reports sab isi hisaab se banenge.
                    Auto day-close bhi isi waqt par hoga. Tax record (PRA/FBR) hamesha asal waqt par rehta hai.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <select x-model="cutoff" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                    @foreach($cutoffOptions as $val => $label)
                    <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
                <button type="button" @click="save()" :disabled="saving"
                    class="px-4 py-2 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition disabled:opacity-50">
                    <span x-show="!saving">Save</span><span x-show="saving" x-cloak>Saving…</span>
                </button>
            </div>
        </div>
        <p x-show="msg" x-cloak class="text-xs mt-2" :class="ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'" x-text="msg"></p>
    </div>
    @endif

    @if($previousReports->isNotEmpty())
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Previous Day Close Reports</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Report #</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Invoices</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Tax</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($previousReports as $rpt)
                    <tr>
                        <td class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-white">{{ $rpt->report_number }}</td>
                        <td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $rpt->report_date->format('d M Y') }}</td>
                        <td class="px-3 py-2 text-sm text-center text-gray-700 dark:text-gray-300">{{ $rpt->total_invoices }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($rpt->total_amount, 2) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-purple-600">PKR {{ number_format($rpt->total_tax, 2) }}</td>
                        <td class="px-3 py-2 text-sm text-center">
                            <a href="{{ route('pos.day-close-pdf', $rpt->id) }}" class="text-purple-600 hover:text-purple-800 font-medium">PDF</a>
                            <span class="mx-1 text-gray-300">|</span>
                            <a href="{{ route('pos.day-close', ['date' => $rpt->report_date->format('Y-m-d')]) }}" class="text-gray-600 hover:text-gray-800 dark:text-gray-400 font-medium">View</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
</x-pos-layout>
