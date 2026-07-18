<x-fbr-pos-layout>
<div class="max-w-7xl mx-auto">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Day Close Report (Z-Report)</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">FBR Rule 150R-4(f) — End-of-Day Summary</p>
        </div>
        <form method="GET" action="{{ route('fbrpos.day-close') }}" class="flex items-center gap-2">
            <input type="date" name="date" value="{{ $date }}" max="{{ today()->format('Y-m-d') }}"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition">View</button>
        </form>
    </div>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm border border-emerald-200 dark:border-emerald-800">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm border border-red-200 dark:border-red-800">{{ session('error') }}</div>
    @endif

    @if($existingReport)
    <div class="mb-6 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800">
        <div class="flex items-center justify-between">
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
                <a href="{{ route('fbrpos.day-close-thermal', $existingReport->id) }}" target="_blank" class="px-4 py-2 bg-gray-800 text-white text-sm font-semibold rounded-lg hover:bg-gray-900 transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4H7v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    Thermal Print
                </a>
                <a href="{{ route('fbrpos.day-close-pdf', $existingReport->id) }}" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition flex items-center gap-2">
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

    @if($stats->total_invoices > 0)
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Invoices</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ $stats->total_invoices }}</p>
            <div class="flex gap-2 mt-2">
                <span class="text-xs px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">FBR: {{ $stats->fbr_invoices }}</span>
                <span class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">Local: {{ $stats->local_invoices }}</span>
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
            <p class="text-2xl font-bold text-blue-600 mt-1">PKR {{ number_format($stats->total_tax) }}</p>
            @if($stats->total_fbr_fee > 0)
            <p class="text-xs text-gray-500 mt-1">FBR Fee: PKR {{ number_format($stats->total_fbr_fee) }}</p>
            @endif
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Net Revenue</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1">PKR {{ number_format($stats->total_amount) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
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
                        <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        </div>
                        <span class="font-medium text-gray-900 dark:text-white">Card</span>
                    </div>
                    <span class="font-bold text-gray-900 dark:text-white">PKR {{ number_format($stats->card_amount, 2) }}</span>
                </div>
                @if($stats->other_amount > 0)
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
                <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
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
                            <td class="px-3 py-2 text-sm text-right text-blue-600">PKR {{ number_format($data->tax, 2) }}</td>
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

    {{-- ═══ Comprehensive Z-Report analytics (owner request Jul 2026 — FBR mirror) ═══ --}}

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

    {{-- FBR submission health --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            FBR Submission Health
        </h3>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center">
                <p class="text-2xl font-bold text-emerald-600">{{ $analytics->fbr_health->submitted }}</p>
                <p class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase mt-0.5">Submitted</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 text-center">
                <p class="text-2xl font-bold text-gray-700 dark:text-gray-300">{{ $analytics->fbr_health->pending }}</p>
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase mt-0.5">Pending</p>
            </div>
            <div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-center">
                <p class="text-2xl font-bold text-red-600">{{ $analytics->fbr_health->failed }}</p>
                <p class="text-xs font-semibold text-red-700 dark:text-red-400 uppercase mt-0.5">Failed</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 text-center">
                <p class="text-2xl font-bold text-gray-700 dark:text-gray-300">{{ $analytics->fbr_health->local }}</p>
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase mt-0.5">Local</p>
            </div>
        </div>
        @if($analytics->fbr_health->failed > 0)
        <p class="text-xs text-amber-700 dark:text-amber-400 font-semibold mt-3">Kuch bills FBR tak nahi pohnche — failed bills ko Bills page se Edit &amp; Retry karein.</p>
        @endif
    </div>

    {{-- Top products + Hourly sales --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
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
                            <td class="px-3 py-2 text-sm font-bold text-blue-600">{{ $loop->iteration }}</td>
                            <td class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-white">{{ $pname }} <span class="text-xs text-gray-500">({{ $p->share }}%)</span></td>
                            <td class="px-3 py-2 text-sm text-center text-gray-700 dark:text-gray-300">{{ rtrim(rtrim(number_format($p->qty, 2), '0'), '.') }}</td>
                            <td class="px-3 py-2 text-sm text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($p->revenue) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>

        {{-- Hourly sales (CSS bar chart — no JS dependency) --}}
        @php
            $maxHourRevenue = max(1, collect($analytics->hourly)->max('revenue'));
            $activeHours = collect($analytics->hourly)->filter(fn ($h) => $h->count > 0);
        @endphp
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Hourly Sales
            </h3>
            @if($activeHours->isEmpty())
            <p class="text-sm text-gray-500 mt-3">No sales recorded yet.</p>
            @else
            <p class="text-xs text-gray-500 mb-4">Peak hour: <b class="text-gray-700 dark:text-gray-300">{{ \Carbon\Carbon::createFromTime($activeHours->sortByDesc('revenue')->keys()->first())->format('g A') }}</b> — PKR {{ number_format($activeHours->max('revenue')) }}</p>
            <div class="flex items-end gap-1 h-32">
                @foreach($analytics->hourly as $hour => $h)
                <div class="flex-1 flex flex-col items-center justify-end h-full" title="{{ \Carbon\Carbon::createFromTime($hour)->format('g A') }} — {{ $h->count }} bills, PKR {{ number_format($h->revenue) }}">
                    <div class="w-full rounded-t {{ $h->revenue > 0 ? 'bg-blue-600' : 'bg-gray-100 dark:bg-gray-800' }}" style="height: {{ $h->revenue > 0 ? max(4, round($h->revenue / $maxHourRevenue * 100)) : 2 }}%"></div>
                    <span class="text-[9px] text-gray-400 mt-1 {{ $hour % 3 === 0 ? '' : 'invisible' }}">{{ \Carbon\Carbon::createFromTime($hour)->format('gA') }}</span>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    {{-- Discounts + cash reconciliation (stored on closed report) --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
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
        </div>

        @if($existingReport && $existingReport->counted_cash !== null)
        @php $variance = (float) $existingReport->cash_variance; @endphp
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
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
                    <p class="font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($existingReport->counted_cash, 2) }}</p>
                </div>
            </div>
            <div class="mt-3 p-3 rounded-lg {{ abs($variance) < 0.01 ? 'bg-emerald-50 dark:bg-emerald-900/20' : ($variance < 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-amber-50 dark:bg-amber-900/20') }}">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-bold {{ abs($variance) < 0.01 ? 'text-emerald-700 dark:text-emerald-400' : ($variance < 0 ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400') }}">
                        {{ abs($variance) < 0.01 ? 'Balanced — no variance' : ($variance < 0 ? 'Short (kami)' : 'Over (zyada)') }}
                    </p>
                    <p class="text-lg font-bold {{ abs($variance) < 0.01 ? 'text-emerald-700 dark:text-emerald-400' : ($variance < 0 ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400') }}">{{ $variance > 0 ? '+' : '' }}{{ number_format($variance, 2) }}</p>
                </div>
            </div>
        </div>
        @endif
    </div>

    @if(!$existingReport)
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-3">Close Day</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Generate an official Day Close (Z-Report) for {{ \Carbon\Carbon::parse($date)->format('d M Y') }}. This is required under FBR Rule 150R-4(f). Once closed, the report becomes immutable.</p>
        <form method="POST" action="{{ route('fbrpos.close-day') }}">
            @csrf
            <input type="hidden" name="date" value="{{ $date }}">
            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Notes (Optional)</label>
                <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="Any additional notes for this day's report..."></textarea>
            </div>

            {{-- Cash reconciliation (optional, Z-report style): live variance preview via Alpine --}}
            <div class="mb-4 p-4 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700"
                 x-data="{ float: '', counted: '', cashSales: {{ (float) $stats->cash_amount }},
                           get expected() { return (parseFloat(this.float) || 0) + this.cashSales; },
                           get variance() { return this.counted === '' ? null : (parseFloat(this.counted) || 0) - this.expected; } }">
                <p class="text-sm font-bold text-gray-900 dark:text-white mb-1">Cash Reconciliation (Optional)</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Drawer ka cash gin kar enter karein — system expected cash se compare kar ke kami/zyadati report mein save karega.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Opening Float (subah drawer mein rakha cash)</label>
                        <input type="number" name="opening_float" x-model="float" step="0.01" min="0" placeholder="0.00"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Counted Cash (ginti ke baad total)</label>
                        <input type="number" name="counted_cash" x-model="counted" step="0.01" min="0" placeholder="0.00"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs">
                    <span class="text-gray-600 dark:text-gray-400">Cash sales today: <b class="text-gray-900 dark:text-white">PKR {{ number_format($stats->cash_amount, 2) }}</b></span>
                    <span class="text-gray-600 dark:text-gray-400">Expected in drawer: <b class="text-gray-900 dark:text-white" x-text="'PKR ' + expected.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></b></span>
                    <template x-if="variance !== null">
                        <span class="font-bold" :class="Math.abs(variance) < 0.01 ? 'text-emerald-600' : (variance < 0 ? 'text-red-600' : 'text-amber-600')"
                              x-text="(Math.abs(variance) < 0.01 ? 'Balanced' : (variance < 0 ? 'Short: ' : 'Over: +')) + (Math.abs(variance) < 0.01 ? '' : variance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}))"></span>
                    </template>
                </div>
            </div>
            <button type="submit" onclick="return confirm('Are you sure you want to close this day? This action cannot be undone.')"
                class="px-6 py-2.5 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition text-sm flex items-center gap-2">
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
                        <td class="px-3 py-2 text-sm text-right text-blue-600">PKR {{ number_format($rpt->total_tax, 2) }}</td>
                        <td class="px-3 py-2 text-sm text-center">
                            <a href="{{ route('fbrpos.day-close-pdf', $rpt->id) }}" class="text-blue-600 hover:text-blue-800 font-medium">PDF</a>
                            <span class="mx-1 text-gray-300">|</span>
                            <a href="{{ route('fbrpos.day-close', ['date' => $rpt->report_date->format('Y-m-d')]) }}" class="text-gray-600 hover:text-gray-800 dark:text-gray-400 font-medium">View</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
</x-fbr-pos-layout>
