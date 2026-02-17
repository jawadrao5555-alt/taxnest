<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 leading-tight">Super Admin Dashboard</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            @if($pendingCompanies > 0)
            <div class="mb-8">
                <a href="/admin/companies/pending" class="block bg-amber-50 rounded-xl shadow-sm border-2 border-amber-300 p-6 hover:bg-amber-100 transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-bold text-amber-700 uppercase tracking-wider">Pending Approvals</p>
                            <p class="text-4xl font-extrabold text-amber-600 mt-2">{{ $pendingCompanies }}</p>
                            <p class="text-sm text-amber-600 mt-1">Companies awaiting approval</p>
                        </div>
                        <div class="p-4 bg-amber-200 rounded-xl">
                            <svg class="w-8 h-8 text-amber-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    </div>
                </a>
            </div>
            @endif

            <div class="bg-gradient-to-r from-emerald-500 to-emerald-700 rounded-xl shadow-sm p-5 mb-8">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center space-x-3">
                        <div class="p-2 bg-white/20 rounded-lg">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-emerald-100 uppercase tracking-wider">Today's Invoices</p>
                            <p class="text-2xl font-bold text-white">{{ $todayInvoices }}</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="p-2 bg-white/20 rounded-lg">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-emerald-100 uppercase tracking-wider">Today's Revenue</p>
                            <p class="text-2xl font-bold text-white">Rs. {{ number_format($todayRevenue) }}</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="p-2 bg-white/20 rounded-lg">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-emerald-100 uppercase tracking-wider">New Companies (Month)</p>
                            <p class="text-2xl font-bold text-white">{{ $newCompaniesThisMonth }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Companies</p>
                            <p class="text-3xl font-bold text-gray-900 mt-1">{{ $totalCompanies }}</p>
                        </div>
                        <div class="p-3 bg-blue-50 rounded-lg">
                            <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-gray-500">{{ $activeSubscriptions }} active subscriptions</p>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Users</p>
                            <p class="text-3xl font-bold text-gray-900 mt-1">{{ $totalUsers }}</p>
                        </div>
                        <div class="p-3 bg-emerald-50 rounded-lg">
                            <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Total Invoices</p>
                            <p class="text-3xl font-bold text-gray-900 mt-1">{{ $totalInvoices }}</p>
                        </div>
                        <div class="p-3 bg-purple-50 rounded-lg">
                            <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                    </div>
                    <div class="mt-2 flex items-center space-x-2 text-sm">
                        <span class="text-yellow-600">{{ $draftInvoices }} draft</span>
                        <span class="text-gray-300">|</span>
                        <span class="text-green-600">{{ $lockedInvoices }} locked</span>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Total Revenue</p>
                            <p class="text-3xl font-bold text-gray-900 mt-1">Rs. {{ number_format($totalRevenue) }}</p>
                        </div>
                        <div class="p-3 bg-orange-50 rounded-lg">
                            <svg class="w-6 h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    </div>
                    @if($failedLogs > 0)
                    <p class="mt-2 text-sm text-red-500 font-medium">{{ $failedLogs }} failed FBR submissions</p>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 dark:border-gray-800">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Recent Companies</h3>
                        <a href="/admin/companies" class="text-sm text-emerald-600 hover:text-emerald-700 font-medium">View All</a>
                    </div>
                    <div class="p-6">
                        @forelse($recentCompanies as $company)
                        <div class="flex items-center justify-between py-3 {{ !$loop->last ? 'border-b border-gray-200 dark:border-gray-800' : '' }}">
                            <div>
                                <p class="font-medium text-gray-900">{{ $company->name }}</p>
                                <p class="text-sm text-gray-500">NTN: {{ $company->ntn }}</p>
                            </div>
                            <div class="text-right text-sm">
                                <p class="text-gray-700">{{ $company->invoices_count }} invoices</p>
                                <p class="text-gray-500">{{ $company->users_count }} users</p>
                            </div>
                        </div>
                        @empty
                        <p class="text-gray-400 text-center py-4">No companies yet</p>
                        @endforelse
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-200 dark:border-gray-800">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-800">
                        <h3 class="text-lg font-semibold text-gray-800">Recent Invoices (All Companies)</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($recentInvoices as $invoice)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $invoice->invoice_number ?? 'INV-' . $invoice->id }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ $invoice->company->name ?? 'N/A' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-900">Rs. {{ number_format($invoice->total_amount) }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium
                                            @if($invoice->status === 'draft') bg-yellow-100 text-yellow-800
                                            @elseif($invoice->status === 'failed') bg-red-100 text-red-800
                                            @elseif($invoice->status === 'locked') bg-green-100 text-green-800
                                            @elseif($invoice->status === 'pending_verification') bg-amber-100 text-amber-800
                                            @endif">{{ $invoice->status === 'pending_verification' ? 'Pending' : ucfirst($invoice->status) }}</span>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No invoices</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2 mb-4">
                        <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        <span>Top 5 Companies by Revenue</span>
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Company</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Invoices</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse($topCompanies as $index => $tc)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-500 dark:text-gray-400">{{ $index + 1 }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $tc->name }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $tc->invoices_count }}</td>
                                    <td class="px-4 py-3 text-sm font-bold text-emerald-700 dark:text-emerald-400 text-right">Rs. {{ number_format($tc->company_revenue) }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No revenue data yet</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2 mb-4">
                        <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        <span>Monthly Revenue (Last 6 Months)</span>
                    </h3>
                    <div style="position: relative; height: 280px;">
                        <canvas id="monthlyRevenueChart"></canvas>
                    </div>
                </div>
            </div>

            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const ctx = document.getElementById('monthlyRevenueChart');
                    if (ctx) {
                        new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: {!! json_encode($monthlyRevenue->map(function($m) { return \Carbon\Carbon::parse($m->month . '-01')->format('M Y'); })->values()) !!},
                                datasets: [{
                                    label: 'Revenue (Rs.)',
                                    data: {!! json_encode($monthlyRevenue->pluck('revenue')->values()) !!},
                                    backgroundColor: 'rgba(59, 130, 246, 0.7)',
                                    borderColor: 'rgba(59, 130, 246, 1)',
                                    borderWidth: 1,
                                    borderRadius: 6,
                                    barPercentage: 0.6
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                return 'Rs. ' + Number(context.raw).toLocaleString();
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        ticks: {
                                            callback: function(value) {
                                                return 'Rs. ' + Number(value).toLocaleString();
                                            }
                                        },
                                        grid: { color: 'rgba(0,0,0,0.05)' }
                                    },
                                    x: {
                                        grid: { display: false }
                                    }
                                }
                            }
                        });
                    }
                });
            </script>

            @if($expiringTrials->count() > 0)
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-amber-200 dark:border-amber-800 p-6 mb-8">
                <h3 class="text-lg font-semibold text-amber-800 dark:text-amber-300 flex items-center space-x-2 mb-4">
                    <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    <span>Expiring Trials (Next 7 Days)</span>
                </h3>
                <div class="space-y-2">
                    @foreach($expiringTrials as $trial)
                    @php
                        $daysLeft = $trial->ends_at ? (int) now()->diffInDays($trial->ends_at, false) : 0;
                    @endphp
                    <div class="flex items-center justify-between p-3 bg-amber-50 dark:bg-amber-900/20 rounded-lg">
                        <div class="flex items-center space-x-3">
                            <div class="p-2 bg-amber-100 dark:bg-amber-900/50 rounded-lg">
                                <svg class="w-4 h-4 text-amber-700 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900 dark:text-gray-100 text-sm">{{ $trial->company->name ?? 'N/A' }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Expires {{ $trial->ends_at ? $trial->ends_at->format('M d, Y') : 'N/A' }}</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm font-bold {{ $daysLeft <= 2 ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400' }}">{{ $daysLeft }} {{ $daysLeft === 1 ? 'day' : 'days' }} left</span>
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $daysLeft <= 2 ? 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300' }}">
                                {{ $daysLeft <= 2 ? 'Critical' : 'Warning' }}
                            </span>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2 mb-4">
                    <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Activity Feed</span>
                </h3>
                <div class="space-y-1 max-h-96 overflow-y-auto">
                    @forelse($activityFeed as $activity)
                    <div class="flex items-start space-x-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg transition">
                        <div class="flex-shrink-0 mt-0.5">
                            @if(str_contains(strtolower($activity->action ?? ''), 'create'))
                                <span class="inline-flex w-7 h-7 rounded-full bg-green-100 dark:bg-green-900/50 items-center justify-center"><svg class="w-3.5 h-3.5 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg></span>
                            @elseif(str_contains(strtolower($activity->action ?? ''), 'delete') || str_contains(strtolower($activity->action ?? ''), 'reject'))
                                <span class="inline-flex w-7 h-7 rounded-full bg-red-100 dark:bg-red-900/50 items-center justify-center"><svg class="w-3.5 h-3.5 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></span>
                            @elseif(str_contains(strtolower($activity->action ?? ''), 'update') || str_contains(strtolower($activity->action ?? ''), 'edit'))
                                <span class="inline-flex w-7 h-7 rounded-full bg-blue-100 dark:bg-blue-900/50 items-center justify-center"><svg class="w-3.5 h-3.5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></span>
                            @elseif(str_contains(strtolower($activity->action ?? ''), 'login') || str_contains(strtolower($activity->action ?? ''), 'auth'))
                                <span class="inline-flex w-7 h-7 rounded-full bg-purple-100 dark:bg-purple-900/50 items-center justify-center"><svg class="w-3.5 h-3.5 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></span>
                            @else
                                <span class="inline-flex w-7 h-7 rounded-full bg-gray-100 dark:bg-gray-700 items-center justify-center"><svg class="w-3.5 h-3.5 text-gray-600 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-gray-900 dark:text-gray-100">
                                <span class="font-medium">{{ $activity->user->name ?? 'System' }}</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ str_replace('_', ' ', $activity->action ?? 'performed action') }}</span>
                                @if($activity->entity_type)
                                    <span class="text-gray-400 dark:text-gray-500">on {{ $activity->entity_type }} #{{ $activity->entity_id }}</span>
                                @endif
                            </p>
                            @if($activity->new_values)
                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">{{ is_array($activity->new_values) ? json_encode($activity->new_values) : $activity->new_values }}</p>
                            @endif
                            <div class="flex items-center space-x-3 mt-1">
                                <span class="text-xs text-gray-400 dark:text-gray-500">{{ $activity->created_at->diffForHumans() }}</span>
                                @if($activity->ip_address)
                                    <span class="text-xs text-gray-400 dark:text-gray-500">IP: {{ $activity->ip_address }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    @empty
                    <p class="text-gray-400 text-center py-4">No recent activity</p>
                    @endforelse
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-cyan-100 p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-cyan-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                        <span>Tax Override Intelligence</span>
                    </h3>
                    <a href="/tax-overrides" class="text-sm text-cyan-600 hover:text-cyan-700 font-medium">Manage Rules</a>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div class="text-center p-3 bg-blue-50 rounded-lg">
                        <p class="text-2xl font-bold text-blue-700">{{ $overrideStats['sector_rules'] }}</p>
                        <p class="text-xs text-blue-600 mt-1">Sector Rules</p>
                    </div>
                    <div class="text-center p-3 bg-purple-50 rounded-lg">
                        <p class="text-2xl font-bold text-purple-700">{{ $overrideStats['province_rules'] }}</p>
                        <p class="text-xs text-purple-600 mt-1">Province Rules</p>
                    </div>
                    <div class="text-center p-3 bg-emerald-50 rounded-lg">
                        <p class="text-2xl font-bold text-emerald-700">{{ $overrideStats['customer_rules'] }}</p>
                        <p class="text-xs text-emerald-600 mt-1">Customer Rules</p>
                    </div>
                    <div class="text-center p-3 bg-amber-50 rounded-lg">
                        <p class="text-2xl font-bold text-amber-700">{{ $overrideStats['sro_rules'] }}</p>
                        <p class="text-xs text-amber-600 mt-1">SRO Rules</p>
                    </div>
                    <div class="text-center p-3 bg-cyan-50 rounded-lg">
                        <p class="text-2xl font-bold text-cyan-700">{{ $overrideStats['total_overrides_applied'] }}</p>
                        <p class="text-xs text-cyan-600 mt-1">Total Applied</p>
                    </div>
                    <div class="text-center p-3 bg-teal-50 rounded-lg">
                        <p class="text-2xl font-bold text-teal-700">{{ $overrideStats['overrides_this_month'] }}</p>
                        <p class="text-xs text-teal-600 mt-1">This Month</p>
                    </div>
                </div>
            </div>

            @if(auth()->user()->role === 'super_admin')
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-rose-100 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 flex items-center space-x-2">
                            <svg class="w-5 h-5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                            <span>Top Rejected HS Codes (Last 30 Days)</span>
                        </h3>
                        <a href="/admin/hs-unmapped" class="text-sm text-rose-600 hover:text-rose-700 font-medium">Manage HS</a>
                    </div>
                    @if($topRejectedHsCodes->count() > 0)
                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        @foreach($topRejectedHsCodes as $rejected)
                        @php
                            $badge = \App\Services\HsIntelligenceService::getConfidenceBadge(max(0, 100 - ($rejected->rejection_count * 10)));
                        @endphp
                        <div class="flex items-center justify-between p-3 bg-rose-50 rounded-lg">
                            <div class="flex items-center space-x-3">
                                <span class="font-mono text-sm font-bold text-gray-800">{{ $rejected->hs_code }}</span>
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-{{ $badge['color'] }}-100 text-{{ $badge['color'] }}-800">{{ $badge['label'] }}</span>
                            </div>
                            <div class="text-right">
                                <span class="text-sm font-bold text-rose-700">{{ $rejected->rejection_count }}x</span>
                                <p class="text-xs text-gray-500">{{ $rejected->last_rejected_at ? $rejected->last_rejected_at->diffForHumans() : ($rejected->last_seen_at ? $rejected->last_seen_at->diffForHumans() : 'N/A') }}</p>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <p class="text-gray-400 text-center py-4">No HS rejections in the last 30 days</p>
                    @endif
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-indigo-100 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 flex items-center space-x-2">
                            <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg>
                            <span>HS Intelligence Summary</span>
                        </h3>
                        <a href="/admin/hs-master" class="text-sm text-indigo-600 hover:text-indigo-700 font-medium">HS Master</a>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="text-center p-4 bg-indigo-50 rounded-lg">
                            <p class="text-3xl font-bold text-indigo-700">{{ $totalHsMaster }}</p>
                            <p class="text-xs text-indigo-600 mt-1">Total HS Codes</p>
                        </div>
                        <div class="text-center p-4 bg-amber-50 rounded-lg">
                            <p class="text-3xl font-bold text-amber-700">{{ $totalUnmapped }}</p>
                            <p class="text-xs text-amber-600 mt-1">Unmapped Queue</p>
                        </div>
                        <div class="text-center p-4 bg-rose-50 rounded-lg">
                            <p class="text-3xl font-bold text-rose-700">{{ $topRejectedHsCodes->sum('rejection_count') }}</p>
                            <p class="text-xs text-rose-600 mt-1">Total Rejections</p>
                        </div>
                        <div class="text-center p-4 bg-green-50 rounded-lg">
                            <p class="text-3xl font-bold text-green-700">{{ $topRejectedHsCodes->count() }}</p>
                            <p class="text-xs text-green-600 mt-1">Affected HS Codes</p>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            @if($recentAnomalies->count() > 0)
            <div class="bg-white rounded-xl shadow-sm border border-red-100 p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-red-800 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                        <span>Recent Anomalies</span>
                    </h3>
                    <a href="/admin/anomalies" class="text-sm text-red-600 hover:text-red-700 font-medium">View All</a>
                </div>
                <div class="space-y-2">
                    @foreach($recentAnomalies as $anomaly)
                    <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg">
                        <div class="flex items-center space-x-3">
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $anomaly->type === 'invoice_spike' ? 'bg-orange-100 text-orange-800' : 'bg-red-100 text-red-800' }}">
                                {{ str_replace('_', ' ', ucfirst($anomaly->type)) }}
                            </span>
                            <span class="text-sm text-gray-700">{{ $anomaly->company->name ?? 'N/A' }} - {{ $anomaly->description }}</span>
                        </div>
                        <span class="text-xs text-gray-500">{{ $anomaly->created_at->diffForHumans() }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2 mb-6">
                    <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    <span>Platform Risk Intelligence</span>
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                    <div class="rounded-xl p-4 {{ $platformAuditStats['total_anomalies'] > 0 ? 'bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800' : 'bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-600' }}">
                        <p class="text-sm font-medium {{ $platformAuditStats['total_anomalies'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}">Active Anomalies</p>
                        <p class="text-3xl font-bold mt-1 {{ $platformAuditStats['total_anomalies'] > 0 ? 'text-red-700 dark:text-red-300' : 'text-gray-900 dark:text-gray-100' }}">{{ $platformAuditStats['total_anomalies'] }}</p>
                    </div>
                    <div class="rounded-xl p-4 {{ $platformAuditStats['high_risk_companies'] > 0 ? 'bg-orange-50 dark:bg-orange-900/30 border border-orange-200 dark:border-orange-800' : 'bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-600' }}">
                        <p class="text-sm font-medium {{ $platformAuditStats['high_risk_companies'] > 0 ? 'text-orange-600 dark:text-orange-400' : 'text-gray-500 dark:text-gray-400' }}">High Risk Companies</p>
                        <p class="text-3xl font-bold mt-1 {{ $platformAuditStats['high_risk_companies'] > 0 ? 'text-orange-700 dark:text-orange-300' : 'text-gray-900 dark:text-gray-100' }}">{{ $platformAuditStats['high_risk_companies'] }}</p>
                    </div>
                    <div class="rounded-xl p-4 {{ $platformAuditStats['avg_compliance'] >= 70 ? 'bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800' : ($platformAuditStats['avg_compliance'] >= 40 ? 'bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800' : 'bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800') }}">
                        <p class="text-sm font-medium {{ $platformAuditStats['avg_compliance'] >= 70 ? 'text-green-600 dark:text-green-400' : ($platformAuditStats['avg_compliance'] >= 40 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">Avg Compliance</p>
                        <p class="text-3xl font-bold mt-1 {{ $platformAuditStats['avg_compliance'] >= 70 ? 'text-green-700 dark:text-green-300' : ($platformAuditStats['avg_compliance'] >= 40 ? 'text-amber-700 dark:text-amber-300' : 'text-red-700 dark:text-red-300') }}">{{ $platformAuditStats['avg_compliance'] }}%</p>
                    </div>
                    <div class="rounded-xl p-4 {{ $platformAuditStats['total_vendor_risks'] > 0 ? 'bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800' : 'bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-600' }}">
                        <p class="text-sm font-medium {{ $platformAuditStats['total_vendor_risks'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-500 dark:text-gray-400' }}">Vendor Alerts</p>
                        <p class="text-3xl font-bold mt-1 {{ $platformAuditStats['total_vendor_risks'] > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-gray-900 dark:text-gray-100' }}">{{ $platformAuditStats['total_vendor_risks'] }}</p>
                    </div>
                </div>

                @if($atRiskCompanies->count() > 0)
                <div class="mb-6">
                    <h4 class="text-md font-semibold text-gray-700 dark:text-gray-300 mb-3">Companies at Risk</h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Company Name</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">NTN</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Compliance Score</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Risk Level</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                                @foreach($atRiskCompanies as $riskCompany)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                    <td class="px-4 py-3 text-sm">
                                        <a href="/admin/companies/{{ $riskCompany->id }}" class="font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300">{{ $riskCompany->name }}</a>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $riskCompany->ntn }}</td>
                                    <td class="px-4 py-3 text-sm font-bold text-gray-900 dark:text-gray-100">{{ $riskCompany->compliance_score }}</td>
                                    <td class="px-4 py-3">
                                        @if($riskCompany->compliance_score >= 70)
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300">LOW</span>
                                        @elseif($riskCompany->compliance_score >= 40)
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">MODERATE</span>
                                        @else
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300">HIGH</span>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif

                @if($companyScores->count() > 0)
                <div>
                    <h4 class="text-md font-semibold text-gray-700 dark:text-gray-300 mb-3">Compliance Leaderboard</h4>
                    <div class="space-y-2">
                        @foreach($companyScores as $scored)
                        <div class="flex items-center space-x-3">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300 w-40 truncate">{{ $scored->name }}</span>
                            <div class="flex-1 bg-gray-200 dark:bg-gray-600 rounded-full h-4 overflow-hidden">
                                <div class="h-4 rounded-full {{ $scored->compliance_score >= 70 ? 'bg-green-500 dark:bg-green-400' : ($scored->compliance_score >= 40 ? 'bg-amber-500 dark:bg-amber-400' : 'bg-red-500 dark:bg-red-400') }}" style="width: {{ $scored->compliance_score }}%"></div>
                            </div>
                            <span class="text-sm font-bold text-gray-900 dark:text-gray-100 w-12 text-right">{{ $scored->compliance_score }}%</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="/admin/companies" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg font-medium text-sm hover:bg-blue-700 transition">Manage Companies</a>
                <a href="/admin/users" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg font-medium text-sm hover:bg-purple-700 transition">Manage Users</a>
                <a href="/admin/fbr-logs" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg font-medium text-sm hover:bg-gray-700 transition">FBR Logs</a>
                <a href="/admin/system-health" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg font-medium text-sm hover:bg-emerald-700 transition">System Health</a>
                <a href="/admin/security-logs" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg font-medium text-sm hover:bg-red-700 transition">Security Logs</a>
                <a href="/admin/anomalies" class="inline-flex items-center px-4 py-2 bg-orange-600 text-white rounded-lg font-medium text-sm hover:bg-orange-700 transition">Anomalies</a>
                <a href="/admin/risk-settings" class="inline-flex items-center px-4 py-2 bg-teal-600 text-white rounded-lg font-medium text-sm hover:bg-teal-700 transition">Risk Settings</a>
                <a href="/admin/override-logs" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg font-medium text-sm hover:bg-amber-700 transition">Override Logs</a>
                <a href="/tax-overrides" class="inline-flex items-center px-4 py-2 bg-cyan-600 text-white rounded-lg font-medium text-sm hover:bg-cyan-700 transition">Tax Override Rules</a>
                <a href="/admin/audit/export" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg font-medium text-sm hover:bg-indigo-700 transition">Export Audit CSV</a>
            </div>
        </div>
    </div>
</x-app-layout>
