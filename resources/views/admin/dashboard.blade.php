<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 leading-tight">Super Admin Dashboard</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
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

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
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

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
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
                        <span class="text-blue-600">{{ $submittedInvoices }} submitted</span>
                        <span class="text-gray-300">|</span>
                        <span class="text-green-600">{{ $lockedInvoices }} locked</span>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
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
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Recent Companies</h3>
                        <a href="/admin/companies" class="text-sm text-emerald-600 hover:text-emerald-700 font-medium">View All</a>
                    </div>
                    <div class="p-6">
                        @forelse($recentCompanies as $company)
                        <div class="flex items-center justify-between py-3 {{ !$loop->last ? 'border-b border-gray-100' : '' }}">
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

                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100">
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
                                            @elseif($invoice->status === 'submitted') bg-blue-100 text-blue-800
                                            @elseif($invoice->status === 'locked') bg-green-100 text-green-800
                                            @endif">{{ ucfirst($invoice->status) }}</span>
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

            <div class="flex flex-wrap gap-3">
                <a href="/admin/companies" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg font-medium text-sm hover:bg-blue-700 transition">Manage Companies</a>
                <a href="/admin/users" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg font-medium text-sm hover:bg-purple-700 transition">Manage Users</a>
                <a href="/admin/fbr-logs" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg font-medium text-sm hover:bg-gray-700 transition">FBR Logs</a>
                <a href="/admin/system-health" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg font-medium text-sm hover:bg-emerald-700 transition">System Health</a>
                <a href="/admin/security-logs" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg font-medium text-sm hover:bg-red-700 transition">Security Logs</a>
                <a href="/admin/anomalies" class="inline-flex items-center px-4 py-2 bg-orange-600 text-white rounded-lg font-medium text-sm hover:bg-orange-700 transition">Anomalies</a>
                <a href="/admin/audit/export" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg font-medium text-sm hover:bg-indigo-700 transition">Export Audit CSV</a>
            </div>
        </div>
    </div>
</x-app-layout>
