<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-200 leading-tight">Tax Collection Summary - {{ $year }}</h2>
            <div class="flex items-center space-x-2">
                <a href="{{ route('reports.wht') }}" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg text-xs font-semibold hover:bg-amber-700 transition">WHT Report</a>
                <a href="/mis" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg text-xs font-semibold hover:bg-gray-700 transition">MIS Reports</a>
                <a href="/dashboard" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-xs font-semibold hover:bg-emerald-700 transition">Dashboard</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <form method="GET" action="{{ route('reports.tax-summary') }}" class="flex flex-col sm:flex-row items-end gap-4">
                    <div class="flex-1 w-full sm:w-auto">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Year</label>
                        <select name="year" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @foreach($availableYears as $yr)
                            <option value="{{ $yr }}" {{ (int)$year === $yr ? 'selected' : '' }}>{{ $yr }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex-1 w-full sm:w-auto">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Party Filter (optional)</label>
                        <input type="text" name="party" value="{{ $partyFilter }}" placeholder="Filter by party name or NTN..." class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div class="flex-1 w-full sm:w-auto">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Status</label>
                        <select name="status" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="production" {{ $status === 'production' ? 'selected' : '' }}>Production</option>
                            <option value="draft" {{ $status === 'draft' ? 'selected' : '' }}>Draft</option>
                            <option value="failed" {{ $status === 'failed' ? 'selected' : '' }}>Failed</option>
                        </select>
                    </div>
                    <button type="submit" class="inline-flex items-center px-6 py-2.5 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 transition">
                        <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                        Apply
                    </button>
                </form>
            </div>

            <div class="mb-4">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $status === 'production' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400' : ($status === 'draft' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400') }}">
                    Showing: {{ ucfirst($status) }} Invoices
                </span>
            </div>

            @if($yearTotals['total_sales_tax'] > 0)
            <div class="bg-emerald-600 rounded-xl shadow-sm p-6 mb-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-emerald-100">Total Sales Tax to Pay FBR</p>
                        <p class="text-3xl font-bold mt-1">PKR {{ number_format($yearTotals['total_sales_tax'], 2) }}</p>
                        <p class="text-xs text-emerald-200 mt-2">This is the total sales tax collected from {{ ucfirst($status) }} invoices in {{ $year }} that needs to be deposited with FBR.</p>
                    </div>
                    <div class="text-right">
                        <svg class="w-16 h-16 text-white/20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                    </div>
                </div>
            </div>
            @endif

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5 text-center">
                    <p class="text-2xl font-bold text-emerald-700 dark:text-emerald-400">{{ number_format($yearTotals['invoice_count']) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Total Invoices</p>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5 text-center">
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($yearTotals['total_billed'], 2) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Total Billed</p>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5 text-center">
                    <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ number_format($yearTotals['total_wht'], 2) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">WHT Collected</p>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5 text-center">
                    <p class="text-2xl font-bold text-blue-700 dark:text-blue-400">{{ number_format($yearTotals['total_net'], 2) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Net Amount</p>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Monthly Breakdown</h3>
                    <div class="flex items-center flex-wrap gap-2">
                        @php
                            $baseParams = array_merge(request()->query(), ['status' => $status]);
                        @endphp
                        <div x-data="{ open: false }" class="relative">
                            <button @click="open = !open" type="button" class="inline-flex items-center px-3 py-1.5 bg-gray-600 text-white rounded-lg text-xs font-semibold hover:bg-gray-700 transition">
                                <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                CSV
                                <svg class="w-3 h-3 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="open" @click.away="open = false" class="absolute right-0 mt-1 w-56 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 z-50">
                                <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-800">
                                    <p class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ ucfirst($status) }} Invoices</p>
                                </div>
                                <a href="{{ route('reports.tax-summary.download', array_merge($baseParams, ['view' => 'whole'])) }}" class="block px-4 py-2 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">Whole Report CSV</a>
                                <a href="{{ route('reports.tax-summary.download', array_merge($baseParams, ['view' => 'partywise'])) }}" class="block px-4 py-2 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">Party-wise CSV</a>
                            </div>
                        </div>
                        <div x-data="{ open: false }" class="relative">
                            <button @click="open = !open" type="button" class="inline-flex items-center px-3 py-1.5 bg-red-600 text-white rounded-lg text-xs font-semibold hover:bg-red-700 transition">
                                <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                PDF
                                <svg class="w-3 h-3 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="open" @click.away="open = false" class="absolute right-0 mt-1 w-56 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 z-50">
                                <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-800">
                                    <p class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ ucfirst($status) }} Invoices</p>
                                </div>
                                <a href="{{ route('reports.tax-summary.pdf', array_merge($baseParams, ['view' => 'whole'])) }}" onclick="event.preventDefault(); downloadPdf(this.href);" class="block px-4 py-2 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer">Whole Report PDF</a>
                                <a href="{{ route('reports.tax-summary.pdf', array_merge($baseParams, ['view' => 'partywise'])) }}" onclick="event.preventDefault(); downloadPdf(this.href);" class="block px-4 py-2 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer">Party-wise PDF</a>
                            </div>
                        </div>
                        @php
                            $summaryShareText = urlencode('Tax Collection Summary ' . $year . ' - TaxNest');
                            $summaryShareUrl = urlencode(request()->fullUrl());
                        @endphp
                        <a href="https://wa.me/?text={{ $summaryShareText }}%20{{ $summaryShareUrl }}" target="_blank" class="inline-flex items-center px-2.5 py-1.5 bg-green-500 text-white rounded-lg text-xs font-semibold hover:bg-green-600 transition">WhatsApp</a>
                        <a href="mailto:?subject={{ $summaryShareText }}&body={{ $summaryShareUrl }}" class="inline-flex items-center px-2.5 py-1.5 bg-blue-500 text-white rounded-lg text-xs font-semibold hover:bg-blue-600 transition">Email</a>
                        <button onclick="navigator.clipboard.writeText('{{ request()->fullUrl() }}').then(() => { this.textContent = 'Copied!'; setTimeout(() => { this.textContent = 'Copy'; }, 2000); })" class="inline-flex items-center px-2.5 py-1.5 bg-indigo-500 text-white rounded-lg text-xs font-semibold hover:bg-indigo-600 transition">Copy</button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Month</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Invoices</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Billed</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Sales Tax Collected</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">WHT Collected</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Net Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse($monthly as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">
                                    {{ \Carbon\Carbon::createFromFormat('Y-m', $row->month_label)->format('F Y') }}
                                </td>
                                <td class="px-4 py-3 text-sm text-center text-gray-700 dark:text-gray-300 font-semibold">{{ $row->invoice_count }}</td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-300">{{ number_format($row->total_billed, 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right font-semibold text-emerald-700 dark:text-emerald-400">{{ number_format($row->total_sales_tax, 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right font-semibold text-amber-700 dark:text-amber-400">{{ number_format($row->total_wht, 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right font-semibold text-gray-900 dark:text-white">{{ number_format($row->total_net, 2) }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center">
                                    <svg class="mx-auto h-12 w-12 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No {{ ucfirst($status) }} invoices found for {{ $year }}.</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                        @if($monthly->count() > 0)
                        <tfoot class="bg-emerald-50 dark:bg-emerald-900/20">
                            <tr class="font-bold">
                                <td class="px-4 py-3 text-sm text-emerald-800 dark:text-emerald-300">Yearly Total</td>
                                <td class="px-4 py-3 text-sm text-center text-emerald-800 dark:text-emerald-300">{{ number_format($yearTotals['invoice_count']) }}</td>
                                <td class="px-4 py-3 text-sm text-right text-emerald-800 dark:text-emerald-300">{{ number_format($yearTotals['total_billed'], 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right text-emerald-800 dark:text-emerald-300">{{ number_format($yearTotals['total_sales_tax'], 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right text-emerald-800 dark:text-emerald-300">{{ number_format($yearTotals['total_wht'], 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right text-emerald-800 dark:text-emerald-300">{{ number_format($yearTotals['total_net'], 2) }}</td>
                            </tr>
                        </tfoot>
                        @endif
                    </table>
                </div>
            </div>

        </div>
    </div>
<script>
function downloadPdf(url) {
    var a = document.createElement('a');
    a.href = url;
    a.target = '_blank';
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    setTimeout(function(){ document.body.removeChild(a); }, 100);
}
</script>
</x-app-layout>
