<x-fbr-pos-layout>
<div class="max-w-7xl mx-auto">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.day_close_report_z') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.fbr_rule_eod_summary') }}</p>
        </div>
        <form method="GET" action="{{ route('fbrpos.day-close') }}" class="flex items-center gap-2">
            <input type="date" name="date" value="{{ $date }}" max="{{ today()->format('Y-m-d') }}"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition">{{ __('pos.view_word') }}</button>
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
                    <p class="font-bold text-emerald-800 dark:text-emerald-300">{{ __('pos.day_closed_num', ['num' => $existingReport->report_number]) }}</p>
                    <p class="text-xs text-emerald-600 dark:text-emerald-400">{{ __('pos.closed_on', ['datetime' => $existingReport->created_at->format('d M Y h:i A')]) }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('fbrpos.day-close-thermal', $existingReport->id) }}" target="_blank" class="px-4 py-2 bg-gray-800 text-white text-sm font-semibold rounded-lg hover:bg-gray-900 transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4H7v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    {{ __('pos.thermal_print') }}
                </a>
                <a href="{{ route('fbrpos.day-close-pdf', $existingReport->id) }}" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    {{ __('pos.download_pdf') }}
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
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.total_invoices') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ $stats->total_invoices }}</p>
            <div class="flex gap-2 mt-2">
                <span class="text-xs px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">{{ __('pos.fbr_colon', ['count' => $stats->fbr_invoices]) }}</span>
                <span class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">{{ __('pos.local_colon', ['count' => $stats->local_invoices]) }}</span>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.gross_sales') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($stats->gross_sales) }}</p>
            @if($stats->total_discount > 0)
            <p class="text-xs text-red-500 mt-1">{{ __('pos.discount_minus_pkr', ['amount' => number_format($stats->total_discount)]) }}</p>
            @endif
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.total_tax') }}</p>
            <p class="text-2xl font-bold text-blue-600 mt-1">PKR {{ number_format($stats->total_tax) }}</p>
            @if($stats->total_fbr_fee > 0)
            <p class="text-xs text-gray-500 mt-1">{{ __('pos.fbr_fee_pkr', ['amount' => number_format($stats->total_fbr_fee)]) }}</p>
            @endif
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.net_revenue') }}</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1">PKR {{ number_format($stats->total_amount) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                {{ __('pos.payment_breakdown') }}
            </h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        </div>
                        <span class="font-medium text-gray-900 dark:text-white">{{ __('pos.cash_word') }}</span>
                    </div>
                    <span class="font-bold text-gray-900 dark:text-white">PKR {{ number_format($stats->cash_amount, 2) }}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        </div>
                        <span class="font-medium text-gray-900 dark:text-white">{{ __('pos.card_word') }}</span>
                    </div>
                    <span class="font-bold text-gray-900 dark:text-white">PKR {{ number_format($stats->card_amount, 2) }}</span>
                </div>
                @if($stats->other_amount > 0)
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <span class="font-medium text-gray-900 dark:text-white">{{ __('pos.other_word') }}</span>
                    </div>
                    <span class="font-bold text-gray-900 dark:text-white">PKR {{ number_format($stats->other_amount, 2) }}</span>
                </div>
                @endif
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                {{ __('pos.cashier_breakdown') }}
            </h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('pos.cashier_word') }}</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">{{ __('pos.sales_word') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('pos.revenue_word') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('pos.tax_word') }}</th>
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
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.invoice_range') }}</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.first_invoice') }}</p>
                <p class="font-bold text-gray-900 dark:text-white">{{ $stats->first_invoice->invoice_number ?? '-' }}</p>
                <p class="text-xs text-gray-500">{{ $stats->first_invoice ? $stats->first_invoice->created_at->format('h:i A') : '-' }}</p>
            </div>
            <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.last_invoice') }}</p>
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
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.average_bill') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($analytics->avg_bill) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.unique_customers') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ $analytics->unique_customers }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ __('pos.named_saved_customers_only') }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.vs_yesterday') }}</p>
            <div class="flex items-baseline gap-2 mt-1">
                <p class="text-lg font-bold text-gray-900 dark:text-white">PKR {{ number_format($cmp->yesterday->revenue) }}</p>
                {!! $pctBadge($cmp->vs_yesterday_revenue_pct) !!}
            </div>
            <p class="text-xs text-gray-500 mt-1">{{ __('pos.n_bills', ['count' => $cmp->yesterday->invoices]) }} {!! $pctBadge($cmp->vs_yesterday_invoices_pct) !!}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.vs_last_weekday', ['weekday' => \Carbon\Carbon::parse($date)->format('l')]) }}</p>
            <div class="flex items-baseline gap-2 mt-1">
                <p class="text-lg font-bold text-gray-900 dark:text-white">PKR {{ number_format($cmp->last_week->revenue) }}</p>
                {!! $pctBadge($cmp->vs_last_week_revenue_pct) !!}
            </div>
            <p class="text-xs text-gray-500 mt-1">{{ __('pos.n_bills', ['count' => $cmp->last_week->invoices]) }} {!! $pctBadge($cmp->vs_last_week_invoices_pct) !!}</p>
        </div>
    </div>

    {{-- FBR submission health --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            {{ __('pos.fbr_submission_health') }}
        </h3>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center">
                <p class="text-2xl font-bold text-emerald-600">{{ $analytics->fbr_health->submitted }}</p>
                <p class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase mt-0.5">{{ __('pos.submitted_word') }}</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 text-center">
                <p class="text-2xl font-bold text-gray-700 dark:text-gray-300">{{ $analytics->fbr_health->pending }}</p>
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase mt-0.5">{{ __('pos.pending_word') }}</p>
            </div>
            <div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-center">
                <p class="text-2xl font-bold text-red-600">{{ $analytics->fbr_health->failed }}</p>
                <p class="text-xs font-semibold text-red-700 dark:text-red-400 uppercase mt-0.5">{{ __('pos.failed_word') }}</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 text-center">
                <p class="text-2xl font-bold text-gray-700 dark:text-gray-300">{{ $analytics->fbr_health->local }}</p>
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase mt-0.5">{{ __('pos.local_word') }}</p>
            </div>
        </div>
        @if($analytics->fbr_health->failed > 0)
        <p class="text-xs text-amber-700 dark:text-amber-400 font-semibold mt-3">{!! __('pos.some_bills_not_reached_fbr') !!}</p>
        @endif
    </div>

    {{-- Top products + Hourly sales --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                {{ __('pos.top_products_of_day') }}
            </h3>
            @if($analytics->top_products->isEmpty())
            <p class="text-sm text-gray-500">{{ __('pos.no_item_data_day') }}</p>
            @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('pos.product_col') }}</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">{{ __('pos.qty_word') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('pos.revenue_word') }}</th>
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
                {{ __('pos.hourly_sales') }}
            </h3>
            @if($activeHours->isEmpty())
            <p class="text-sm text-gray-500 mt-3">{{ __('pos.no_sales_recorded_yet') }}</p>
            @else
            <p class="text-xs text-gray-500 mb-4">{!! __('pos.peak_hour_line', ['hour' => \Carbon\Carbon::createFromTime($activeHours->sortByDesc('revenue')->keys()->first())->format('g A'), 'amount' => number_format($activeHours->max('revenue'))]) !!}</p>
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
                {{ __('pos.discount_summary') }}
            </h3>
            @if($analytics->discounts->total <= 0)
            <p class="text-sm text-gray-500">{{ __('pos.no_discounts_day') }}</p>
            @else
            <div class="grid grid-cols-2 gap-3">
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.bills_with_discount') }}</p>
                    <p class="text-xl font-bold text-gray-900 dark:text-white mt-0.5">{{ $analytics->discounts->bill_count }}</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.total_discount') }}</p>
                    <p class="text-xl font-bold text-red-500 mt-0.5">PKR {{ number_format($analytics->discounts->total) }}</p>
                    @if($analytics->discounts->item_total > 0)
                    <p class="text-[10px] text-gray-500 mt-0.5">{{ __('pos.bill_plus_item', ['bill' => number_format($analytics->discounts->bill_total), 'item' => number_format($analytics->discounts->item_total)]) }}</p>
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
                {{ __('pos.cash_reconciliation') }}
            </h3>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.opening_float') }}</p>
                    <p class="font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($existingReport->opening_float ?? 0, 2) }}</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.cash_sales') }}</p>
                    <p class="font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($existingReport->cash_amount, 2) }}</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.expected_in_drawer') }}</p>
                    <p class="font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($existingReport->expected_cash ?? 0, 2) }}</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.counted_cash') }}</p>
                    <p class="font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($existingReport->counted_cash, 2) }}</p>
                </div>
            </div>
            <div class="mt-3 p-3 rounded-lg {{ abs($variance) < 0.01 ? 'bg-emerald-50 dark:bg-emerald-900/20' : ($variance < 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-amber-50 dark:bg-amber-900/20') }}">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-bold {{ abs($variance) < 0.01 ? 'text-emerald-700 dark:text-emerald-400' : ($variance < 0 ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400') }}">
                        {{ abs($variance) < 0.01 ? __('pos.balanced_no_variance') : ($variance < 0 ? __('pos.short_kami') : __('pos.over_zyada')) }}
                    </p>
                    <p class="text-lg font-bold {{ abs($variance) < 0.01 ? 'text-emerald-700 dark:text-emerald-400' : ($variance < 0 ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400') }}">{{ $variance > 0 ? '+' : '' }}{{ number_format($variance, 2) }}</p>
                </div>
            </div>
        </div>
        @endif
    </div>

    @if(!$existingReport)
    {{-- 'Khud Final' policy notice (Aug 2026, PRA UX mirror): when auto-finalize is
         ON and pending local bills exist, warn the cashier BEFORE the close so the
         auto-FINAL sweep is never a surprise. Wording follows reporting ON/OFF. --}}
    @if(($pendingAutoFinal ?? 0) > 0)
    <div class="mb-6 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border-2 border-emerald-400 dark:border-emerald-700">
        <p class="text-sm font-bold text-emerald-800 dark:text-emerald-300">
            {{ __(($company->fbr_reporting_enabled ?? false) ? 'pos.fbr_auto_final_notice_on' : 'pos.fbr_auto_final_notice_off', ['count' => $pendingAutoFinal]) }}
        </p>
        <p class="text-xs text-emerald-700 dark:text-emerald-400 mt-0.5">{{ __('pos.fbr_auto_final_notice_hint') }}</p>
    </div>
    @endif
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-3">{{ __('pos.close_day') }}</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.close_day_desc', ['date' => \Carbon\Carbon::parse($date)->format('d M Y')]) }}</p>
        <form method="POST" action="{{ route('fbrpos.close-day') }}">
            @csrf
            <input type="hidden" name="date" value="{{ $date }}">
            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.notes_optional') }}</label>
                <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('pos.ph_day_report_notes') }}"></textarea>
            </div>

            {{-- Cash reconciliation (optional, Z-report style): live variance preview via Alpine --}}
            <div class="mb-4 p-4 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700"
                 x-data="{ float: '', counted: '', cashSales: {{ (float) $stats->cash_amount }},
                           get expected() { return (parseFloat(this.float) || 0) + this.cashSales; },
                           get variance() { return this.counted === '' ? null : (parseFloat(this.counted) || 0) - this.expected; } }">
                <p class="text-sm font-bold text-gray-900 dark:text-white mb-1">{{ __('pos.cash_reconciliation_optional') }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.cash_recon_hint') }}</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.opening_float_hint_label') }}</label>
                        <input type="number" name="opening_float" x-model="float" step="0.01" min="0" placeholder="0.00"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.counted_cash_hint_label') }}</label>
                        <input type="number" name="counted_cash" x-model="counted" step="0.01" min="0" placeholder="0.00"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs">
                    <span class="text-gray-600 dark:text-gray-400">{!! __('pos.cash_sales_today_line', ['amount' => number_format($stats->cash_amount, 2)]) !!}</span>
                    <span class="text-gray-600 dark:text-gray-400">{{ __('pos.expected_in_drawer_label') }} <b class="text-gray-900 dark:text-white" x-text="'PKR ' + expected.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></b></span>
                    <template x-if="variance !== null">
                        <span class="font-bold" :class="Math.abs(variance) < 0.01 ? 'text-emerald-600' : (variance < 0 ? 'text-red-600' : 'text-amber-600')"
                              x-text="(Math.abs(variance) < 0.01 ? @js(__('pos.balanced_word')) : (variance < 0 ? @js(__('pos.short_prefix')) : @js(__('pos.over_prefix')))) + (Math.abs(variance) < 0.01 ? '' : variance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}))"></span>
                    </template>
                </div>
            </div>
            <button type="submit" onclick="return confirm(@js(__('pos.confirm_close_day')))"
                class="px-6 py-2.5 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition text-sm flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                {{ __('pos.close_day_generate_z') }}
            </button>
        </form>
    </div>
    @endif

    @else
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-10 text-center mb-6">
        <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        <p class="text-gray-500 dark:text-gray-400">{{ __('pos.no_transactions_for_date', ['date' => \Carbon\Carbon::parse($date)->format('d M Y')]) }}</p>
    </div>
    @endif

    @if($previousReports->isNotEmpty())
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.previous_day_close_reports') }}</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('pos.report_hash') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('pos.date_word') }}</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">{{ __('pos.invoices_word') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('pos.revenue_word') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('pos.tax_word') }}</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">{{ __('pos.actions_col') }}</th>
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
                            <a href="{{ route('fbrpos.day-close-pdf', $rpt->id) }}" class="text-blue-600 hover:text-blue-800 font-medium">{{ __('pos.pdf_word') }}</a>
                            <span class="mx-1 text-gray-300">|</span>
                            <a href="{{ route('fbrpos.day-close', ['date' => $rpt->report_date->format('Y-m-d')]) }}" class="text-gray-600 hover:text-gray-800 dark:text-gray-400 font-medium">{{ __('pos.view_word') }}</a>
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
