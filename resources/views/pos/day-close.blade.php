<x-pos-layout>
<div class="max-w-7xl mx-auto">
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
            <a href="{{ route('pos.day-close-pdf', $existingReport->id) }}" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Download PDF
            </a>
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
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
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
            <button type="submit" onclick="return confirm('Are you sure you want to close this day? This action cannot be undone.')"
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

    @if($previousReports->isNotEmpty())
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Previous Day Close Reports</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
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
