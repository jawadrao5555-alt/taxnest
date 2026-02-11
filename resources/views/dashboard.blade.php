<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <h2 class="font-bold text-xl text-gray-800 leading-tight">
                    Dashboard - {{ $company->name ?? 'My Company' }}
                </h2>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold {{ $complianceBadge['bg'] }} {{ $complianceBadge['text'] }}">
                    {{ $complianceScore }} - {{ $complianceBadge['label'] }}
                </span>
            </div>
            <a href="/invoice/create" class="inline-flex items-center px-4 py-2 bg-emerald-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-emerald-700 transition">
                + New Invoice
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Total Invoices</p>
                            <p class="text-3xl font-bold text-gray-900 mt-1" x-data="{ count: 0 }" x-init="let target = {{ $totalInvoices }}; let interval = setInterval(() => { if(count < target) count++; else clearInterval(interval); }, 30)" x-text="count">0</p>
                        </div>
                        <div class="p-3 bg-blue-50 rounded-lg">
                            <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center space-x-2 text-sm">
                        <span class="text-yellow-600 font-medium">{{ $draftCount }} drafts</span>
                        <span class="text-gray-300">|</span>
                        <span class="text-blue-600 font-medium">{{ $submittedCount }} submitted</span>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Total Revenue</p>
                            <p class="text-3xl font-bold text-gray-900 mt-1">Rs. {{ number_format($totalRevenue) }}</p>
                        </div>
                        <div class="p-3 bg-emerald-50 rounded-lg">
                            <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">FBR Locked</p>
                            <p class="text-3xl font-bold text-gray-900 mt-1" x-data="{ count: 0 }" x-init="let target = {{ $lockedCount }}; let interval = setInterval(() => { if(count < target) count++; else clearInterval(interval); }, 30)" x-text="count">0</p>
                        </div>
                        <div class="p-3 bg-purple-50 rounded-lg">
                            <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">FBR Success</p>
                            <p class="text-3xl font-bold {{ $fbrSuccessRate >= 80 ? 'text-green-600' : ($fbrSuccessRate >= 50 ? 'text-yellow-600' : 'text-red-600') }} mt-1">{{ $fbrSuccessRate }}%</p>
                        </div>
                        <div class="p-3 bg-indigo-50 rounded-lg">
                            <svg class="w-6 h-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Plan Usage</p>
                            <p class="text-3xl font-bold text-gray-900 mt-1">{{ $invoicesUsed }}/{{ $invoiceLimit }}</p>
                        </div>
                        <div class="p-3 bg-orange-50 rounded-lg">
                            <svg class="w-6 h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                        </div>
                    </div>
                    @if($subscription)
                    <p class="mt-2 text-sm text-gray-500">{{ $subscription->pricingPlan->name }} Plan</p>
                    @php $usagePercent = $invoiceLimit > 0 ? min(100, ($invoicesUsed / $invoiceLimit) * 100) : 0; @endphp
                    <div class="mt-2 w-full bg-gray-200 rounded-full h-2">
                        <div class="h-2 rounded-full {{ $usagePercent > 80 ? 'bg-red-500' : 'bg-emerald-500' }}" style="width: {{ $usagePercent }}%"></div>
                    </div>
                    @endif
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Draft Aging Breakdown</h3>
                <div class="grid grid-cols-3 gap-4">
                    <div class="text-center p-4 bg-green-50 rounded-lg">
                        <p class="text-2xl font-bold text-green-700">{{ $draftAging['1_day'] }}</p>
                        <p class="text-sm text-green-600 mt-1">Less than 1 day</p>
                    </div>
                    <div class="text-center p-4 bg-yellow-50 rounded-lg">
                        <p class="text-2xl font-bold text-yellow-700">{{ $draftAging['3_days'] }}</p>
                        <p class="text-sm text-yellow-600 mt-1">1 - 3 days</p>
                    </div>
                    <div class="text-center p-4 bg-red-50 rounded-lg">
                        <p class="text-2xl font-bold text-red-700">{{ $draftAging['7_plus'] }}</p>
                        <p class="text-sm text-red-600 mt-1">7+ days old</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Invoice Status</h3>
                    <canvas id="statusChart" height="200"></canvas>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Monthly Invoices</h3>
                    <canvas id="monthlyChart" height="200"></canvas>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Compliance Trend (6 Months)</h3>
                    <canvas id="complianceChart" height="200"></canvas>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Recent Invoices</h3>
                        <a href="/invoices" class="text-sm text-emerald-600 hover:text-emerald-700 font-medium">View All</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Buyer</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($recentInvoices as $invoice)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                        <a href="/invoice/{{ $invoice->id }}" class="text-emerald-600 hover:underline">{{ $invoice->invoice_number ?? 'INV-' . $invoice->id }}</a>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ $invoice->buyer_name }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">Rs. {{ number_format($invoice->total_amount) }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium
                                            @if($invoice->status === 'draft') bg-yellow-100 text-yellow-800
                                            @elseif($invoice->status === 'submitted') bg-blue-100 text-blue-800
                                            @elseif($invoice->status === 'locked') bg-green-100 text-green-800
                                            @endif">{{ ucfirst($invoice->status) }}</span>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No invoices yet</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-800">Recent Activity</h3>
                    </div>
                    <div class="p-4 max-h-96 overflow-y-auto">
                        @forelse($recentActivity as $activity)
                        <div class="flex items-start space-x-3 py-3 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
                            <div class="flex-shrink-0 mt-1">
                                @if($activity->action === 'created')
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-green-100"><svg class="w-4 h-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg></span>
                                @elseif($activity->action === 'edited')
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100"><svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></span>
                                @elseif($activity->action === 'submitted')
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-100"><svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg></span>
                                @elseif($activity->action === 'locked')
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-emerald-100"><svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg></span>
                                @else
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gray-100"><svg class="w-4 h-4 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-800">
                                    <span class="font-medium">{{ $activity->user->name ?? 'System' }}</span>
                                    <span class="text-gray-500">{{ $activity->action }}</span>
                                    <a href="/invoice/{{ $activity->invoice_id }}" class="text-emerald-600 hover:underline font-medium">Invoice #{{ $activity->invoice->invoice_number ?? $activity->invoice_id }}</a>
                                </p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ $activity->created_at->diffForHumans() }} &middot; {{ $activity->ip_address }}</p>
                            </div>
                        </div>
                        @empty
                        <p class="text-center text-gray-400 py-6">No activity yet</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Draft', 'Submitted', 'Locked'],
                    datasets: [{
                        data: [{{ $statusData['draft'] }}, {{ $statusData['submitted'] }}, {{ $statusData['locked'] }}],
                        backgroundColor: ['#fbbf24', '#3b82f6', '#10b981'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });

            const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
            new Chart(monthlyCtx, {
                type: 'bar',
                data: {
                    labels: {!! json_encode($monthlyData->pluck('month_label')) !!},
                    datasets: [{
                        label: 'Invoices',
                        data: {!! json_encode($monthlyData->pluck('count')) !!},
                        backgroundColor: '#10b981',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                }
            });

            const complianceCtx = document.getElementById('complianceChart').getContext('2d');
            new Chart(complianceCtx, {
                type: 'line',
                data: {
                    labels: {!! json_encode(collect($complianceTrend)->pluck('month')) !!},
                    datasets: [{
                        label: 'Compliance %',
                        data: {!! json_encode(collect($complianceTrend)->pluck('score')) !!},
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2,
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, max: 100 } }
                }
            });
        });
    </script>
</x-app-layout>
