<x-franchise-layout>
<div class="p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">Revenue Overview</h1>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-5">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Total Revenue</p>
            <p class="text-2xl font-bold text-emerald-600">PKR {{ number_format($totalRevenue, 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-5">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Commission Rate</p>
            <p class="text-2xl font-bold text-teal-600">{{ $commissionRate }}%</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-5">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Total Commission</p>
            <p class="text-2xl font-bold text-indigo-600">PKR {{ number_format($totalCommission, 0) }}</p>
        </div>
    </div>

    @if($monthlyRevenue->count() > 0)
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Monthly Revenue (Last 12 Months)</h3>
        <canvas id="revenueChart" height="120"></canvas>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                new Chart(document.getElementById('revenueChart'), {
                    type: 'line',
                    data: {
                        labels: {!! json_encode($monthlyRevenue->pluck('month')) !!},
                        datasets: [{
                            label: 'Revenue (PKR)',
                            data: {!! json_encode($monthlyRevenue->pluck('revenue')) !!},
                            borderColor: 'rgb(13, 148, 136)',
                            backgroundColor: 'rgba(13, 148, 136, 0.1)',
                            fill: true, tension: 0.3, borderWidth: 2
                        }]
                    },
                    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
                });
            });
        </script>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                        <th class="px-4 py-3">Month</th>
                        <th class="px-4 py-3 text-right">Transactions</th>
                        <th class="px-4 py-3 text-right">Revenue (PKR)</th>
                        <th class="px-4 py-3 text-right">Commission (PKR)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($monthlyRevenue as $m)
                    <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}">
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $m->month }}</td>
                        <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-300">{{ number_format($m->count) }}</td>
                        <td class="px-4 py-3 text-right text-emerald-600 font-medium">{{ number_format($m->revenue, 0) }}</td>
                        <td class="px-4 py-3 text-right text-indigo-600 font-medium">{{ number_format($m->revenue * $commissionRate / 100, 0) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @else
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-12 text-center">
        <p class="text-gray-500 dark:text-gray-400">No revenue data yet.</p>
    </div>
    @endif
</div>
</x-franchise-layout>
