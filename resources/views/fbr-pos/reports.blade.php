<x-fbr-pos-layout>
<div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Sales Reports</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ now()->format('F Y') }} Overview</p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Today's Revenue</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($todayStats->revenue ?? 0) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ $todayStats->count ?? 0 }} invoices</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Today's Tax</p>
            <p class="text-2xl font-bold text-blue-600 mt-1">PKR {{ number_format($todayStats->tax ?? 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Monthly Revenue</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($monthStats->revenue ?? 0) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ $monthStats->count ?? 0 }} invoices</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Monthly Tax</p>
            <p class="text-2xl font-bold text-blue-600 mt-1">PKR {{ number_format($monthStats->tax ?? 0) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Daily Sales — {{ now()->format('F Y') }}</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Invoices</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($dailySales as $day)
                        <tr>
                            <td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">{{ \Carbon\Carbon::parse($day->date)->format('d M Y') }}</td>
                            <td class="px-3 py-2 text-sm text-center text-gray-700 dark:text-gray-300">{{ $day->count }}</td>
                            <td class="px-3 py-2 text-sm text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($day->revenue, 2) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="px-3 py-6 text-center text-gray-400">No sales data this month</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Payment Methods</h3>
            <div class="space-y-3">
                @forelse($paymentBreakdown as $pm)
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">{{ ucwords(str_replace('_', ' ', $pm->payment_method)) }}</p>
                        <p class="text-xs text-gray-500">{{ $pm->count }} transactions</p>
                    </div>
                    <p class="font-bold text-gray-900 dark:text-white">PKR {{ number_format($pm->revenue, 2) }}</p>
                </div>
                @empty
                <p class="text-center text-gray-400 py-6">No payment data yet</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
</x-fbr-pos-layout>
