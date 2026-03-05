<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">POS Reports</h1>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Payment Method Summary (This Month)</h3>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-2">Method</th>
                        <th class="pb-2 text-right">Count</th>
                        <th class="pb-2 text-right">Revenue</th>
                        <th class="pb-2 text-right">Tax Collected</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($paymentSummary as $ps)
                    <tr class="border-b border-gray-50 dark:border-gray-800">
                        <td class="py-2.5">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $ps->payment_method === 'cash' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' }}">
                                {{ ucwords(str_replace('_', ' ', $ps->payment_method)) }}
                            </span>
                        </td>
                        <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $ps->count }}</td>
                        <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($ps->total) }}</td>
                        <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">PKR {{ number_format($ps->tax) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-400">No data this month</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Top Selling Items (This Month)</h3>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-2">#</th>
                        <th class="pb-2">Item</th>
                        <th class="pb-2 text-right">Qty Sold</th>
                        <th class="pb-2 text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($topItems as $i => $item)
                    <tr class="border-b border-gray-50 dark:border-gray-800">
                        <td class="py-2.5 text-gray-400">{{ $i + 1 }}</td>
                        <td class="py-2.5 text-gray-900 dark:text-white font-medium">{{ $item->item_name }}</td>
                        <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ number_format($item->total_qty) }}</td>
                        <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($item->total_revenue) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-400">No data this month</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Daily Sales (Last 30 Days)</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-2">Date</th>
                        <th class="pb-2 text-right">Transactions</th>
                        <th class="pb-2 text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($dailySales as $day)
                    <tr class="border-b border-gray-50 dark:border-gray-800 {{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}">
                        <td class="py-2.5 text-gray-900 dark:text-white">{{ \Carbon\Carbon::parse($day->date)->format('d M Y (D)') }}</td>
                        <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $day->count }}</td>
                        <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($day->revenue) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="3" class="py-6 text-center text-gray-400">No sales data</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Monthly Trend (Last 6 Months)</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach($monthlyTrend as $mt)
            <div class="text-center p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 mb-1">{{ \Carbon\Carbon::parse($mt->month . '-01')->format('M Y') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $mt->count }}</p>
                <p class="text-xs text-emerald-600 font-medium">PKR {{ number_format($mt->revenue / 1000, 1) }}K</p>
            </div>
            @endforeach
        </div>
    </div>
</div>
</x-pos-layout>