<x-fbr-pos-layout>
@php
    $fbrRidersRelevant = \App\Services\PosFeatureService::moduleRelevant($company, 'riders_enabled');
    $fbrVocab = \App\Support\PosVocabulary::for($company);
@endphp
<div class="max-w-7xl mx-auto">
    @include('fbr-pos.partials.back-link')
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

    {{-- Stranded-day banner (Task 479 — FBR mirror of PRA Task 455): prior
         day(s) never closed — nobody ran day-close. Surface them loudly
         before more bills pile onto today. --}}
    @if(($unclosedPriorDays ?? collect())->isNotEmpty())
    <div class="mb-6 p-4 rounded-xl bg-red-50 dark:bg-red-900/20 border-2 border-red-300 dark:border-red-800">
        <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/40 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="font-bold text-red-800 dark:text-red-300">{{ __('pos.dc_prior_open_title') }}</p>
                <p class="text-sm text-red-700 dark:text-red-400 mt-0.5">{{ __('pos.dc_prior_open_msg') }}</p>
                {{-- Task 519 (FBR mirror of PRA Task 516): bulk close — one click
                     closes ALL stranded prior days chronologically via the same
                     performDayClose routine. --}}
                <form method="POST" action="{{ route('fbrpos.close-all-days') }}" class="mt-3"
                      onsubmit="return confirm(@js(__('pos.dc_bulk_close_confirm', ['count' => $unclosedPriorDays->count()])));">
                    @csrf
                    <button type="submit"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-red-700 text-white text-sm font-bold rounded-lg hover:bg-red-800 transition shadow-sm">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        {{ __('pos.dc_close_all_days_btn', ['count' => $unclosedPriorDays->count()]) }}
                    </button>
                </form>
                <div class="flex flex-wrap gap-2 mt-3">
                    @foreach($unclosedPriorDays as $openDay)
                    <a href="{{ route('fbrpos.day-close', ['date' => $openDay]) }}"
                       class="inline-flex items-center gap-2 px-3 py-1.5 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        {{ __('pos.dc_close_this_day', ['date' => \Carbon\Carbon::parse($openDay)->format('d M Y')]) }}
                    </a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    @endif

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
                <a href="{{ route('fbrpos.day-close-pdf', $existingReport->id) }}" target="_blank" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    {{ __('pos.view_word') }}
                </a>
                <a href="{{ route('fbrpos.day-close-pdf-download', $existingReport->id) }}" class="px-4 py-2 bg-emerald-700 text-white text-sm font-semibold rounded-lg hover:bg-emerald-800 transition">{{ __('pos.download_pdf') }}</a>
            </div>
        </div>
    </div>
    @endif

    <div class="text-center mb-6">
        <p class="text-lg font-bold text-gray-900 dark:text-white">{{ \Carbon\Carbon::parse($date)->format('l, d F Y') }}</p>
    </div>

    {{-- Task 607: gate on the day's transactions (sales OR credit notes) —
         total_invoices is now SALES-only, so a return-only day must still
         render its (negative) Z-report and stay closable. --}}
    @if($transactions->count() > 0)
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
                {{-- Task 607: udhaar is SIGNED (credit refunds net it) — render any non-zero value --}}
                @if(abs((float) $stats->udhaar_amount) > 0.004)
                <div class="flex items-center justify-between p-3 bg-orange-50 dark:bg-orange-900/20 rounded-lg">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                        </div>
                        <div>
                            <span class="font-medium text-gray-900 dark:text-white">{{ __('pos.dc_udhaar') }}</span>
                            <p class="text-xs text-orange-600 dark:text-orange-400">{{ __('pos.dc_udhaar_not_in_drawer') }}</p>
                        </div>
                    </div>
                    <span class="font-bold text-orange-700 dark:text-orange-400">PKR {{ number_format($stats->udhaar_amount, 2) }}</span>
                </div>
                @endif
                @if(abs((float) $stats->other_amount) > 0.004)
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
                {{ __('pos.fbr_vocab_top_items', ['items' => $fbrVocab['items']]) }}
            </h3>
            @if($analytics->top_products->isEmpty())
            <p class="text-sm text-gray-500">{{ __('pos.no_item_data_day') }}</p>
            @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ $fbrVocab['item'] }}</th>
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

    {{-- Delivery Riders (Task 541 — FBR mirror of the PRA Jul 2026 card):
         rider day detail stored on the closed report. --}}
    {{-- Shown whenever the stored summary carries rider rows OR a nonzero
         cash figure — a cash-in-only day (old bills settled today, no new
         rider bills) must still surface the money movement. --}}
    @if($fbrRidersRelevant && $existingReport && is_array($existingReport->rider_summary) && (!empty($existingReport->rider_summary['riders']) || ($existingReport->rider_summary['cash_out'] ?? 0) > 0 || ($existingReport->rider_summary['cash_in'] ?? 0) > 0))
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.delivery_riders_day_summary') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
            {{ __('pos.rider_cash_summary_hint') }}
        </p>
        @if(!empty($existingReport->rider_summary['riders']))
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-3 py-2">{{ __('pos.role_rider') }}</th>
                        <th class="px-3 py-2 text-center">{{ __('pos.th_deliveries') }}</th>
                        <th class="px-3 py-2 text-center">{{ __('pos.th_delivered') }}</th>
                        <th class="px-3 py-2 text-center">{{ __('pos.th_returned') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('pos.th_cash_bills') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('pos.th_unsettled_at_close') }}</th>
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
                        <td class="px-3 py-2 text-right font-semibold {{ ($rr['cash_pending'] ?? 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ ($rr['cash_pending'] ?? 0) > 0 ? 'PKR ' . number_format($rr['cash_pending']) : __('pos.clear_word') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
        <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs">
            @if(($existingReport->rider_summary['cash_out'] ?? 0) > 0)
            <span class="text-amber-700 dark:text-amber-400">{{ __('pos.with_rider_at_close') }} <b>− PKR {{ number_format($existingReport->rider_summary['cash_out'], 2) }}</b></span>
            @endif
            @if(($existingReport->rider_summary['cash_in'] ?? 0) > 0)
            <span class="text-emerald-700 dark:text-emerald-400">{{ __('pos.old_bills_settled_today') }} <b>+ PKR {{ number_format($existingReport->rider_summary['cash_in'], 2) }}</b></span>
            @endif
        </div>
    </div>
    @endif

    {{-- Pending-bill decision audit (Task 691 — FBR mirror of PRA's
         local_summary card): a durable record of what happened to the day's
         Local (pending) bills at close — finalize sweep outcome, per-bill
         picks, deletes, and rider-khata guarded bills. Rendered from the
         STORED snapshot, never recomputed (deleted bills leave no live rows). --}}
    @php $fls = ($existingReport && is_array($existingReport->local_summary)) ? ($existingReport->local_summary['provisional'] ?? null) : null; @endphp
    @if(is_array($fls) && (($fls['count'] ?? 0) > 0 || ($fls['finalized'] ?? 0) > 0 || ($fls['deleted'] ?? 0) > 0))
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.local_bills_closed_with_day') }}</h3>
        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <div class="flex items-center justify-between">
                <p class="text-xs font-bold uppercase text-gray-600 dark:text-gray-300">{{ __('pos.provisional_bills_l_series') }}</p>
                @php $flsAct = $fls['action'] ?? 'carry'; @endphp
                {{-- FBR has no archive: save/carry both mean the bills simply stayed Local. --}}
                <span class="text-[10px] px-2 py-0.5 rounded-full font-bold {{ $flsAct === 'delete' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : ($flsAct === 'finalize' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300') }}">{{ $flsAct === 'delete' ? __('pos.badge_deleted') : ($flsAct === 'finalize' ? __('pos.badge_finalized') : __('pos.badge_carried')) }}</span>
            </div>
            <p class="text-xl font-bold text-gray-900 dark:text-white mt-1">{{ $fls['count'] ?? 0 }} <span class="text-sm font-semibold text-gray-500">{{ __('pos.bills_word') }} — PKR {{ number_format($fls['amount'] ?? 0) }}</span></p>
            @if(($fls['backlog'] ?? 0) > 0)
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.n_older_dates_included', ['count' => $fls['backlog']]) }}</p>
            @endif
            {{-- Finalize sweep detail — same wording set as the close-time flash. --}}
            @if(($fls['finalized'] ?? 0) > 0)
            <p class="text-xs text-emerald-700 dark:text-emerald-300 font-semibold mt-1">{{ ltrim(__('pos.dayclose_bills_finalized', ['count' => $fls['finalized']]), ' —') }}@if(($fls['submitted'] ?? 0) > 0){{ __('pos.dayclose_bills_submitted', ['count' => $fls['submitted']]) }}@endif @if(($fls['queued'] ?? 0) > 0){{ __('pos.dayclose_bills_queued', ['count' => $fls['queued']]) }}@endif @if(($fls['failed'] ?? 0) > 0){{ __('pos.dayclose_bills_failed', ['count' => $fls['failed']]) }}@endif</p>
            @endif
            @if(($fls['deleted'] ?? 0) > 0)
            <p class="text-xs text-red-600 dark:text-red-400 font-semibold mt-1">{{ ltrim(__('pos.dayclose_bills_deleted', ['count' => $fls['deleted']]), ' —') }}</p>
            @endif
            @if(($fls['rider_guarded'] ?? 0) > 0)
            <p class="text-xs text-amber-700 dark:text-amber-400 font-semibold mt-1">{{ __('pos.dc_rider_guarded_kept', ['count' => $fls['rider_guarded']]) }}</p>
            @endif
            @if(is_array($fls['per_bill'] ?? null))
            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">{{ __('pos.dc_per_bill_split', ['save' => $fls['per_bill']['save'] ?? 0, 'delete' => $fls['per_bill']['delete'] ?? 0, 'carry' => $fls['per_bill']['carry'] ?? 0]) }}</p>
            @endif
        </div>
    </div>
    @endif

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
    {{-- ═══ Pending checklist (Task 676 — FBR mirror of PRA Task 661, ZFC):
         everything that must be settled BEFORE the day can close, in one
         glance. Undispatched deliveries hard-stop the close; pending local
         bills are handled by the wash policy; rider khata is a warning only.
         (No open-orders row — FBR holds are JSON carts, not restaurant orders.) ═══ --}}
    @php
        $pd = $pendingDeliveries ?? (object) ['active' => false, 'count' => 0, 'amount' => 0, 'assigned' => 0, 'unassigned' => 0, 'khata_count' => 0, 'khata_amount' => 0];
        $clProv = in_array($company->pos_dayclose_provisional_action ?? 'save', ['save','delete','carry','finalize'], true) ? ($company->pos_dayclose_provisional_action ?? 'save') : 'save';
        $clPendingLocal = $pendingLocalCount ?? 0;
        $clBlocked = $pd->count > 0;
    @endphp
    <div class="bg-white dark:bg-gray-900 rounded-xl border {{ $clBlocked ? 'border-red-300 dark:border-red-800' : 'border-gray-200 dark:border-gray-700' }} shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.dc_checklist_title') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.dc_checklist_hint') }}</p>
        <ul class="space-y-2">
            {{-- 1. Undispatched delivery bills — BLOCKER (ZFC waqia; delivery-feature shops only) --}}
            @if($fbrRidersRelevant && $pd->active)
            <li class="flex flex-wrap items-center gap-2 text-sm p-2.5 rounded-lg {{ $pd->count > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-emerald-50/60 dark:bg-emerald-900/10' }}">
                <span class="font-bold {{ $pd->count > 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ $pd->count > 0 ? '✗' : '✓' }}</span>
                <span class="font-semibold text-gray-900 dark:text-white">{{ __('pos.dc_check_undispatched') }}</span>
                @if($pd->count > 0)
                <span class="text-xs text-red-700 dark:text-red-300 font-semibold">{{ __('pos.dc_undispatched_detail', ['count' => $pd->count, 'amount' => number_format($pd->amount)]) }}</span>
                <span class="ml-auto flex items-center gap-2">
                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ __('pos.dc_check_blocks') }}</span>
                    <a href="{{ route('fbrpos.deliveries') }}" class="text-xs underline font-semibold text-red-700 dark:text-red-300">{{ __('pos.dc_open_deliveries_board') }}</a>
                </span>
                @else
                <span class="ml-auto text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ __('pos.dc_check_clear') }}</span>
                @endif
            </li>
            @endif
            {{-- 2. Pending local / provisional bills — handled by policy/override --}}
            <li class="flex flex-wrap items-center gap-2 text-sm p-2.5 rounded-lg {{ $clPendingLocal > 0 ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-emerald-50/60 dark:bg-emerald-900/10' }}">
                <span class="font-bold {{ $clPendingLocal > 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $clPendingLocal > 0 ? '!' : '✓' }}</span>
                <span class="font-semibold text-gray-900 dark:text-white">{{ __('pos.dc_check_pending_local') }}</span>
                @if($clPendingLocal > 0)
                <span class="text-xs text-amber-700 dark:text-amber-300 font-semibold">{{ $clPendingLocal }} {{ __('pos.bills_word') }} — PKR {{ number_format($pendingLocalAmount ?? 0) }}</span>
                <span class="ml-auto flex items-center gap-2">
                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold {{ $clProv === 'delete' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' : ($clProv === 'carry' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : ($clProv === 'finalize' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300')) }}">{{ $clProv === 'delete' ? __('pos.badge_delete') : ($clProv === 'carry' ? __('pos.badge_carry') : ($clProv === 'finalize' ? __('pos.badge_finalize') : __('pos.badge_archive'))) }}</span>
                    <a href="{{ route('fbrpos.transactions', ['tab' => 'local']) }}" class="text-xs underline font-semibold text-amber-700 dark:text-amber-300">{{ __('pos.view_btn') }}</a>
                </span>
                @if($clProv === 'carry')
                <span class="basis-full text-[11px] text-indigo-700 dark:text-indigo-300 font-semibold">{{ __('pos.dc_carry_pending_note') }}</span>
                @endif
                @else
                <span class="ml-auto text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ __('pos.dc_check_clear') }}</span>
                @endif
            </li>
            {{-- 3. Rider unsettled cash khata — WARNING ONLY, never blocks --}}
            @if($fbrRidersRelevant && $pd->active)
            <li class="flex flex-wrap items-center gap-2 text-sm p-2.5 rounded-lg {{ $pd->khata_count > 0 ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-emerald-50/60 dark:bg-emerald-900/10' }}">
                <span class="font-bold {{ $pd->khata_count > 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $pd->khata_count > 0 ? '!' : '✓' }}</span>
                <span class="font-semibold text-gray-900 dark:text-white">{{ __('pos.dc_check_rider_khata') }}</span>
                @if($pd->khata_count > 0)
                <span class="text-xs text-amber-700 dark:text-amber-300 font-semibold">{{ __('pos.dc_rider_khata_note', ['amount' => number_format($pd->khata_amount), 'count' => $pd->khata_count]) }}</span>
                <span class="ml-auto flex items-center gap-2">
                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ __('pos.dc_check_warn_only') }}</span>
                    <a href="{{ route('fbrpos.deliveries') }}" class="text-xs underline font-semibold text-amber-700 dark:text-amber-300">{{ __('pos.dc_open_deliveries_board') }}</a>
                </span>
                @else
                <span class="ml-auto text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ __('pos.dc_check_clear') }}</span>
                @endif
            </li>
            @endif
        </ul>
    </div>

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
                 @php $rf = $riderFigures ?? ['active' => false, 'cash_out' => 0, 'cash_in' => 0, 'riders' => []]; @endphp
                 x-data="{ float: '', counted: '', cashSales: {{ (float) $stats->cash_amount }},
                           riderOut: {{ (float) ($rf['cash_out'] ?? 0) }}, riderIn: {{ (float) ($rf['cash_in'] ?? 0) }},
                           get expected() { return (parseFloat(this.float) || 0) + this.cashSales - this.riderOut + this.riderIn; },
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
                    @if(!empty($rf['active']) && ($rf['cash_out'] ?? 0) > 0)
                    <span class="text-amber-700 dark:text-amber-400">{{ __('pos.with_rider_unsettled') }} <b>− PKR {{ number_format($rf['cash_out'], 2) }}</b></span>
                    @endif
                    @if(!empty($rf['active']) && ($rf['cash_in'] ?? 0) > 0)
                    <span class="text-emerald-700 dark:text-emerald-400">{{ __('pos.rider_settlements_old_bills') }} <b>+ PKR {{ number_format($rf['cash_in'], 2) }}</b></span>
                    @endif
                    <span class="text-gray-600 dark:text-gray-400">{{ __('pos.expected_in_drawer_label') }} <b class="text-gray-900 dark:text-white" x-text="'PKR ' + expected.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></b></span>
                    <template x-if="variance !== null">
                        <span class="font-bold" :class="Math.abs(variance) < 0.01 ? 'text-emerald-600' : (variance < 0 ? 'text-red-600' : 'text-amber-600')"
                              x-text="(Math.abs(variance) < 0.01 ? @js(__('pos.balanced_word')) : (variance < 0 ? @js(__('pos.short_prefix')) : @js(__('pos.over_prefix')))) + (Math.abs(variance) < 0.01 ? '' : variance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}))"></span>
                    </template>
                </div>
            </div>
            {{-- Per-close action override (Task 676 — FBR mirror of Task 661):
                 admin/manager only; applies to THIS close only — the standing
                 Customize policy stays untouched. Auto-close never uses it. --}}
            {{-- BILL-BY-BILL choice (Task 687 — FBR mirror of PRA Task 677): the
                 all-box (wash_override) covers every bill left on "default"; a
                 per-row pick beats the all-box for exactly that bill. FBR set =
                 provisionals only (no final_local / draft kinds). --}}
            @if(auth('fbrpos')->user() && !auth('fbrpos')->user()->isPosCashier() && ($pendingLocalCount ?? 0) > 0)
            @php $wbShow = $washBills ?? collect(); @endphp
            <div class="mb-4 p-3 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.wash_override_label') }}</label>
                <select name="wash_override" class="w-full sm:w-auto rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="standing" selected>{{ __('pos.wash_override_standing') }}</option>
                    <option value="finalize">{{ __('pos.wash_override_finalize') }}</option>
                    <option value="save">{{ __('pos.wash_override_save') }}</option>
                    <option value="delete">{{ __('pos.wash_override_delete') }}</option>
                </select>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.wash_override_hint') }}</p>

                @if($wbShow->isNotEmpty())
                <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                    <p class="text-xs font-bold text-gray-700 dark:text-gray-300">{{ __('pos.dc_bill_actions_title') }}</p>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-2">{{ __('pos.dc_bill_actions_hint') }}</p>
                    @if($wbShow->count() > 15)
                    {{-- Big-backlog helper: client-side filter only — every row stays
                         in the DOM/form, so hidden rows still submit their pick. --}}
                    <input type="text" inputmode="search" autocomplete="off" name="wb_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                           placeholder="{{ __('pos.dc_bill_search_ph') }}"
                           class="w-full sm:w-64 mb-2 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-xs focus:ring-blue-500 focus:border-blue-500"
                           oninput="var q=this.value.toLowerCase().trim();document.querySelectorAll('#dc-bill-rows tr').forEach(function(r){r.style.display=(!q||(r.getAttribute('data-wb')||'').indexOf(q)!==-1)?'':'none';});">
                    @endif
                    <div class="overflow-x-auto max-h-80 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                            <thead class="bg-gray-100 dark:bg-gray-900 sticky top-0">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('pos.th_invoice') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase hidden sm:table-cell">{{ __('pos.th_type') }}</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('pos.receipt_amount') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('pos.dc_bill_action_col') }}</th>
                                </tr>
                            </thead>
                            <tbody id="dc-bill-rows" class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
                                @foreach($wbShow as $wb)
                                <tr data-wb="{{ Str::lower($wb->invoice_number . ' ' . ($wb->customer_name ?? '')) }}">
                                    <td class="px-3 py-2">
                                        <span class="font-semibold text-gray-900 dark:text-white">{{ $wb->invoice_number }}</span>
                                        <span class="block text-[11px] text-gray-500">{{ \Carbon\Carbon::parse($wb->business_date)->format('d M') }}{{ $wb->customer_name ? ' — ' . $wb->customer_name : '' }}</span>
                                    </td>
                                    <td class="px-3 py-2 hidden sm:table-cell">
                                        <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300">{{ __('pos.dc_bill_kind_prov') }}</span>
                                        @if($wb->khata)
                                        <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300" title="{{ __('pos.dc_bill_khata_note') }}">{{ __('pos.dc_check_rider_khata') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right font-semibold text-gray-900 dark:text-white whitespace-nowrap">{{ number_format($wb->total_amount) }}</td>
                                    <td class="px-3 py-2">
                                        <select name="bill_actions[{{ $wb->id }}]" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-xs focus:ring-blue-500 focus:border-blue-500 py-1">
                                            <option value="standing" selected>{{ __('pos.dc_bill_action_default') }}</option>
                                            <option value="finalize">{{ __('pos.wash_override_finalize') }}</option>
                                            <option value="save">{{ __('pos.wash_override_save') }}</option>
                                            {{-- Khata bills: delete disabled — the wash keeps them Local regardless (rider guard). --}}
                                            <option value="delete" @if($wb->khata) disabled @endif>{{ __('pos.wash_override_delete') }}@if($wb->khata) — {{ __('pos.dc_bill_khata_short') }}@endif</option>
                                        </select>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif
            </div>
            @endif
            @if(($parkedBills ?? 0) > 0)
            {{-- Parked bills (owner, 23 Aug 2026): unfinished carts a counter set
                 aside to serve the next customer. They are NOT sales, so this is a
                 reminder only — never a blocker. Carts left parked from EARLIER
                 days are swept by the close itself. --}}
            <div class="mb-4 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700">
                <div class="text-sm">
                    <span class="font-bold text-amber-800 dark:text-amber-300">{{ __('pos.hs_day_close_parked') }}: {{ $parkedBills }}</span>
                    <p class="text-xs font-medium text-amber-800 dark:text-amber-300 mt-1">{{ __('pos.hs_day_close_parked_hint') }}</p>
                </div>
            </div>
            @endif
            @if(($pendingDeliveries->count ?? 0) > 0)
            {{-- Task 676 (ZFC): undispatched delivery bills HARD-BLOCK the close —
                 the day is not settled while delivery orders never left the shop.
                 Server (closeDayReport) enforces this as the authority. --}}
            <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border-2 border-red-400 dark:border-red-700">
                <div class="text-sm">
                    <span class="font-bold text-red-800 dark:text-red-300">{{ __('pos.dayclose_blocked_undispatched', ['count' => $pendingDeliveries->count]) }}</span>
                    <p class="text-xs font-semibold text-red-800 dark:text-red-300 mt-1">{{ __('pos.dc_undispatched_detail', ['count' => $pendingDeliveries->count, 'amount' => number_format($pendingDeliveries->amount)]) }}</p>
                    <p class="text-xs font-bold text-red-700 dark:text-red-400 mt-0.5">
                        <a href="{{ route('fbrpos.deliveries') }}" class="underline font-semibold">{{ __('pos.dc_open_deliveries_board') }}</a>
                    </p>
                </div>
            </div>
            <button type="button" disabled
                class="px-6 py-2.5 bg-gray-400 dark:bg-gray-600 text-white font-semibold rounded-lg cursor-not-allowed text-sm flex items-center gap-2" title="{{ __('pos.dayclose_blocked_hint') }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                {{ __('pos.close_day_generate_z') }}
            </button>
            @else
            <button type="submit" onclick="return confirm(@js(__('pos.confirm_close_day')))"
                class="px-6 py-2.5 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition text-sm flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                {{ __('pos.close_day_generate_z') }}
            </button>
            @endif
        </form>
    </div>
    @endif

    @else
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-10 text-center mb-6">
        <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        <p class="text-gray-500 dark:text-gray-400">{{ __('pos.no_transactions_for_date', ['date' => \Carbon\Carbon::parse($date)->format('d M Y')]) }}</p>
    </div>
    @endif

    {{-- Day cutoff selector + auto day-close checkbox (Task 676 — FBR mirror
         of PRA Task 661): admin/manager only; same shared company columns
         (pos_business_day_cutoff / pos_auto_dayclose_24h) via fbrpos-gated
         endpoints. The hourly fbrpos:auto-dayclose command reads the flag. --}}
    @if(auth('fbrpos')->user() && !auth('fbrpos')->user()->isPosCashier())
    @php
        $currentCutoff = \App\Services\PosBusinessDay::cutoffFor($company->id);
        $cutoffOptions = [];
        for ($h = 0; $h < 12; $h++) {
            foreach (['00', '30'] as $m) {
                $val = str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . $m;
                $cutoffOptions[$val] = \Carbon\Carbon::createFromFormat('H:i', $val)->format('g:i A');
            }
        }
        $unassignedDeliveryAction = \Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_dayclose_unassigned_delivery_action')
            && in_array($company->pos_dayclose_unassigned_delivery_action ?? 'allow', ['allow', 'block'], true)
            ? $company->pos_dayclose_unassigned_delivery_action
            : 'allow';
        $autoCloseTime = \Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_auto_dayclose_time')
            && is_string($company->pos_auto_dayclose_time ?? null)
            && preg_match('/^([01]\d):(00|30)$/', $company->pos_auto_dayclose_time)
            && $company->pos_auto_dayclose_time < '12:00'
            ? $company->pos_auto_dayclose_time
            : '06:00';
    @endphp
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6"
         x-data="{ cutoff: '{{ $currentCutoff }}', saving: false, msg: '', ok: true,
            autoOn: {{ ($company->pos_auto_dayclose_24h ?? false) ? 'true' : 'false' }}, autoMsg: '', autoOk: true,
            autoTime: @js($autoCloseTime), autoTimeSaved: @js($autoCloseTime), autoTimeBusy: false, autoTimeMsg: '', autoTimeOk: true,
            unassignedAction: @js($unassignedDeliveryAction), unassignedSaved: @js($unassignedDeliveryAction), unassignedBusy: false, unassignedMsg: '', unassignedOk: true,
            save() {
                this.saving = true; this.msg = '';
                fetch('{{ route('fbrpos.settings.dayclose-cutoff') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ cutoff: this.cutoff }) })
                    .then(r => r.json())
                    .then(d => { this.ok = !!(d && d.success); this.msg = (d && d.message) || (this.ok ? @js(__('pos.saved_dot')) : @js(__('pos.setting_save_failed'))); })
                    .catch(() => { this.ok = false; this.msg = @js(__('pos.setting_save_failed')); })
                    .finally(() => { this.saving = false; });
            },
            toggleAuto() {
                this.autoMsg = '';
                fetch('{{ route('fbrpos.settings.auto-dayclose-toggle') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ enabled: this.autoOn }) })
                    .then(r => r.json())
                    .then(d => { this.autoOk = !!(d && d.success); this.autoMsg = (d && d.message) || (this.autoOk ? @js(__('pos.saved_dot')) : @js(__('pos.setting_save_failed'))); if (!this.autoOk) { this.autoOn = !this.autoOn; } })
                    .catch(() => { this.autoOk = false; this.autoMsg = @js(__('pos.setting_save_failed')); this.autoOn = !this.autoOn; });
            },
            saveAutoTime() {
                const previous = this.autoTimeSaved;
                this.autoTimeBusy = true; this.autoTimeMsg = '';
                fetch('{{ route('fbrpos.settings.auto-dayclose-time') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ time: this.autoTime }) })
                    .then(r => r.json())
                    .then(d => {
                        this.autoTimeOk = !!(d && d.success);
                        if (this.autoTimeOk) { this.autoTimeSaved = d.time || this.autoTime; }
                        else { this.autoTime = previous; }
                        this.autoTimeMsg = (d && d.message) || (this.autoTimeOk ? @js(__('pos.saved_dot')) : @js(__('pos.setting_save_failed')));
                    })
                    .catch(() => { this.autoTimeOk = false; this.autoTime = previous; this.autoTimeMsg = @js(__('pos.setting_save_failed')); })
                    .finally(() => { this.autoTimeBusy = false; });
            },
            saveUnassigned() {
                const previous = this.unassignedSaved;
                this.unassignedBusy = true; this.unassignedMsg = '';
                fetch('{{ route('fbrpos.settings.unassigned-delivery-dayclose') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ action: this.unassignedAction }) })
                    .then(r => r.json())
                    .then(d => {
                        this.unassignedOk = !!(d && d.success);
                        if (this.unassignedOk) { this.unassignedSaved = d.action || this.unassignedAction; }
                        else { this.unassignedAction = previous; }
                        this.unassignedMsg = (d && d.message) || (this.unassignedOk ? @js(__('pos.saved_dot')) : @js(__('pos.setting_save_failed')));
                    })
                    .catch(() => { this.unassignedOk = false; this.unassignedAction = previous; this.unassignedMsg = @js(__('pos.setting_save_failed')); })
                    .finally(() => { this.unassignedBusy = false; });
            } }" id="dayclose-settings">
        {{-- Task 1403: the Customize hub deep-links here (#dayclose-settings) instead of
             cloning these two controls — one setting, one place, no drift. --}}
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('pos.day_cutoff_title') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-xl">
                    {!! __('pos.day_cutoff_hint', ['previous_day' => '<span class="font-semibold">' . e(__('pos.previous_day_word')) . '</span>']) !!}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <select x-model="cutoff" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                    @foreach($cutoffOptions as $val => $label)
                    <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
                <button type="button" @click="save()" :disabled="saving"
                    class="px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition disabled:opacity-50">
                    <span x-show="!saving">{{ __('pos.save_btn') }}</span><span x-show="saving" x-cloak>{{ __('pos.saving_ellipsis') }}</span>
                </button>
            </div>
        </div>
        <p x-show="msg" x-cloak class="text-xs mt-2" :class="ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'" x-text="msg"></p>
        <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 flex flex-wrap items-start gap-3">
            <input type="checkbox" id="dc-auto-close-chk" x-model="autoOn" @change="toggleAuto()"
                class="mt-0.5 w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500">
            <label for="dc-auto-close-chk" class="cursor-pointer flex-1 min-w-0">
                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.auto_dayclose_6am') }}</span>
                <span class="block text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.auto_dayclose_6am_sub') }}</span>
            </label>
            <div class="flex items-center gap-2 shrink-0">
                <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ __('pos.auto_dayclose_time_label') }}</span>
                <select x-model="autoTime" @change="saveAutoTime()" :disabled="autoTimeBusy"
                    class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500 disabled:opacity-50">
                    @foreach($cutoffOptions as $val => $label)
                    <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <span x-show="autoMsg" x-cloak class="text-xs font-semibold shrink-0" :class="autoOk ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'" x-text="autoMsg"></span>
            <span x-show="autoTimeMsg" x-cloak class="text-xs font-semibold shrink-0" :class="autoTimeOk ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'" x-text="autoTimeMsg"></span>
        </div>
        <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row sm:items-center gap-3">
            <div class="flex-1 min-w-0">
                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.unassigned_delivery_dayclose_title') }}</span>
                <span class="block text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.unassigned_delivery_dayclose_sub') }}</span>
            </div>
            <select x-model="unassignedAction" @change="saveUnassigned()" :disabled="unassignedBusy"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500 disabled:opacity-50">
                <option value="allow">{{ __('pos.unassigned_delivery_dayclose_allow') }}</option>
                <option value="block">{{ __('pos.unassigned_delivery_dayclose_block') }}</option>
            </select>
            <span x-show="unassignedMsg" x-cloak class="text-xs font-semibold shrink-0" :class="unassignedOk ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'" x-text="unassignedMsg"></span>
        </div>
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
                            <a href="{{ route('fbrpos.day-close-pdf', $rpt->id) }}" target="_blank" class="text-blue-600 hover:text-blue-800 font-medium">{{ __('pos.view_word') }} {{ __('pos.pdf_word') }}</a>
                            <span class="mx-1 text-gray-300">|</span>
                            <a href="{{ route('fbrpos.day-close-pdf-download', $rpt->id) }}" class="text-blue-600 hover:text-blue-800 font-medium">{{ __('pos.download_pdf') }}</a>
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
