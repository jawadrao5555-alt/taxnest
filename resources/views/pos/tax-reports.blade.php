<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ ($tab ?? 'pra') === 'local' ? __('pos.local_tax_reports') : __('pos.tax_reports') }}
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $dateLabel }} &mdash; {{ $taxRateLabel }}</p>
        </div>
        <div class="flex items-center gap-2 mt-3 sm:mt-0">
            <a href="{{ route('pos.tax-reports.csv', request()->all()) }}" class="inline-flex items-center px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-lg transition shadow-sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                {{ __('pos.download_csv') }}
            </a>
            <a href="{{ route('pos.tax-reports.pdf', request()->all()) }}" class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-lg transition shadow-sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                {{ __('pos.download_pdf') }}
            </a>
        </div>
    </div>

    @include('pos.partials.mode-tabs', ['baseUrl' => route('pos.tax-reports')])

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <form method="GET" action="{{ route('pos.tax-reports') }}" class="space-y-4" id="taxReportForm">
            <input type="hidden" name="tab" value="{{ $tab ?? 'pra' }}">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.lbl_tax_rate') }}</label>
                    <select name="tax_rate" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                        <option value="">{{ __('pos.opt_all_taxes') }}</option>
                        @foreach($availableRates ?? [] as $rate)
                            @php $rateLabel = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'); @endphp
                            <option value="{{ $rateLabel }}" {{ request('tax_rate') !== null && request('tax_rate') !== '' && request('tax_rate') !== 'exempt' && (float) request('tax_rate') === (float) $rate ? 'selected' : '' }}>{{ __('pos.opt_rate_tax_only', ['rate' => $rateLabel]) }}</option>
                        @endforeach
                        <option value="exempt" {{ request('tax_rate') == 'exempt' ? 'selected' : '' }}>{{ __('pos.opt_exempt_items_only') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.lbl_period') }}</label>
                    <select name="period" id="periodSelect" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                        <option value="">{{ __('pos.opt_all_time') }}</option>
                        <option value="today" {{ request('period') == 'today' ? 'selected' : '' }}>{{ __('pos.opt_today') }}</option>
                        <option value="yesterday" {{ request('period') == 'yesterday' ? 'selected' : '' }}>{{ __('pos.opt_yesterday') }}</option>
                        <option value="weekly" {{ request('period') == 'weekly' ? 'selected' : '' }}>{{ __('pos.opt_this_week') }}</option>
                        <option value="monthly" {{ request('period') == 'monthly' ? 'selected' : '' }}>{{ __('pos.opt_this_month') }}</option>
                        <option value="last_month" {{ request('period') == 'last_month' ? 'selected' : '' }}>{{ __('pos.opt_last_month') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.lbl_payment_method') }}</label>
                    <select name="payment_method" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                        <option value="">{{ __('pos.opt_all_methods') }}</option>
                        <option value="cash" {{ request('payment_method') == 'cash' ? 'selected' : '' }}>{{ __('pos.cash_title') }}</option>
                        <option value="debit_card" {{ request('payment_method') == 'debit_card' ? 'selected' : '' }}>{{ __('pos.opt_debit_card') }}</option>
                        <option value="credit_card" {{ request('payment_method') == 'credit_card' ? 'selected' : '' }}>{{ __('pos.opt_credit_card') }}</option>
                        <option value="qr_payment" {{ request('payment_method') == 'qr_payment' ? 'selected' : '' }}>{{ __('pos.opt_qr_raast') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.customer_word') }}</label>
                    <input type="text" name="customer" value="{{ request('customer') }}" placeholder="{{ __('pos.ph_search_customer_name') }}" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.lbl_date_from') }}</label>
                    <input type="date" name="date_from" id="dateFrom" value="{{ request('date_from') }}" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.lbl_date_to') }}</label>
                    <input type="date" name="date_to" id="dateTo" value="{{ request('date_to') }}" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 inline-flex items-center justify-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-lg transition shadow-sm">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                        {{ __('pos.apply_filters') }}
                    </button>
                    <a href="{{ route('pos.tax-reports', ['tab' => $tab ?? 'pra']) }}" class="inline-flex items-center justify-center px-4 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm font-semibold rounded-lg transition">
                        {{ __('pos.clear') }}
                    </a>
                </div>
            </div>
        </form>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var periodSelect = document.getElementById('periodSelect');
        var dateFrom = document.getElementById('dateFrom');
        var dateTo = document.getElementById('dateTo');

        periodSelect.addEventListener('change', function() {
            if (this.value) {
                dateFrom.value = '';
                dateTo.value = '';
            }
        });

        dateFrom.addEventListener('change', function() {
            if (this.value) periodSelect.value = '';
        });

        dateTo.addEventListener('change', function() {
            if (this.value) periodSelect.value = '';
        });
    });
    </script>

    @if($taxRateFilter ?? false)
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.kpi_invoices') }}</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white">{{ number_format($summary->total_invoices) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.kpi_rate_value', ['rate' => $taxRateLabel]) }}</p>
            <p class="text-xl font-bold text-emerald-600">PKR {{ number_format($summary->total_sales, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.kpi_rate_tax', ['rate' => $taxRateLabel]) }}</p>
            <p class="text-xl font-bold text-purple-600">PKR {{ number_format($summary->total_tax, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.kpi_rate_total', ['rate' => $taxRateLabel]) }}</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white">PKR {{ number_format($summary->total_sales + $summary->total_tax, 2) }}</p>
        </div>
    </div>
    @else
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.kpi_total_invoices') }}</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white">{{ number_format($summary->total_invoices) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.kpi_total_sales') }}</p>
            <p class="text-xl font-bold text-emerald-600">PKR {{ number_format($summary->total_sales, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.kpi_total_discount') }}</p>
            <p class="text-xl font-bold text-red-500">PKR {{ number_format($summary->total_discount, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.kpi_total_taxable') }}</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white">PKR {{ number_format($summary->total_taxable, 2) }}</p>
        </div>
        {{-- Third Schedule (Aug 2026): manufacturer-paid tax items — shown in no-filter view only --}}
        @if(!isset($taxRateFilter) || !$taxRateFilter)
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-700 shadow-md p-4 text-center">
            <p class="text-xs text-blue-600 dark:text-blue-400 mb-1 font-semibold">{{ __('pos.kpi_third_schedule') }}</p>
            <p class="text-xl font-bold text-blue-700 dark:text-blue-300">PKR {{ number_format($summary->total_third_schedule ?? 0, 2) }}</p>
        </div>
        <div class="bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-700 shadow-md p-4 text-center">
            <p class="text-xs text-amber-600 dark:text-amber-400 mb-1 font-semibold">{{ __('pos.kpi_tax_exempt_other') }}</p>
            <p class="text-xl font-bold text-amber-600">PKR {{ number_format($summary->total_exempt_other ?? max(0, ($summary->total_exempt ?? 0) - ($summary->total_third_schedule ?? 0)), 2) }}</p>
        </div>
        @else
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.kpi_tax_exempt') }}</p>
            <p class="text-xl font-bold text-amber-600">PKR {{ number_format($summary->total_exempt ?? 0, 2) }}</p>
        </div>
        <div class="hidden lg:block"></div>{{-- spacer --}}
        @endif
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.kpi_total_tax') }}</p>
            <p class="text-xl font-bold text-purple-600">PKR {{ number_format($summary->total_tax, 2) }}</p>
        </div>
    </div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                        <th class="px-4 py-3">{{ __('pos.th_pos_invoice_no') }}</th>
                        <th class="px-4 py-3">{{ __('pos.th_pra_fiscal_no') }}</th>
                        <th class="px-4 py-3">{{ __('pos.th_date') }}</th>
                        <th class="px-4 py-3">{{ __('pos.customer_word') }}</th>
                        <th class="px-4 py-3">{{ __('pos.th_payment') }}</th>
                        @if($taxRateFilter ?? false)
                        <th class="px-4 py-3 text-right">{{ __('pos.kpi_rate_value', ['rate' => $taxRateLabel]) }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.kpi_rate_tax', ['rate' => $taxRateLabel]) }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.kpi_rate_total', ['rate' => $taxRateLabel]) }}</th>
                        @else
                        <th class="px-4 py-3 text-right">{{ __('pos.subtotal') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.receipt_discount') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.th_taxable') }}</th>
                        <th class="px-4 py-3 text-right hidden lg:table-cell">{{ __('pos.exempt') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.th_tax_pct') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.th_tax_amt') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.total_word') }}</th>
                        @endif
                        <th class="px-4 py-3">{{ __('pos.th_terminal') }}</th>
                        <th class="px-4 py-3">{{ __('pos.pra_word') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($transactions as $t)
                    @php
                        $iv = ($taxRateFilter ?? false) ? ($itemValues[$t->id] ?? null) : null;
                    @endphp
                    @if(($taxRateFilter ?? false) && !$iv)
                        @continue
                    @endif
                    <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }} hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white whitespace-nowrap">
                            <a href="{{ route('pos.transaction.show', $t->id) }}" class="text-purple-600 hover:text-purple-800 dark:text-purple-400 hover:underline">{{ $t->invoice_number }}</a>
                        </td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $t->pra_invoice_number ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $t->created_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ $t->customer_name ?? __('pos.walk_in_short') }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $t->payment_method === 'cash' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' }}">
                                {{ ucwords(str_replace('_', ' ', $t->payment_method)) }}
                            </span>
                        </td>
                        @if($taxRateFilter ?? false)
                        <td class="px-4 py-3 text-right text-emerald-600 font-medium whitespace-nowrap">{{ number_format((float)($iv['item_subtotal'] ?? 0), 2) }}</td>
                        <td class="px-4 py-3 text-right text-purple-600 font-medium whitespace-nowrap">{{ number_format((float)($iv['item_tax'] ?? 0), 2) }}</td>
                        <td class="px-4 py-3 text-right font-bold text-gray-900 dark:text-white whitespace-nowrap">{{ number_format((float)($iv['item_subtotal'] ?? 0) + (float)($iv['item_tax'] ?? 0), 2) }}</td>
                        @else
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ number_format($t->subtotal, 2) }}</td>
                        <td class="px-4 py-3 text-right text-red-500 whitespace-nowrap">{{ number_format($t->discount_amount, 2) }}</td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ number_format($t->subtotal - $t->discount_amount - ($t->exempt_amount ?? 0), 2) }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap hidden lg:table-cell">
                            @if(($t->exempt_amount ?? 0) > 0)
                            <span class="text-amber-600 dark:text-amber-400 font-medium">{{ number_format($t->exempt_amount, 2) }}</span>
                            @else
                            <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white whitespace-nowrap">{{ number_format($t->tax_rate, 0) }}%</td>
                        <td class="px-4 py-3 text-right text-purple-600 dark:text-purple-400 font-medium whitespace-nowrap">{{ number_format($t->tax_amount, 2) }}</td>
                        <td class="px-4 py-3 text-right font-bold text-gray-900 dark:text-white whitespace-nowrap">{{ number_format($t->total_amount, 2) }}</td>
                        @endif
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $t->terminal?->terminal_name ?? '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            @php
                                $praColors = [
                                    'submitted' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
                                    'pending' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
                                    'failed' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
                                    'offline' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
                                    'local' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
                                ];
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $praColors[$t->pra_status] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ ucfirst($t->pra_status ?? 'N/A') }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ ($taxRateFilter ?? false) ? 10 : 14 }}" class="px-4 py-12 text-center text-gray-400 dark:text-gray-500">
                            <svg class="w-10 h-10 mx-auto mb-2 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <p class="text-sm">{{ __('pos.no_transactions_for_filters') }}</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
                @if($transactions->count() > 0)
                <tfoot>
                    @if($taxRateFilter ?? false)
                    <tr class="bg-gray-50 dark:bg-gray-800/50 border-t-2 border-gray-300 dark:border-gray-600 font-bold text-sm">
                        <td class="px-4 py-3 text-gray-900 dark:text-white" colspan="5">{{ __('pos.rate_totals_invoices', ['rate' => $taxRateLabel, 'count' => $summary->total_invoices]) }}</td>
                        <td class="px-4 py-3 text-right text-emerald-600">PKR {{ number_format($summary->total_sales, 2) }}</td>
                        <td class="px-4 py-3 text-right text-purple-600">PKR {{ number_format($summary->total_tax, 2) }}</td>
                        <td class="px-4 py-3 text-right text-gray-900 dark:text-white">PKR {{ number_format($summary->total_sales + $summary->total_tax, 2) }}</td>
                        <td class="px-4 py-3" colspan="2"></td>
                    </tr>
                    @else
                    <tr class="bg-gray-50 dark:bg-gray-800/50 border-t-2 border-gray-300 dark:border-gray-600 font-bold text-sm">
                        <td class="px-4 py-3 text-gray-900 dark:text-white" colspan="5">{{ __('pos.filtered_totals_invoices', ['count' => $summary->total_invoices]) }}</td>
                        <td class="px-4 py-3 text-right text-gray-900 dark:text-white">—</td>
                        <td class="px-4 py-3 text-right text-red-600">PKR {{ number_format($summary->total_discount, 2) }}</td>
                        <td class="px-4 py-3 text-right text-gray-900 dark:text-white">PKR {{ number_format($summary->total_taxable, 2) }}</td>
                        <td class="px-4 py-3 text-right text-amber-600 hidden lg:table-cell">PKR {{ number_format($summary->total_exempt ?? 0, 2) }}</td>
                        <td class="px-4 py-3 text-right text-gray-900 dark:text-white">—</td>
                        <td class="px-4 py-3 text-right text-purple-600">PKR {{ number_format($summary->total_tax, 2) }}</td>
                        <td class="px-4 py-3 text-right text-emerald-600">PKR {{ number_format($summary->total_sales, 2) }}</td>
                        <td class="px-4 py-3" colspan="2"></td>
                    </tr>
                    @endif
                </tfoot>
                @endif
            </table>
        </div>
    </div>

    @if($transactions->hasPages())
    <div class="mt-4">
        {{ $transactions->links() }}
    </div>
    @endif
</div>
@if($hasPinSet ?? false)
@include('pos.partials.pin-modal')
@endif
</x-pos-layout>
