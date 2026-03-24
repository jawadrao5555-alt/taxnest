<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Executive Dashboard</h2>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">{{ $company->name ?? 'Company' }}</span>
            </div>
            <div class="flex items-center space-x-3">
                <a href="/dashboard" class="inline-flex items-center px-3 py-2 bg-gray-600 rounded-lg font-semibold text-xs text-white uppercase hover:bg-gray-700 transition">Back to Dashboard</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Invoices</p>
                    <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ number_format($totalInvoices) }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Revenue</p>
                    <p class="text-3xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">PKR {{ number_format($totalRevenue) }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">FBR Locked</p>
                    <p class="text-3xl font-bold text-purple-600 dark:text-purple-400 mt-1">{{ number_format($lockedCount) }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Audit Probability</p>
                    <p class="text-3xl font-bold mt-1" style="color: {{ $auditEngine['color'] }}">{{ $auditEngine['probability'] }}%</p>
                    <p class="text-xs mt-1" style="color: {{ $auditEngine['color'] }}">{{ $auditEngine['level'] }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Monthly Submission Volume</h3>
                    <canvas id="volumeChart" height="200"></canvas>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Failure Rate Trend</h3>
                    <canvas id="failureChart" height="200"></canvas>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Tax Collected Trend</h3>
                    <canvas id="taxChart" height="200"></canvas>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Risk Score Trend</h3>
                    <canvas id="riskChart" height="200"></canvas>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Audit Probability Trend</h3>
                    <canvas id="auditChart" height="200"></canvas>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Audit Probability Gauge</h3>
                    <div class="flex flex-col items-center justify-center h-48">
                        <canvas id="auditGauge" width="200" height="120"></canvas>
                        <p class="text-4xl font-bold mt-2" style="color: {{ $auditEngine['color'] }}">{{ $auditEngine['probability'] }}%</p>
                        <p class="text-sm font-semibold" style="color: {{ $auditEngine['color'] }}">{{ $auditEngine['level'] }} Risk</p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Audit Probability Factors</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
                    @foreach($auditEngine['factors'] as $key => $factor)
                    <div class="text-center p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ $factor['label'] }}</p>
                        <p class="text-lg font-bold {{ $factor['weight'] > 50 ? 'text-red-600' : ($factor['weight'] > 25 ? 'text-yellow-600' : 'text-green-600') }}">{{ $factor['value'] }}</p>
                        <div class="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-1.5 mt-2">
                            <div class="h-1.5 rounded-full {{ $factor['weight'] > 50 ? 'bg-red-500' : ($factor['weight'] > 25 ? 'bg-yellow-500' : 'bg-green-500') }}" style="width: {{ min(100, $factor['weight']) }}%"></div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            @if($topCustomers->count() > 0)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Top 5 Customers</h3>
                </div>
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">NTN</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Invoices</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($topCustomers as $cust)
                        <tr>
                            <td class="px-6 py-3 text-sm text-gray-900 dark:text-gray-100">{{ $cust->buyer_name }}</td>
                            <td class="px-6 py-3 text-sm text-gray-500 dark:text-gray-400 font-mono">{{ $cust->buyer_ntn }}</td>
                            <td class="px-6 py-3 text-sm text-right text-gray-900 dark:text-gray-100">{{ $cust->invoice_count }}</td>
                            <td class="px-6 py-3 text-sm text-right font-semibold text-gray-900 dark:text-gray-100">PKR {{ number_format($cust->total_amount) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const isDark = document.documentElement.classList.contains('dark');
        const gridColor = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)';
        const textColor = isDark ? '#9ca3af' : '#6b7280';

        const defaultOptions = {
            responsive: true,
            plugins: { legend: { labels: { color: textColor } } },
            scales: {
                x: { ticks: { color: textColor }, grid: { color: gridColor } },
                y: { ticks: { color: textColor }, grid: { color: gridColor } }
            }
        };

        const volumeData = @json($monthlyVolume);
        new Chart(document.getElementById('volumeChart'), {
            type: 'bar',
            data: {
                labels: volumeData.map(d => d.month),
                datasets: [{
                    label: 'Invoices',
                    data: volumeData.map(d => d.count),
                    backgroundColor: 'rgba(16, 185, 129, 0.7)',
                    borderRadius: 6,
                }]
            },
            options: defaultOptions
        });

        const failureData = @json($failureRateTrend);
        new Chart(document.getElementById('failureChart'), {
            type: 'line',
            data: {
                labels: failureData.map(d => d.month),
                datasets: [{
                    label: 'Failure Rate %',
                    data: failureData.map(d => d.rate),
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    fill: true,
                    tension: 0.4,
                }]
            },
            options: { ...defaultOptions, scales: { ...defaultOptions.scales, y: { ...defaultOptions.scales.y, min: 0, max: 100 } } }
        });

        const taxData = @json($taxCollectedTrend);
        new Chart(document.getElementById('taxChart'), {
            type: 'line',
            data: {
                labels: taxData.map(d => d.month),
                datasets: [{
                    label: 'Tax Collected (PKR)',
                    data: taxData.map(d => d.amount),
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    fill: true,
                    tension: 0.4,
                }]
            },
            options: defaultOptions
        });

        const riskData = @json($riskTrend);
        new Chart(document.getElementById('riskChart'), {
            type: 'line',
            data: {
                labels: riskData.map(d => d.month),
                datasets: [{
                    label: 'Compliance Score',
                    data: riskData.map(d => d.score),
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    fill: true,
                    tension: 0.4,
                }]
            },
            options: { ...defaultOptions, scales: { ...defaultOptions.scales, y: { ...defaultOptions.scales.y, min: 0, max: 100 } } }
        });

        const auditData = @json($auditTrend);
        new Chart(document.getElementById('auditChart'), {
            type: 'line',
            data: {
                labels: auditData.map(d => d.month),
                datasets: [{
                    label: 'Audit Probability %',
                    data: auditData.map(d => d.probability),
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    fill: true,
                    tension: 0.4,
                }]
            },
            options: { ...defaultOptions, scales: { ...defaultOptions.scales, y: { ...defaultOptions.scales.y, min: 0, max: 100 } } }
        });

        const gaugeCtx = document.getElementById('auditGauge').getContext('2d');
        const prob = {{ $auditEngine['probability'] }};
        const gaugeColor = '{{ $auditEngine['color'] }}';
        gaugeCtx.beginPath();
        gaugeCtx.arc(100, 100, 80, Math.PI, 2 * Math.PI, false);
        gaugeCtx.lineWidth = 15;
        gaugeCtx.strokeStyle = isDark ? '#374151' : '#e5e7eb';
        gaugeCtx.stroke();
        gaugeCtx.beginPath();
        gaugeCtx.arc(100, 100, 80, Math.PI, Math.PI + (Math.PI * prob / 100), false);
        gaugeCtx.lineWidth = 15;
        gaugeCtx.strokeStyle = gaugeColor;
        gaugeCtx.lineCap = 'round';
        gaugeCtx.stroke();
    });
    </script>
</x-app-layout>
