<x-fbr-pos-layout>
<div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Tax Reports</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ now()->format('F Y') }} — FBR Tax Summary</p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Sales (Excl. Tax)</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($monthlyTax->total_sales ?? 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Tax Collected</p>
            <p class="text-2xl font-bold text-blue-600 mt-1">PKR {{ number_format($monthlyTax->total_tax ?? 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">FBR POS Fee Collected</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($monthlyTax->total_pos_fee ?? 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Invoices</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ $monthlyTax->invoice_count ?? 0 }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">FBR Submission Status</h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                    <span class="text-sm font-medium text-green-800 dark:text-green-300">FBR Submitted</span>
                    <span class="text-lg font-bold text-green-700 dark:text-green-400">{{ $fbrStats->submitted ?? 0 }}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-amber-50 dark:bg-amber-900/20 rounded-lg">
                    <span class="text-sm font-medium text-amber-800 dark:text-amber-300">Pending</span>
                    <span class="text-lg font-bold text-amber-700 dark:text-amber-400">{{ $fbrStats->pending ?? 0 }}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">
                    <span class="text-sm font-medium text-red-800 dark:text-red-300">Failed</span>
                    <span class="text-lg font-bold text-red-700 dark:text-red-400">{{ $fbrStats->failed ?? 0 }}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <span class="text-sm font-medium text-gray-800 dark:text-gray-300">Local (Offline)</span>
                    <span class="text-lg font-bold text-gray-700 dark:text-gray-400">{{ $fbrStats->local_count ?? 0 }}</span>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Tax Breakdown by Rate</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Tax Rate</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Invoices</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Sales</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Tax</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($taxByRate as $rate)
                        <tr>
                            <td class="px-3 py-2 text-sm font-semibold text-gray-900 dark:text-white">{{ number_format($rate->tax_rate, 0) }}%</td>
                            <td class="px-3 py-2 text-sm text-center text-gray-700 dark:text-gray-300">{{ $rate->count }}</td>
                            <td class="px-3 py-2 text-sm text-right text-gray-700 dark:text-gray-300">PKR {{ number_format($rate->sales_total, 2) }}</td>
                            <td class="px-3 py-2 text-sm text-right font-semibold text-blue-600">PKR {{ number_format($rate->tax_total, 2) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="px-3 py-6 text-center text-gray-400">No tax data yet</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</x-fbr-pos-layout>
