<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ ($tab ?? 'pra') === 'local' ? 'Local Tax Reports' : 'Tax Reports' }}
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $dateLabel }} &mdash; {{ $taxRateLabel }}</p>
        </div>
        <div class="flex items-center gap-2 mt-3 sm:mt-0">
            <a href="{{ route('pos.tax-reports.csv', request()->all()) }}" class="inline-flex items-center px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-lg transition shadow-sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Download CSV
            </a>
            <a href="{{ route('pos.tax-reports.pdf', request()->all()) }}" class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-lg transition shadow-sm">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                Download PDF
            </a>
        </div>
    </div>

    @include('pos.partials.mode-tabs', ['baseUrl' => route('pos.tax-reports')])

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <form method="GET" action="{{ route('pos.tax-reports') }}" class="space-y-4">
            <input type="hidden" name="tab" value="{{ $tab ?? 'pra' }}">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Tax Rate</label>
                    <select name="tax_rate" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                        <option value="">All Taxes</option>
                        <option value="5" {{ request('tax_rate') == '5' ? 'selected' : '' }}>5% Tax Only</option>
                        <option value="16" {{ request('tax_rate') == '16' ? 'selected' : '' }}>16% Tax Only</option>
                        <option value="exempt" {{ request('tax_rate') == 'exempt' ? 'selected' : '' }}>Exempt Items Only</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Period</label>
                    <select name="period" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                        <option value="">All Time</option>
                        <option value="today" {{ request('period') == 'today' ? 'selected' : '' }}>Today</option>
                        <option value="weekly" {{ request('period') == 'weekly' ? 'selected' : '' }}>This Week</option>
                        <option value="monthly" {{ request('period') == 'monthly' ? 'selected' : '' }}>This Month</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Payment Method</label>
                    <select name="payment_method" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                        <option value="">All Methods</option>
                        <option value="cash" {{ request('payment_method') == 'cash' ? 'selected' : '' }}>Cash</option>
                        <option value="debit_card" {{ request('payment_method') == 'debit_card' ? 'selected' : '' }}>Debit Card</option>
                        <option value="credit_card" {{ request('payment_method') == 'credit_card' ? 'selected' : '' }}>Credit Card</option>
                        <option value="qr_payment" {{ request('payment_method') == 'qr_payment' ? 'selected' : '' }}>QR / Raast</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Customer</label>
                    <input type="text" name="customer" value="{{ request('customer') }}" placeholder="Search customer name" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Date From</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Date To</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 inline-flex items-center justify-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-lg transition shadow-sm">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                        Apply Filters
                    </button>
                    <a href="{{ route('pos.tax-reports') }}" class="inline-flex items-center justify-center px-4 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm font-semibold rounded-lg transition">
                        Clear
                    </a>
                </div>
            </div>
        </form>
    </div>

    @if($taxRateFilter ?? false)
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Invoices</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white">{{ number_format($summary->total_invoices) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ $taxRateLabel }} Value</p>
            <p class="text-xl font-bold text-emerald-600">PKR {{ number_format($summary->total_sales, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ $taxRateLabel }} Tax</p>
            <p class="text-xl font-bold text-purple-600">PKR {{ number_format($summary->total_tax, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ $taxRateLabel }} Total</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white">PKR {{ number_format($summary->total_sales + $summary->total_tax, 2) }}</p>
        </div>
    </div>
    @else
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Total Invoices</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white">{{ number_format($summary->total_invoices) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Total Sales</p>
            <p class="text-xl font-bold text-emerald-600">PKR {{ number_format($summary->total_sales, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Total Discount</p>
            <p class="text-xl font-bold text-red-500">PKR {{ number_format($summary->total_discount, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Total Taxable</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white">PKR {{ number_format($summary->total_taxable, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Tax Exempt</p>
            <p class="text-xl font-bold text-amber-600">PKR {{ number_format($summary->total_exempt ?? 0, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Total Tax</p>
            <p class="text-xl font-bold text-purple-600">PKR {{ number_format($summary->total_tax, 2) }}</p>
        </div>
    </div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                        <th class="px-4 py-3">POS Invoice #</th>
                        <th class="px-4 py-3">PRA Fiscal #</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Payment</th>
                        @if($taxRateFilter ?? false)
                        <th class="px-4 py-3 text-right">{{ $taxRateLabel }} Value</th>
                        <th class="px-4 py-3 text-right">{{ $taxRateLabel }} Tax</th>
                        <th class="px-4 py-3 text-right">{{ $taxRateLabel }} Total</th>
                        @else
                        <th class="px-4 py-3 text-right">Subtotal</th>
                        <th class="px-4 py-3 text-right">Discount</th>
                        <th class="px-4 py-3 text-right">Taxable</th>
                        <th class="px-4 py-3 text-right hidden lg:table-cell">Exempt</th>
                        <th class="px-4 py-3 text-right">Tax %</th>
                        <th class="px-4 py-3 text-right">Tax Amt</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        @endif
                        <th class="px-4 py-3">Terminal</th>
                        <th class="px-4 py-3">PRA</th>
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
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ $t->customer_name ?? 'Walk-in' }}</td>
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
                            <p class="text-sm">No transactions found for the selected filters.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
                @if($transactions->count() > 0)
                <tfoot>
                    @if($taxRateFilter ?? false)
                    <tr class="bg-gray-50 dark:bg-gray-800/50 border-t-2 border-gray-300 dark:border-gray-600 font-bold text-sm">
                        <td class="px-4 py-3 text-gray-900 dark:text-white" colspan="5">{{ $taxRateLabel }} Totals ({{ $summary->total_invoices }} invoices)</td>
                        <td class="px-4 py-3 text-right text-emerald-600">PKR {{ number_format($summary->total_sales, 2) }}</td>
                        <td class="px-4 py-3 text-right text-purple-600">PKR {{ number_format($summary->total_tax, 2) }}</td>
                        <td class="px-4 py-3 text-right text-gray-900 dark:text-white">PKR {{ number_format($summary->total_sales + $summary->total_tax, 2) }}</td>
                        <td class="px-4 py-3" colspan="2"></td>
                    </tr>
                    @else
                    <tr class="bg-gray-50 dark:bg-gray-800/50 border-t-2 border-gray-300 dark:border-gray-600 font-bold text-sm">
                        <td class="px-4 py-3 text-gray-900 dark:text-white" colspan="5">Filtered Totals ({{ $summary->total_invoices }} invoices)</td>
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
