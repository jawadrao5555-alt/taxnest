<x-pos-layout>
<div class="max-w-7xl mx-auto">
    @include('pos.partials.back-link')
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.day_close_report_z') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.pra_compliance_eod') }}</p>
        </div>
        <form method="GET" action="{{ route('pos.day-close') }}" class="flex items-center gap-2">
            <input type="date" name="date" value="{{ $date }}" max="{{ today()->format('Y-m-d') }}"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
            <button type="submit" class="px-4 py-2 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition">{{ __('pos.view_btn') }}</button>
        </form>
    </div>

    {{-- Stranded-day banner (Task 455): prior business day(s) never closed —
         auto-close skipped (open orders) or nobody closed manually. Surface
         them loudly before more bills pile onto today. --}}
    @if(($unclosedPriorDays ?? collect())->isNotEmpty())
    <div class="mb-6 p-4 rounded-xl bg-red-50 dark:bg-red-900/20 border-2 border-red-300 dark:border-red-800">
        <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/40 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="font-bold text-red-800 dark:text-red-300">{{ __('pos.dc_prior_open_title') }}</p>
                <p class="text-sm text-red-700 dark:text-red-400 mt-0.5">{{ __('pos.dc_prior_open_msg') }}</p>
                {{-- Task 516: bulk close — one click closes ALL stranded prior days
                     chronologically via the same performDayClose routine. --}}
                <form method="POST" action="{{ route('pos.close-all-days') }}" class="mt-3"
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
                    <a href="{{ route('pos.day-close', ['date' => $openDay]) }}"
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

    @if($existingReport)
    <div class="mb-6 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-emerald-100 dark:bg-emerald-900/40 flex items-center justify-center">
                    <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <p class="font-bold text-emerald-800 dark:text-emerald-300">{{ __('pos.day_closed_report', ['number' => $existingReport->report_number]) }}</p>
                    <p class="text-xs text-emerald-600 dark:text-emerald-400">{{ __('pos.closed_on', ['datetime' => $existingReport->created_at->format('d M Y h:i A')]) }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('pos.day-close-thermal', $existingReport->id) }}" target="_blank" class="px-4 py-2 bg-gray-800 text-white text-sm font-semibold rounded-lg hover:bg-gray-900 transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4H7v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    {{ __('pos.thermal_z_report') }}
                </a>
                <a href="{{ route('pos.day-close-pdf', $existingReport->id) }}" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition flex items-center gap-2">
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
    {{-- X-Report (Task 660, ZFC owner): "abhi tak ki report" WITHOUT closing
         the day — read-only, PROVISIONAL watermark; no wash, no hash, no row. --}}
    @if(!$existingReport && $stats->total_invoices > 0)
    <div class="mb-6 p-4 rounded-xl bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <div>
                <p class="font-bold text-sky-800 dark:text-sky-300">{{ __('pos.dc_xreport_title') }}</p>
                <p class="text-xs text-sky-600 dark:text-sky-400">{{ __('pos.dc_xreport_hint') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('pos.day-close-x-thermal', ['date' => $date]) }}" target="_blank" class="px-4 py-2 bg-gray-800 text-white text-sm font-semibold rounded-lg hover:bg-gray-900 transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4H7v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    {{ __('pos.dc_xreport_thermal') }}
                </a>
                <a href="{{ route('pos.day-close-x-pdf', ['date' => $date]) }}" class="px-4 py-2 bg-sky-600 text-white text-sm font-semibold rounded-lg hover:bg-sky-700 transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    {{ __('pos.dc_xreport_pdf') }}
                </a>
            </div>
        </div>
    </div>
    @endif

    @php
        // Day can also be closed when ONLY leftover local bills exist (backlog wash).
        $lbPending = ($localWash->prov_count ?? 0) + ($localWash->final_count ?? 0);
    @endphp
    @if($stats->total_invoices > 0 || $lbPending > 0)
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.kpi_total_invoices') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ $stats->total_invoices }}</p>
            <div class="flex flex-wrap gap-2 mt-2">
                <span class="text-xs px-1.5 py-0.5 rounded bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">{{ __('pos.badge_pra_count', ['count' => $stats->pra_invoices]) }}</span>
                <span class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">{{ __('pos.badge_local_count', ['count' => $stats->local_invoices]) }}</span>
                @if($stats->offline_invoices > 0)
                <span class="text-xs px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">{{ __('pos.badge_offline_count', ['count' => $stats->offline_invoices]) }}</span>
                @endif
            </div>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.kpi_gross_sales') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($stats->gross_sales) }}</p>
            @if($stats->total_discount > 0)
            <p class="text-xs text-red-500 mt-1">{{ __('pos.discount_minus_pkr', ['amount' => number_format($stats->total_discount)]) }}</p>
            @endif
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.kpi_total_tax') }}</p>
            <p class="text-2xl font-bold text-purple-600 mt-1">PKR {{ number_format($stats->total_tax) }}</p>
            {{-- PRA segregation (owner 9 Aug 2026): taxable vs exempt values --}}
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.dc_taxable_value') }}: PKR {{ number_format($stats->taxable_value) }}</p>
            @if($stats->exempt_value > 0)
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.dc_exempt_value') }}: PKR {{ number_format($stats->exempt_value) }}</p>
            @endif
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.kpi_net_revenue') }}</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1">PKR {{ number_format($stats->total_amount) }}</p>
            {{-- Returns / credit notes (Task 570): figures above are already netted --}}
            @if(($stats->returns_count ?? 0) > 0)
            <p class="text-xs text-rose-500 mt-1">{{ __('pos.dc_returns_netted', ['count' => $stats->returns_count, 'amount' => number_format($stats->returns_amount, 2)]) }}</p>
            @endif
            {{-- Wastage line (Task 593): spoiled-goods returns, shown separately --}}
            @if(($stats->wastage_count ?? 0) > 0)
            <p class="text-xs text-amber-600 dark:text-amber-400 mt-0.5">{{ __('pos.dc_wastage_line', ['count' => $stats->wastage_count, 'amount' => number_format($stats->wastage_amount, 2)]) }}</p>
            @endif
        </div>
    </div>
    {{-- ═══ PRA vs Local side-by-side + Exempt detail (Task 660, ZFC owner:
         total sab se oopar, phir PRA/Local alag-alag boxes har aik mein
         tax + payment breakdown, exempt items ki details bhi) ═══ --}}
    @php
        $ssPra = $streamSplit['pra'] ?? null;
        $ssLocal = $streamSplit['local'] ?? null;
        $ssExempt = $streamSplit['exempt'] ?? null;
        $ssExDetail = $streamSplit['exempt_detail'] ?? ['value' => 0, 'items' => []];
        $ssHasExempt = ($ssExempt['count'] ?? 0) > 0 || ($ssExDetail['value'] ?? 0) > 0 || !empty($ssExDetail['items']);
    @endphp
    @if(is_array($ssPra) && is_array($ssLocal))
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        @foreach([['key' => 'pra', 'box' => $ssPra, 'title' => __('pos.dc_stream_pra'), 'accent' => 'purple'], ['key' => 'local', 'box' => $ssLocal, 'title' => __('pos.dc_stream_local'), 'accent' => 'teal']] as $sbox)
        <div class="bg-white dark:bg-gray-900 rounded-xl border-2 {{ $sbox['accent'] === 'purple' ? 'border-purple-200 dark:border-purple-800' : 'border-teal-200 dark:border-teal-800' }} shadow-md p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold {{ $sbox['accent'] === 'purple' ? 'text-purple-700 dark:text-purple-300' : 'text-teal-700 dark:text-teal-300' }}">{{ $sbox['title'] }}</h3>
                <span class="text-xs px-2 py-0.5 rounded-full font-bold {{ $sbox['accent'] === 'purple' ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300' : 'bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300' }}">{{ $sbox['box']['count'] ?? 0 }} {{ __('pos.bills_word') }}</span>
            </div>
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.dc_stream_sale') }}</p>
                    <p class="text-xl font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($sbox['box']['sales'] ?? 0) }}</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.receipt_tax') }}</p>
                    <p class="text-xl font-bold {{ $sbox['accent'] === 'purple' ? 'text-purple-600' : 'text-teal-600' }} mt-0.5">PKR {{ number_format($sbox['box']['tax'] ?? 0) }}</p>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-2 text-center">
                <div class="p-2 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-[10px] text-gray-500 uppercase font-semibold">{{ __('pos.cash_title') }}</p>
                    <p class="text-sm font-bold text-gray-900 dark:text-white">{{ number_format($sbox['box']['cash'] ?? 0) }}</p>
                </div>
                <div class="p-2 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-[10px] text-gray-500 uppercase font-semibold">{{ __('pos.card_title') }}</p>
                    <p class="text-sm font-bold text-gray-900 dark:text-white">{{ number_format($sbox['box']['card'] ?? 0) }}</p>
                </div>
                <div class="p-2 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-[10px] text-gray-500 uppercase font-semibold">{{ __('pos.other_word') }}</p>
                    <p class="text-sm font-bold text-gray-900 dark:text-white">{{ number_format($sbox['box']['other'] ?? 0) }}</p>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    @if($ssHasExempt)
    <div class="bg-white dark:bg-gray-900 rounded-xl border-2 border-amber-200 dark:border-amber-800 shadow-md p-5 mb-6">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-bold text-amber-700 dark:text-amber-300">{{ __('pos.dc_stream_exempt') }}</h3>
            <span class="text-xs px-2 py-0.5 rounded-full font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">{{ $ssExempt['count'] ?? 0 }} {{ __('pos.bills_word') }}</span>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
            <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.dc_exempt_value') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($ssExDetail['value'] ?? 0) }}</p>
            </div>
            <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.dc_exempt_bills_sale') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($ssExempt['sales'] ?? 0) }}</p>
            </div>
            <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.cash_title') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format($ssExempt['cash'] ?? 0) }}</p>
            </div>
            <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.card_title') }} / {{ __('pos.other_word') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">PKR {{ number_format(($ssExempt['card'] ?? 0) + ($ssExempt['other'] ?? 0)) }}</p>
            </div>
        </div>
        @if(!empty($ssExDetail['items']))
        <p class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase mb-2">{{ __('pos.dc_exempt_items_sold') }}</p>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('pos.th_product') }}</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">{{ __('pos.receipt_qty') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('pos.receipt_amount') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($ssExDetail['items'] as $exItem)
                    <tr>
                        <td class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-white">{{ $exItem['name'] ?? '-' }}</td>
                        <td class="px-3 py-2 text-sm text-center text-gray-700 dark:text-gray-300">{{ rtrim(rtrim(number_format((float) ($exItem['qty'] ?? 0), 2), '0'), '.') }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($exItem['amount'] ?? 0) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @endif
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                {{ __('pos.payment_breakdown') }}
            </h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        </div>
                        <span class="font-medium text-gray-900 dark:text-white">{{ __('pos.cash_title') }}</span>
                    </div>
                    <span class="font-bold text-gray-900 dark:text-white">PKR {{ number_format($stats->cash_amount, 2) }}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        </div>
                        <span class="font-medium text-gray-900 dark:text-white">{{ __('pos.card_title') }}</span>
                    </div>
                    <span class="font-bold text-gray-900 dark:text-white">PKR {{ number_format($stats->card_amount, 2) }}</span>
                </div>
                @if($stats->other_amount > 0)
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                            <svg class="w-4 h-4 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                {{ __('pos.cashier_breakdown') }}
            </h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('pos.receipt_cashier') }}</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">{{ __('pos.th_sales') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('pos.kpi_revenue') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('pos.receipt_tax') }}</th>
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

            {{-- Cash reconciliation (stored on closed report) --}}
            @if($existingReport && ($existingReport->counted_cash !== null || $existingReport->opening_float !== null))
            @php $variance = (float) $existingReport->cash_variance; @endphp
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
                <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
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
                        <p class="font-bold text-gray-900 dark:text-white mt-0.5">{{ $existingReport->counted_cash !== null ? 'PKR ' . number_format($existingReport->counted_cash, 2) : __('pos.not_counted_dash') }}</p>
                    </div>
                </div>
                @if($existingReport->counted_cash !== null)
                <div class="mt-3 p-3 rounded-lg {{ abs($variance) < 0.01 ? 'bg-emerald-50 dark:bg-emerald-900/20' : ($variance < 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-amber-50 dark:bg-amber-900/20') }}">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-bold {{ abs($variance) < 0.01 ? 'text-emerald-700 dark:text-emerald-400' : ($variance < 0 ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400') }}">
                            {{ abs($variance) < 0.01 ? __('pos.balanced_no_variance') : ($variance < 0 ? __('pos.variance_short') : __('pos.variance_over')) }}
                        </p>
                        <p class="text-lg font-bold {{ abs($variance) < 0.01 ? 'text-emerald-700 dark:text-emerald-400' : ($variance < 0 ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400') }}">{{ $variance > 0 ? '+' : '' }}{{ number_format($variance, 2) }}</p>
                    </div>
                </div>
                @endif
            </div>
            @endif

    {{-- Local / provisional bills — comprehensive wash preview (owner request Jul 2026).
         Shows exactly what the day-close wash will touch, INCLUDING backlog bills
         left over from earlier un-closed dates. --}}
    @if(!$existingReport && $lbPending > 0)
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-teal-200 dark:border-teal-800 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.local_bills_will_close') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.local_bills_will_close_hint') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @if($localWash->prov_count > 0)
            <div class="p-3 bg-teal-50 dark:bg-teal-900/20 rounded-lg border border-teal-100 dark:border-teal-900/40">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase text-teal-700 dark:text-teal-300">{{ __('pos.provisional_bills_l_series') }}</p>
                    @php $provPolicy = in_array($company->pos_dayclose_provisional_action ?? 'save', ['save','delete','carry','finalize'], true) ? ($company->pos_dayclose_provisional_action ?? 'save') : 'save'; @endphp
                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold {{ $provPolicy === 'delete' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : ($provPolicy === 'carry' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : ($provPolicy === 'finalize' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300')) }}">{{ $provPolicy === 'delete' ? __('pos.badge_delete') : ($provPolicy === 'carry' ? __('pos.badge_carry') : ($provPolicy === 'finalize' ? __('pos.badge_finalize') : __('pos.badge_archive'))) }}</span>
                </div>
                <p class="text-xl font-bold text-gray-900 dark:text-white mt-1">{{ $localWash->prov_count }} <span class="text-sm font-semibold text-gray-500">{{ __('pos.bills_word') }} — PKR {{ number_format($localWash->prov_amount) }}</span></p>
                @if($localWash->prov_backlog > 0)
                <p class="text-xs text-amber-700 dark:text-amber-400 font-semibold mt-1">{{ __('pos.n_older_dates_pending', ['count' => $localWash->prov_backlog]) }}</p>
                @endif
                {{-- Aug 2026 (customer q: auto-close par Make Final bhool gaye to?) —
                     loud warning: after close these can no longer be finalized,
                     unless the policy is Carry Forward. --}}
                @if($provPolicy === 'carry')
                <p class="text-xs text-indigo-700 dark:text-indigo-300 font-semibold mt-1">{{ __('pos.prov_carry_note') }}</p>
                @elseif($provPolicy === 'finalize')
                <p class="text-xs text-emerald-700 dark:text-emerald-300 font-semibold mt-1">{{ __('pos.prov_finalize_note') }}</p>
                @else
                <p class="text-xs text-red-700 dark:text-red-400 font-bold mt-1">⚠ {{ __('pos.prov_pending_final_warning') }}</p>
                @endif
            </div>
            @endif
            @if($localWash->final_count > 0)
            <div class="p-3 bg-teal-50 dark:bg-teal-900/20 rounded-lg border border-teal-100 dark:border-teal-900/40">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase text-teal-700 dark:text-teal-300">{{ __('pos.final_bills_reporting_off') }}</p>
                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold {{ ($company->pos_dayclose_final_local_action ?? 'save') === 'delete' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300' }}">{{ ($company->pos_dayclose_final_local_action ?? 'save') === 'delete' ? __('pos.badge_delete') : __('pos.badge_archive') }}</span>
                </div>
                <p class="text-xl font-bold text-gray-900 dark:text-white mt-1">{{ $localWash->final_count }} <span class="text-sm font-semibold text-gray-500">{{ __('pos.bills_word') }} — PKR {{ number_format($localWash->final_amount) }}</span></p>
                @if($localWash->final_backlog > 0)
                <p class="text-xs text-amber-700 dark:text-amber-400 font-semibold mt-1">{{ __('pos.n_older_dates_pending', ['count' => $localWash->final_backlog]) }}</p>
                @endif
            </div>
            @endif
        </div>
    </div>
    @endif

    @if(!$existingReport)
    {{-- ═══ Pending checklist (Task 661, ZFC): everything that must be settled
         BEFORE the day can close, in one glance. Blockers (open orders,
         undispatched deliveries) hard-stop the close; pending local bills are
         handled by the wash policy; rider khata is a warning only. ═══ --}}
    @php
        $pd = $pendingDeliveries ?? (object) ['active' => false, 'count' => 0, 'amount' => 0, 'assigned' => 0, 'unassigned' => 0, 'khata_count' => 0, 'khata_amount' => 0];
        $clProv = in_array($company->pos_dayclose_provisional_action ?? 'save', ['save','delete','carry','finalize'], true) ? ($company->pos_dayclose_provisional_action ?? 'save') : 'save';
        $clPendingLocal = ($localWash->prov_count ?? 0) + ($localWash->final_count ?? 0);
        $clRows = [
            ['count' => $openOrders ?? 0, 'block' => true],
            ['count' => $pd->count, 'block' => true],
            ['count' => $clPendingLocal, 'block' => false],
            ['count' => $pd->khata_count, 'block' => false],
        ];
        $clBlocked = ($openOrders ?? 0) > 0 || $pd->count > 0;
    @endphp
    <div class="bg-white dark:bg-gray-900 rounded-xl border {{ $clBlocked ? 'border-red-300 dark:border-red-800' : 'border-gray-200 dark:border-gray-700' }} shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.dc_checklist_title') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.dc_checklist_hint') }}</p>
        <ul class="space-y-2">
            {{-- 1. Open restaurant orders — BLOCKER (restaurant-mode shops only) --}}
            @if(($company->restaurant_mode ?? false) || ($openOrders ?? 0) > 0)
            <li class="flex flex-wrap items-center gap-2 text-sm p-2.5 rounded-lg {{ ($openOrders ?? 0) > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-emerald-50/60 dark:bg-emerald-900/10' }}">
                <span class="font-bold {{ ($openOrders ?? 0) > 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ ($openOrders ?? 0) > 0 ? '✗' : '✓' }}</span>
                <span class="font-semibold text-gray-900 dark:text-white">{{ __('pos.dc_check_open_orders') }}</span>
                @if(($openOrders ?? 0) > 0)
                <span class="text-red-700 dark:text-red-300 font-bold">{{ $openOrders }}</span>
                @if(!empty($openHeld->tableNumbers ?? ''))<span class="text-xs text-red-700 dark:text-red-300">{{ __('pos.dc_open_tables_list', ['tables' => $openHeld->tableNumbers]) }}</span>@endif
                <span class="ml-auto flex items-center gap-2">
                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ __('pos.dc_check_blocks') }}</span>
                    <a href="{{ route('pos.invoice.create') }}" class="text-xs underline font-semibold text-red-700 dark:text-red-300">{{ __('pos.dc_open_table_board') }}</a>
                </span>
                @else
                <span class="ml-auto text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ __('pos.dc_check_clear') }}</span>
                @endif
            </li>
            @endif
            {{-- 2. Undispatched delivery bills — BLOCKER (ZFC waqia; delivery-feature shops only) --}}
            @if($pd->active)
            <li class="flex flex-wrap items-center gap-2 text-sm p-2.5 rounded-lg {{ $pd->count > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-emerald-50/60 dark:bg-emerald-900/10' }}">
                <span class="font-bold {{ $pd->count > 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ $pd->count > 0 ? '✗' : '✓' }}</span>
                <span class="font-semibold text-gray-900 dark:text-white">{{ __('pos.dc_check_undispatched') }}</span>
                @if($pd->count > 0)
                <span class="text-xs text-red-700 dark:text-red-300 font-semibold">{{ __('pos.dc_undispatched_detail', ['count' => $pd->count, 'amount' => number_format($pd->amount)]) }}</span>
                <span class="ml-auto flex items-center gap-2">
                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ __('pos.dc_check_blocks') }}</span>
                    <a href="{{ route('pos.deliveries') }}" class="text-xs underline font-semibold text-red-700 dark:text-red-300">{{ __('pos.dc_open_deliveries_board') }}</a>
                </span>
                @else
                <span class="ml-auto text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ __('pos.dc_check_clear') }}</span>
                @endif
            </li>
            @endif
            {{-- 3. Pending local / provisional bills — handled by policy/override --}}
            <li class="flex flex-wrap items-center gap-2 text-sm p-2.5 rounded-lg {{ $clPendingLocal > 0 ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-emerald-50/60 dark:bg-emerald-900/10' }}">
                <span class="font-bold {{ $clPendingLocal > 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $clPendingLocal > 0 ? '!' : '✓' }}</span>
                <span class="font-semibold text-gray-900 dark:text-white">{{ __('pos.dc_check_pending_local') }}</span>
                @if($clPendingLocal > 0)
                <span class="text-xs text-amber-700 dark:text-amber-300 font-semibold">{{ $clPendingLocal }} {{ __('pos.bills_word') }} — PKR {{ number_format(($localWash->prov_amount ?? 0) + ($localWash->final_amount ?? 0)) }}</span>
                <span class="ml-auto flex items-center gap-2">
                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold {{ $clProv === 'delete' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' : ($clProv === 'carry' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : ($clProv === 'finalize' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300')) }}">{{ $clProv === 'delete' ? __('pos.badge_delete') : ($clProv === 'carry' ? __('pos.badge_carry') : ($clProv === 'finalize' ? __('pos.badge_finalize') : __('pos.badge_archive'))) }}</span>
                    <a href="{{ route('pos.local.index') }}" class="text-xs underline font-semibold text-amber-700 dark:text-amber-300">{{ __('pos.view_btn') }}</a>
                </span>
                @if($clProv === 'carry')
                <span class="basis-full text-[11px] text-indigo-700 dark:text-indigo-300 font-semibold">{{ __('pos.dc_carry_pending_note') }}</span>
                @endif
                @else
                <span class="ml-auto text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ __('pos.dc_check_clear') }}</span>
                @endif
            </li>
            {{-- 4. Rider unsettled cash khata — WARNING ONLY, never blocks --}}
            @if($pd->active)
            <li class="flex flex-wrap items-center gap-2 text-sm p-2.5 rounded-lg {{ $pd->khata_count > 0 ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-emerald-50/60 dark:bg-emerald-900/10' }}">
                <span class="font-bold {{ $pd->khata_count > 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $pd->khata_count > 0 ? '!' : '✓' }}</span>
                <span class="font-semibold text-gray-900 dark:text-white">{{ __('pos.dc_check_rider_khata') }}</span>
                @if($pd->khata_count > 0)
                <span class="text-xs text-amber-700 dark:text-amber-300 font-semibold">{{ __('pos.dc_rider_khata_note', ['amount' => number_format($pd->khata_amount), 'count' => $pd->khata_count]) }}</span>
                <span class="ml-auto flex items-center gap-2">
                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ __('pos.dc_check_warn_only') }}</span>
                    <a href="{{ route('pos.deliveries') }}" class="text-xs underline font-semibold text-amber-700 dark:text-amber-300">{{ __('pos.dc_open_deliveries_board') }}</a>
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
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.close_day_hint', ['date' => \Carbon\Carbon::parse($date)->format('d M Y')]) }}</p>
        <form method="POST" action="{{ route('pos.close-day') }}">
            @csrf
            <input type="hidden" name="date" value="{{ $date }}">
            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.lbl_notes_optional') }}</label>
                <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="{{ __('pos.ph_day_notes') }}"></textarea>
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
                <p class="text-sm font-bold text-gray-900 dark:text-white mb-1">{{ __('pos.cash_reconciliation_optional') }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.cash_reconciliation_hint') }}</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.lbl_opening_float') }}</label>
                        <input type="number" name="opening_float" x-model="float" step="0.01" min="0" placeholder="0.00"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                        @if($openingFromDayStart !== null)
                        <p class="text-[11px] text-teal-700 dark:text-teal-400 font-semibold mt-1">{{ __('pos.opening_cash_autofilled', ['amount' => number_format($openingFromDayStart, 2)]) }}</p>
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.lbl_counted_cash') }}</label>
                        <input type="number" name="counted_cash" x-model="counted" step="0.01" min="0" placeholder="0.00"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs">
                    <span class="text-gray-600 dark:text-gray-400">{{ __('pos.cash_sales_today') }} <b class="text-gray-900 dark:text-white">PKR {{ number_format($stats->cash_amount, 2) }}</b></span>
                    @if(!empty($rf['active']) && ($rf['cash_out'] ?? 0) > 0)
                    <span class="text-amber-700 dark:text-amber-400">{{ __('pos.with_rider_unsettled') }} <b>− PKR {{ number_format($rf['cash_out'], 2) }}</b></span>
                    @endif
                    @if(!empty($rf['active']) && ($rf['cash_in'] ?? 0) > 0)
                    <span class="text-emerald-700 dark:text-emerald-400">{{ __('pos.rider_settlements_old_bills') }} <b>+ PKR {{ number_format($rf['cash_in'], 2) }}</b></span>
                    @endif
                    <span class="text-gray-600 dark:text-gray-400">{{ __('pos.expected_in_drawer_colon') }} <b class="text-gray-900 dark:text-white" x-text="'PKR ' + expected.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></b></span>
                    <template x-if="variance !== null">
                        <span class="font-bold" :class="Math.abs(variance) < 0.01 ? 'text-emerald-600' : (variance < 0 ? 'text-red-600' : 'text-amber-600')"
                              x-text="(Math.abs(variance) < 0.01 ? @js(__('pos.balanced_word')) : (variance < 0 ? @js(__('pos.short_colon')) : @js(__('pos.over_plus')))) + (Math.abs(variance) < 0.01 ? '' : variance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}))"></span>
                    </template>
                </div>
            </div>
            @php
                $lbFinal = in_array($company->pos_dayclose_final_local_action ?? 'save', ['save','delete'], true) ? ($company->pos_dayclose_final_local_action ?? 'save') : 'save';
                $lbProv  = in_array($company->pos_dayclose_provisional_action ?? 'save', ['save','delete','carry','finalize'], true) ? ($company->pos_dayclose_provisional_action ?? 'save') : 'save';
            @endphp
            <div class="mb-4 p-3 rounded-lg bg-teal-50 dark:bg-teal-900/20 border border-teal-200 dark:border-teal-800">
                <div class="text-sm">
                    <span class="font-bold text-teal-800 dark:text-teal-300">{{ __('pos.local_bills_policy') }}</span>
                    <p class="text-xs text-teal-700 dark:text-teal-400 mt-0.5">
                        {!! __('pos.local_bills_policy_line', ['final' => '<b>' . ($lbFinal === 'delete' ? e(__('pos.badge_delete')) : e(__('pos.badge_archive_save'))) . '</b>', 'prov' => '<b>' . ($lbProv === 'delete' ? e(__('pos.badge_delete')) : ($lbProv === 'carry' ? e(__('pos.badge_carry')) : ($lbProv === 'finalize' ? e(__('pos.badge_finalize')) : e(__('pos.badge_archive_save'))))) . '</b>']) !!}
                        {{ __('pos.pra_bills_untouched_policy') }}
                        <a href="{{ route('pos.customize') }}" class="underline font-semibold">{{ __('pos.customize_pos_local_billing') }}</a>.
                    </p>
                </div>
            </div>
            {{-- Per-close action override (Task 661, owner's 3-option choice):
                 admin/manager only; applies to THIS close only — the standing
                 Customize policy stays untouched. Auto-close never uses it. --}}
            @if(auth('pos')->user() && !auth('pos')->user()->isPosCashier() && (($localWash->prov_count ?? 0) + ($localWash->final_count ?? 0)) > 0)
            <div class="mb-4 p-3 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.wash_override_label') }}</label>
                <select name="wash_override" class="w-full sm:w-auto rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                    <option value="standing" selected>{{ __('pos.wash_override_standing') }}</option>
                    <option value="finalize">{{ __('pos.wash_override_finalize') }}</option>
                    <option value="save">{{ __('pos.wash_override_save') }}</option>
                    <option value="delete">{{ __('pos.wash_override_delete') }}</option>
                </select>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.wash_override_hint') }}</p>
            </div>
            @endif
            @if(($localWash->prov_count ?? 0) > 0 && $lbProv !== 'carry' && $lbProv !== 'finalize')
            {{-- Aug 2026: pending provisionals warning at the CLOSE button too. --}}
            <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border-2 border-red-400 dark:border-red-700">
                <span class="text-sm font-bold text-red-800 dark:text-red-300">⚠ {{ __('pos.prov_pending_close_warning', ['count' => $localWash->prov_count]) }}</span>
            </div>
            @endif
            @if(($openOrders ?? 0) > 0)
            {{-- Owner rule 10 Aug 2026: open orders HARD-BLOCK day close — no
                 "close anyway" confirm. Finalize or cancel them first (they can
                 never be finalized after close). Server enforces this too. --}}
            <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border-2 border-red-400 dark:border-red-700">
                <div class="text-sm">
                    <span class="font-bold text-red-800 dark:text-red-300">{{ __('pos.open_orders_warning', ['count' => $openOrders]) }}{{ ($occupiedTables ?? 0) > 0 ? ' — ' . __('pos.n_tables_occupied', ['count' => $occupiedTables]) : '' }}</span>
                    @if(!empty($openHeld->tableNumbers ?? ''))
                    {{-- ZFC 3 Aug 2026: WHICH tables and HOW MUCH — 5 tables sat
                         occupied for 2 days because nobody could see the detail. --}}
                    <p class="text-xs font-semibold text-red-800 dark:text-red-300 mt-1">
                        {{ __('pos.dc_open_tables_list', ['tables' => $openHeld->tableNumbers]) }} — {{ __('pos.dc_open_orders_amount', ['amount' => number_format($openHeld->amount ?? 0)]) }}@if(($openHeld->noTableCount ?? 0) > 0) ({{ __('pos.dc_open_no_table', ['count' => $openHeld->noTableCount]) }})@endif
                    </p>
                    @elseif(($openHeld->amount ?? 0) > 0)
                    <p class="text-xs font-semibold text-red-800 dark:text-red-300 mt-1">{{ __('pos.dc_open_orders_amount', ['amount' => number_format($openHeld->amount)]) }}</p>
                    @endif
                    <p class="text-xs font-bold text-red-700 dark:text-red-400 mt-0.5">
                        {{ __('pos.dayclose_blocked_hint') }}
                        <a href="{{ route('pos.invoice.create') }}" class="underline font-semibold">{{ __('pos.dc_open_table_board') }}</a>
                    </p>
                </div>
            </div>
            @endif
            @if(($pendingDeliveries->count ?? 0) > 0)
            {{-- Task 661 (ZFC): undispatched delivery bills HARD-BLOCK too — the
                 day is not settled while delivery orders never left the shop.
                 Server (closeDayReport) enforces this as the authority. --}}
            <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border-2 border-red-400 dark:border-red-700">
                <div class="text-sm">
                    <span class="font-bold text-red-800 dark:text-red-300">{{ __('pos.dayclose_blocked_undispatched', ['count' => $pendingDeliveries->count]) }}</span>
                    <p class="text-xs font-semibold text-red-800 dark:text-red-300 mt-1">{{ __('pos.dc_undispatched_detail', ['count' => $pendingDeliveries->count, 'amount' => number_format($pendingDeliveries->amount)]) }}</p>
                    <p class="text-xs font-bold text-red-700 dark:text-red-400 mt-0.5">
                        <a href="{{ route('pos.deliveries') }}" class="underline font-semibold">{{ __('pos.dc_open_deliveries_board') }}</a>
                    </p>
                </div>
            </div>
            @endif
            @if(($openOrders ?? 0) > 0 || ($pendingDeliveries->count ?? 0) > 0)
            <button type="button" disabled
                class="px-6 py-2.5 bg-gray-400 dark:bg-gray-600 text-white font-semibold rounded-lg cursor-not-allowed text-sm flex items-center gap-2" title="{{ __('pos.dayclose_blocked_hint') }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                {{ __('pos.close_day_generate_z') }}
            </button>
            @else
            <button type="submit" onclick="return confirm({{ Js::from(__('pos.confirm_close_day')) }})"
                class="px-6 py-2.5 bg-purple-600 text-white font-semibold rounded-lg hover:bg-purple-700 transition text-sm flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                {{ __('pos.close_day_generate_z') }}
            </button>
            @endif
        </form>
    </div>
    @endif

    {{-- ═══ Long details moved BELOW the close action (Task 660, ZFC owner: item sales / top customers / attendance report ke AAKHIR mein) ═══ --}}
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
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.kpi_average_bill') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($analytics->avg_bill) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.kpi_unique_customers') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ $analytics->unique_customers }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ __('pos.named_customers_only') }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.kpi_vs_yesterday') }}</p>
            <div class="flex items-baseline gap-2 mt-1">
                <p class="text-lg font-bold text-gray-900 dark:text-white">PKR {{ number_format($cmp->yesterday->revenue) }}</p>
                {!! $pctBadge($cmp->vs_yesterday_revenue_pct) !!}
            </div>
            <p class="text-xs text-gray-500 mt-1">{{ __('pos.n_bills', ['count' => $cmp->yesterday->invoices]) }} {!! $pctBadge($cmp->vs_yesterday_invoices_pct) !!}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('pos.kpi_vs_last_weekday', ['day' => \Carbon\Carbon::parse($date)->format('l')]) }}</p>
            <div class="flex items-baseline gap-2 mt-1">
                <p class="text-lg font-bold text-gray-900 dark:text-white">PKR {{ number_format($cmp->last_week->revenue) }}</p>
                {!! $pctBadge($cmp->vs_last_week_revenue_pct) !!}
            </div>
            <p class="text-xs text-gray-500 mt-1">{{ __('pos.n_bills', ['count' => $cmp->last_week->invoices]) }} {!! $pctBadge($cmp->vs_last_week_invoices_pct) !!}</p>
        </div>
    </div>

    {{-- PRA submission health --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            {{ __('pos.pra_submission_health') }}
        </h3>
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center">
                <p class="text-2xl font-bold text-emerald-600">{{ $analytics->pra_health->submitted }}</p>
                <p class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase mt-0.5">{{ __('pos.status_submitted') }}</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 text-center">
                <p class="text-2xl font-bold text-gray-700 dark:text-gray-300">{{ $analytics->pra_health->pending }}</p>
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase mt-0.5">{{ __('pos.status_pending') }}</p>
            </div>
            <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-center">
                <p class="text-2xl font-bold text-amber-600">{{ $analytics->pra_health->offline }}</p>
                <p class="text-xs font-semibold text-amber-700 dark:text-amber-400 uppercase mt-0.5">{{ __('pos.status_offline_queue') }}</p>
            </div>
            <div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-center">
                <p class="text-2xl font-bold text-red-600">{{ $analytics->pra_health->failed }}</p>
                <p class="text-xs font-semibold text-red-700 dark:text-red-400 uppercase mt-0.5">{{ __('pos.failed_word') }}</p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 text-center">
                <p class="text-2xl font-bold text-gray-700 dark:text-gray-300">{{ $analytics->pra_health->not_reported }}</p>
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase mt-0.5">{{ __('pos.status_not_reported') }}</p>
            </div>
        </div>
        @if($analytics->pra_health->offline > 0 || $analytics->pra_health->failed > 0)
        <p class="text-xs text-amber-700 dark:text-amber-400 font-semibold mt-3">{{ __('pos.some_bills_not_reached_pra') }}</p>
        @endif
    </div>

    {{-- Category-wise + Top products --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                {{ __('pos.category_wise_sales') }}
            </h3>
            @if($analytics->categories->isEmpty())
            <p class="text-sm text-gray-500">{{ __('pos.no_item_data_for_day') }}</p>
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
                {{ __('pos.top_products_of_day') }}
            </h3>
            @if($analytics->top_products->isEmpty())
            <p class="text-sm text-gray-500">{{ __('pos.no_item_data_for_day') }}</p>
            @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('pos.th_product') }}</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">{{ __('pos.receipt_qty') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('pos.kpi_revenue') }}</th>
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
            {{ __('pos.hourly_sales') }}
        </h3>
        @if($activeHours->isEmpty())
        <p class="text-sm text-gray-500 mt-3">{{ __('pos.no_sales_recorded_yet') }}</p>
        @else
        <p class="text-xs text-gray-500 mb-4">{{ __('pos.peak_hour_label') }} <b class="text-gray-700 dark:text-gray-300">{{ \Carbon\Carbon::createFromTime($activeHours->sortByDesc('revenue')->keys()->first())->format('g A') }}</b> — PKR {{ number_format($activeHours->max('revenue')) }}</p>
        <div class="flex items-end gap-1 h-32">
            @foreach($analytics->hourly as $hour => $h)
            <div class="flex-1 flex flex-col items-center justify-end h-full" title="{{ \Carbon\Carbon::createFromTime($hour)->format('g A') }} — {{ __('pos.n_bills', ['count' => $h->count]) }}, PKR {{ number_format($h->revenue) }}">
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
                {{ __('pos.discount_summary') }}
            </h3>
            @if($analytics->discounts->total <= 0)
            <p class="text-sm text-gray-500">{{ __('pos.no_discounts_this_day') }}</p>
            @else
            <div class="grid grid-cols-2 gap-3">
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.bills_with_discount') }}</p>
                    <p class="text-xl font-bold text-gray-900 dark:text-white mt-0.5">{{ $analytics->discounts->bill_count }}</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <p class="text-xs text-gray-500 uppercase font-medium">{{ __('pos.kpi_total_discount') }}</p>
                    <p class="text-xl font-bold text-red-500 mt-0.5">PKR {{ number_format($analytics->discounts->total) }}</p>
                    @if($analytics->discounts->item_total > 0)
                    <p class="text-[10px] text-gray-500 mt-0.5">{{ __('pos.bill_item_discount_split', ['bill' => number_format($analytics->discounts->bill_total), 'item' => number_format($analytics->discounts->item_total)]) }}</p>
                    @endif
                </div>
            </div>
            @endif

            @if($analytics->restaurant_enabled && $analytics->deals->isNotEmpty())
            <h4 class="font-semibold text-gray-900 dark:text-white text-sm mt-5 mb-2">{{ __('pos.deals_performance') }}</h4>
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
                    {{ __('pos.order_type_split') }}
                </h3>
                <div class="grid grid-cols-2 gap-3">
                    @foreach($analytics->order_types as $type => $ot)
                    <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <p class="text-xs text-gray-500 uppercase font-medium">{{ ['dine_in' => __('pos.dine_in'), 'takeaway' => __('pos.takeaway'), 'delivery' => __('pos.delivery'), 'counter' => __('pos.counter_word')][$type] ?? ucfirst(str_replace('_', ' ', $type)) }}</p>
                        <p class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">{{ $ot->count }} <span class="text-xs font-semibold text-gray-500">{{ __('pos.bills_word') }}</span></p>
                        <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">PKR {{ number_format($ot->revenue) }}</p>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

        </div>
    </div>

    @else
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-10 text-center mb-6">
        <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        <p class="text-gray-500 dark:text-gray-400">{{ __('pos.no_transactions_for_date', ['date' => \Carbon\Carbon::parse($date)->format('d M Y')]) }}</p>
    </div>
    @endif

    {{-- After close: what the wash actually did (stored on the report). OUTSIDE the
         sales gate above — a day closed with ONLY backlog local bills has zero PRA
         sales, yet its wash summary must still show. --}}
    {{-- ZFC 3 Aug 2026: open held orders stamped AT close time — the user-less
         AUTO close has no live warning, so the Z-report carries the record.
         Rendered from the stored snapshot, not live data (a table settled after
         the close should not erase what the close saw). --}}
    @php $oatc = ($existingReport && is_array($existingReport->local_summary)) ? ($existingReport->local_summary['open_orders_at_close'] ?? null) : null; @endphp
    @if(is_array($oatc) && ($oatc['orders'] ?? 0) > 0)
    <div class="mb-6 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border-2 border-amber-400 dark:border-amber-700">
        <span class="text-sm font-bold text-amber-800 dark:text-amber-300">{{ __('pos.dc_closed_with_open_orders', ['count' => $oatc['orders'], 'amount' => number_format($oatc['amount'] ?? 0)]) }}</span>
        @if(!empty($oatc['table_numbers']))
        <p class="text-xs font-semibold text-amber-800 dark:text-amber-300 mt-1">{{ __('pos.dc_open_tables_list', ['tables' => $oatc['table_numbers']]) }}</p>
        @endif
        <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">{{ __('pos.dc_closed_open_orders_hint') }} <a href="{{ route('pos.invoice.create') }}" class="underline font-semibold">{{ __('pos.dc_open_table_board') }}</a></p>
    </div>
    @endif

    @if($existingReport && is_array($existingReport->local_summary) && (collect($existingReport->local_summary)->sum('count') > 0 || collect($existingReport->local_summary)->sum('finalized') > 0))
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.local_bills_closed_with_day') }}</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @foreach(['provisional' => __('pos.provisional_bills_l_series'), 'final_local' => __('pos.final_bills_reporting_off')] as $kind => $label)
                @php $ls = $existingReport->local_summary[$kind] ?? null; @endphp
                @if($ls && (($ls['count'] ?? 0) > 0 || ($ls['finalized'] ?? 0) > 0))
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-bold uppercase text-gray-600 dark:text-gray-300">{{ $label }}</p>
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-bold {{ ($ls['action'] ?? 'save') === 'delete' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : (($ls['action'] ?? 'save') === 'carry' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : (($ls['action'] ?? 'save') === 'finalize' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300')) }}">{{ ($ls['action'] ?? 'save') === 'delete' ? __('pos.badge_deleted') : (($ls['action'] ?? 'save') === 'carry' ? __('pos.badge_carried') : (($ls['action'] ?? 'save') === 'finalize' ? __('pos.badge_finalized') : __('pos.badge_archived'))) }}</span>
                    </div>
                    <p class="text-xl font-bold text-gray-900 dark:text-white mt-1">{{ $ls['count'] }} <span class="text-sm font-semibold text-gray-500">{{ __('pos.bills_word') }} — PKR {{ number_format($ls['amount'] ?? 0) }}</span></p>
                    @if(($ls['backlog'] ?? 0) > 0)
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.n_older_dates_included', ['count' => $ls['backlog']]) }}</p>
                    @endif
                    @if(($ls['action'] ?? 'save') === 'finalize')
                    {{-- Auto-finalize detail (Aug 2026): what the sweep actually did. --}}
                    <p class="text-xs text-emerald-700 dark:text-emerald-300 font-semibold mt-1">{{ __('pos.wash_finalized_detail', ['count' => $ls['finalized'] ?? 0, 'amount' => number_format($ls['finalized_amount'] ?? 0), 'submitted' => $ls['submitted'] ?? 0, 'queued' => $ls['queued'] ?? 0, 'offline' => $ls['offline'] ?? 0, 'left' => $ls['count'] ?? 0]) }}</p>
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
        <h3 class="font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.delivery_riders_day_summary') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
            {{ __('pos.rider_cash_summary_hint') }}
        </p>
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
            autoOn: {{ ($company->pos_auto_dayclose_24h ?? false) ? 'true' : 'false' }}, autoMsg: '', autoOk: true,
            save() {
                this.saving = true; this.msg = '';
                fetch('{{ route('pos.settings.dayclose-cutoff') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ cutoff: this.cutoff }) })
                    .then(r => r.json())
                    .then(d => { this.ok = !!(d && d.success); this.msg = (d && d.message) || (this.ok ? @js(__('pos.saved_dot')) : @js(__('pos.setting_save_failed'))); })
                    .catch(() => { this.ok = false; this.msg = @js(__('pos.setting_save_failed')); })
                    .finally(() => { this.saving = false; });
            },
            toggleAuto() {
                this.autoMsg = '';
                fetch('{{ route('pos.settings.auto-dayclose-toggle') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ enabled: this.autoOn }) })
                    .then(r => r.json())
                    .then(d => { this.autoOk = !!(d && d.success); this.autoMsg = (d && d.message) || (this.autoOk ? @js(__('pos.saved_dot')) : @js(__('pos.setting_save_failed'))); if (!this.autoOk) { this.autoOn = !this.autoOn; } })
                    .catch(() => { this.autoOk = false; this.autoMsg = @js(__('pos.setting_save_failed')); this.autoOn = !this.autoOn; });
            } }">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('pos.day_cutoff_title') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-xl">
                    {!! __('pos.day_cutoff_hint', ['previous_day' => '<span class="font-semibold">' . e(__('pos.previous_day_word')) . '</span>']) !!}
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
                    <span x-show="!saving">{{ __('pos.save_btn') }}</span><span x-show="saving" x-cloak>{{ __('pos.saving_ellipsis') }}</span>
                </button>
            </div>
        </div>
        <p x-show="msg" x-cloak class="text-xs mt-2" :class="ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'" x-text="msg"></p>
        {{-- Auto day-close on/off RIGHT NEXT to the cutoff selector (Task 661,
             owner tajweez): same flag as the Customize → Local Billing toggle
             (pos_auto_dayclose_24h) via the same cashier-gated endpoint —
             both places read/write ONE setting. --}}
        <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 flex flex-wrap items-start gap-3">
            <input type="checkbox" id="dc-auto-close-chk" x-model="autoOn" @change="toggleAuto()"
                class="mt-0.5 w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-purple-600 focus:ring-purple-500">
            <label for="dc-auto-close-chk" class="cursor-pointer flex-1 min-w-0">
                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.auto_dayclose_6am') }}</span>
                <span class="block text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.auto_dayclose_6am_sub') }}</span>
            </label>
            <span x-show="autoMsg" x-cloak class="text-xs font-semibold shrink-0" :class="autoOk ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'" x-text="autoMsg"></span>
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
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('pos.th_report_no') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('pos.th_date') }}</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">{{ __('pos.kpi_invoices') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('pos.kpi_revenue') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('pos.receipt_tax') }}</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">{{ __('pos.th_actions') }}</th>
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
                            <a href="{{ route('pos.day-close', ['date' => $rpt->report_date->format('Y-m-d')]) }}" class="text-gray-600 hover:text-gray-800 dark:text-gray-400 font-medium">{{ __('pos.view_btn') }}</a>
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
