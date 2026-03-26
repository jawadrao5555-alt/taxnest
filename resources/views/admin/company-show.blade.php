<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <a href="/admin/companies" class="text-gray-400 hover:text-gray-600 dark:text-gray-400 transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ $company->name }}</h2>
                <span class="text-xs font-medium text-gray-400">VIEW ONLY</span>
            </div>
        </div>
    </x-slot>

    <div class="py-8" x-data="{ activeTab: 'overview' }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700">{{ session('success') }}</div>
            @endif

            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-6 mb-8">
                @if($company->company_status === 'pending')
                <div class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg flex items-center justify-between">
                    <span class="text-sm text-amber-700 font-medium">This company is pending approval</span>
                    <div class="flex items-center gap-2">
                        <form method="POST" action="/admin/company/{{ $company->id }}/approve">@csrf<button type="submit" class="text-xs px-3 py-1 bg-green-600 text-white rounded-lg font-medium hover:bg-green-700">Approve</button></form>
                        <form method="POST" action="/admin/company/{{ $company->id }}/reject" onsubmit="return confirm('Are you sure you want to reject this company?')">@csrf<button type="submit" class="text-xs px-3 py-1 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700">Reject</button></form>
                    </div>
                </div>
                @elseif($company->company_status === 'suspended')
                <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg flex items-center justify-between">
                    <span class="text-sm text-red-700 font-medium">This company is suspended{{ $company->suspended_at ? ' (since ' . $company->suspended_at->format('d M Y') . ')' : '' }}</span>
                    <form method="POST" action="/admin/company/{{ $company->id }}/suspend">@csrf<button type="submit" class="text-xs px-3 py-1 bg-green-600 text-white rounded-lg font-medium hover:bg-green-700">Unsuspend</button></form>
                </div>
                @elseif($company->company_status === 'rejected')
                <div class="mb-4 p-3 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg flex items-center justify-between">
                    <span class="text-sm text-gray-700 dark:text-gray-300 font-medium">This company registration was rejected</span>
                    <form method="POST" action="/admin/company/{{ $company->id }}/approve">@csrf<button type="submit" class="text-xs px-3 py-1 bg-green-600 text-white rounded-lg font-medium hover:bg-green-700">Approve</button></form>
                </div>
                @endif

                <div class="mb-4 p-3 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg flex items-center justify-between">
                    <div>
                        <span class="text-sm text-gray-700 dark:text-gray-300 font-medium">Invoice Watermark</span>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $company->force_watermark ? 'Watermark is enabled on invoices' : 'No watermark on invoices' }}</p>
                    </div>
                    <form method="POST" action="/admin/company/{{ $company->id }}/toggle-watermark">
                        @csrf
                        <button type="submit" class="text-xs px-3 py-1 {{ $company->force_watermark ? 'bg-red-600 hover:bg-red-700' : 'bg-gray-600 hover:bg-gray-700' }} text-white rounded-lg font-medium transition">
                            {{ $company->force_watermark ? 'Remove Watermark' : 'Add Watermark' }}
                        </button>
                    </form>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-5 gap-6">
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Company Name</p>
                        <p class="text-sm font-bold text-gray-900 mt-1">{{ $company->name }}</p>
                        @if($company->owner_name)
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Owner: {{ $company->owner_name }}</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">NTN / CNIC</p>
                        <p class="text-sm font-mono font-bold text-gray-900 mt-1">{{ $company->ntn }}</p>
                        @if($company->cnic && $company->cnic !== $company->ntn)
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">CNIC: {{ $company->cnic }}</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</p>
                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium mt-1
                            @if($company->company_status === 'active') bg-green-100 text-green-800
                            @elseif($company->company_status === 'pending') bg-amber-100 text-amber-800
                            @elseif($company->company_status === 'suspended') bg-red-100 text-red-800
                            @else bg-gray-100 text-gray-800 dark:text-gray-100
                            @endif">{{ ucfirst($company->company_status ?? 'unknown') }}</span>
                        @if($company->is_internal_account)
<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800 ml-1">Internal</span>
@endif
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Active Plan</p>
                        <p class="text-sm font-bold text-gray-900 mt-1">{{ $activePlan ?? 'None' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Compliance Score</p>
                        <p class="text-sm font-bold mt-1 {{ ($company->compliance_score ?? 0) >= 70 ? 'text-emerald-600' : (($company->compliance_score ?? 0) >= 40 ? 'text-orange-600' : 'text-red-600') }}">{{ $company->compliance_score ?? 'N/A' }}</p>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-gray-100 dark:border-gray-800 flex flex-wrap items-center gap-3">
                    @if($company->company_status === 'active')
                    <form method="POST" action="/admin/company/{{ $company->id }}/suspend" onsubmit="return confirm('Are you sure you want to suspend this company?')">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white text-sm rounded-lg font-medium hover:bg-red-700 transition">Suspend Company</button>
                    </form>
                    @endif

                    <div x-data="{ showPlan: false }" class="inline-flex items-center gap-2">
                        <button @click="showPlan = !showPlan" class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg font-medium hover:bg-blue-700 transition">Change Plan</button>
                        <form x-show="showPlan" method="POST" action="/admin/company/{{ $company->id }}/change-plan" class="inline-flex items-center gap-2">
                            @csrf
                            <select name="pricing_plan_id" class="text-sm rounded-lg border-gray-300">
                                @foreach($plans as $plan)
                                <option value="{{ $plan->id }}">{{ $plan->name }} (PKR {{ number_format($plan->price) }})</option>
                                @endforeach
                            </select>
                            <button type="submit" class="px-3 py-2 bg-emerald-600 text-white text-sm rounded-lg font-medium hover:bg-emerald-700">Apply</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="mb-6 flex space-x-1 bg-gray-100 rounded-xl p-1">
                <button @click="activeTab = 'overview'" :class="activeTab === 'overview' ? 'bg-white dark:bg-gray-900 shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700 dark:text-gray-300'" class="flex-1 px-4 py-2.5 rounded-lg text-sm font-semibold transition">Overview</button>
                <button @click="activeTab = 'financial'" :class="activeTab === 'financial' ? 'bg-white dark:bg-gray-900 shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700 dark:text-gray-300'" class="flex-1 px-4 py-2.5 rounded-lg text-sm font-semibold transition">Financial</button>
                <button @click="activeTab = 'compliance'" :class="activeTab === 'compliance' ? 'bg-white dark:bg-gray-900 shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700 dark:text-gray-300'" class="flex-1 px-4 py-2.5 rounded-lg text-sm font-semibold transition">Compliance</button>
                <button @click="activeTab = 'activity'" :class="activeTab === 'activity' ? 'bg-white dark:bg-gray-900 shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700 dark:text-gray-300'" class="flex-1 px-4 py-2.5 rounded-lg text-sm font-semibold transition">Activity</button>
                <button @click="activeTab = 'settings'" :class="activeTab === 'settings' ? 'bg-white dark:bg-gray-900 shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700 dark:text-gray-300'" class="flex-1 px-4 py-2.5 rounded-lg text-sm font-semibold transition">Settings</button>
            </div>

            <div x-show="activeTab === 'overview'" class="space-y-6">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-4 text-center">
                        <p class="text-2xl font-extrabold text-blue-600">{{ $activePlan ?? 'None' }}</p>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1">Plan</p>
                    </div>
                    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-4 text-center">
                        <p class="text-2xl font-extrabold text-gray-900">{{ $stats['total_users'] }}</p>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1">Users</p>
                    </div>
                    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-4 text-center">
                        <p class="text-2xl font-extrabold text-gray-900">{{ $stats['total_branches'] }}</p>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1">Branches</p>
                    </div>
                    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-4 text-center">
                        <p class="text-2xl font-extrabold text-gray-900">{{ $stats['total_invoices'] }}</p>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1">Total Invoices</p>
                    </div>
                    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-4 text-center">
                        <p class="text-2xl font-extrabold text-emerald-600">{{ $stats['locked'] }}</p>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1">Locked</p>
                    </div>
                    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-4 text-center">
                        <p class="text-2xl font-extrabold text-red-600">{{ $stats['failed'] }}</p>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1">Failed</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-4">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Draft Invoices</p>
                        <p class="text-3xl font-extrabold text-gray-500 dark:text-gray-400">{{ $stats['draft'] }}</p>
                    </div>
                    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-4">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">FBR Environment</p>
                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $company->fbr_environment === 'production' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800' }}">{{ ucfirst($company->fbr_environment ?? 'sandbox') }}</span>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
                        <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">Users ({{ $stats['total_users'] }})</h4>
                    </div>
                    <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Role</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Invoices</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse($users as $user)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 dark:bg-gray-800">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $user->name }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">{{ $user->email }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold
                                        @if($user->role === 'company_admin') bg-blue-100 text-blue-800
                                        @elseif($user->role === 'employee') bg-gray-100 text-gray-700 dark:text-gray-300
                                        @else bg-purple-100 text-purple-800
                                        @endif">{{ str_replace('_', ' ', ucfirst($user->role)) }}</span>
                                </td>
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900 text-right">{{ $user->user_invoice_count }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="4" class="px-6 py-8 text-center text-gray-400">No users found</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <div x-show="activeTab === 'financial'" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-6">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Total Invoiced Amount</p>
                        <p class="text-3xl font-extrabold text-emerald-600">PKR {{ number_format($financial['total_invoiced'], 2) }}</p>
                    </div>
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-6">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Total Tax Collected</p>
                        <p class="text-3xl font-extrabold text-orange-600">PKR {{ number_format($financial['total_tax'], 2) }}</p>
                        @if($financial['tax_rate_summary']->count() > 0)
                        <div class="mt-3 space-y-1">
                            @foreach($financial['tax_rate_summary'] as $item)
                            <div class="flex justify-between text-xs">
                                <span class="text-gray-500 dark:text-gray-400">Rate {{ $item->tax_rate }}%</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $item->count }} items (PKR {{ number_format($item->total_tax) }})</span>
                            </div>
                            @endforeach
                        </div>
                        @else
                        <p class="text-xs text-gray-400 mt-2">No tax data recorded</p>
                        @endif
                    </div>
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-6">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Outstanding Balance</p>
                        <p class="text-3xl font-extrabold {{ $financial['outstanding'] > 0 ? 'text-red-600' : 'text-gray-900' }}">PKR {{ number_format($financial['outstanding'], 2) }}</p>
                        <p class="text-xs text-gray-400 mt-2">Sum of last customer ledger balances</p>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
                        <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">Monthly Revenue (Last 6 Months)</h4>
                    </div>
                    <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Month</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Invoices</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Revenue</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($financial['monthly_revenue'] as $mr)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 dark:bg-gray-800">
                                <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ $mr['month'] }}</td>
                                <td class="px-6 py-3 text-sm text-gray-700 dark:text-gray-300 text-right">{{ $mr['count'] }}</td>
                                <td class="px-6 py-3 text-sm font-semibold text-gray-900 text-right">PKR {{ number_format($mr['revenue'], 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <div x-show="activeTab === 'compliance'" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-6">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Avg Compliance Score</p>
                        <p class="text-3xl font-extrabold {{ ($compliance['avg_score'] ?? 0) >= 70 ? 'text-emerald-600' : (($compliance['avg_score'] ?? 0) >= 40 ? 'text-orange-600' : 'text-red-600') }}">{{ number_format($compliance['avg_score'] ?? 0, 1) }}</p>
                    </div>
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-6">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Audit Probability</p>
                        <p class="text-3xl font-extrabold text-gray-900">{{ number_format($compliance['audit_probability'] ?? 0, 1) }}%</p>
                    </div>
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-6">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Total Reports</p>
                        <p class="text-3xl font-extrabold text-gray-900">{{ $compliance['total_reports'] ?? 0 }}</p>
                    </div>
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-6">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Failed Submissions</p>
                        <p class="text-3xl font-extrabold text-red-600">{{ $compliance['failed_submissions'] ?? 0 }}</p>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-6">
                    <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100 mb-4">Risk Distribution</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        @foreach($compliance['risk_distribution'] ?? [] as $level => $count)
                        <div class="rounded-xl p-4 text-center
                            @if($level === 'LOW') bg-green-50 border border-green-200
                            @elseif($level === 'MEDIUM') bg-yellow-50 border border-yellow-200
                            @elseif($level === 'HIGH') bg-orange-50 border border-orange-200
                            @else bg-red-50 border border-red-200
                            @endif">
                            <p class="text-2xl font-extrabold
                                @if($level === 'LOW') text-green-600
                                @elseif($level === 'MEDIUM') text-yellow-600
                                @elseif($level === 'HIGH') text-orange-600
                                @else text-red-600
                                @endif">{{ $count }}</p>
                            <p class="text-xs font-bold mt-1
                                @if($level === 'LOW') text-green-700
                                @elseif($level === 'MEDIUM') text-yellow-700
                                @elseif($level === 'HIGH') text-orange-700
                                @else text-red-700
                                @endif">{{ $level }}</p>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div x-show="activeTab === 'activity'" class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Action</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Invoice</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">IP Address</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($activityLogs as $log)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 dark:bg-gray-800">
                            <td class="px-6 py-4">
                                <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold
                                    @if($log->action === 'locked') bg-green-100 text-green-800
                                    @elseif($log->action === 'override') bg-orange-100 text-orange-800
                                    @else bg-gray-100 text-gray-700 dark:text-gray-300
                                    @endif">{{ ucfirst($log->action) }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-700 dark:text-gray-300">{{ $log->invoice->invoice_number ?? 'INV-'.$log->invoice_id }}</td>
                            <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{{ $log->user->name ?? 'System' }}</td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-500 dark:text-gray-400">{{ $log->ip_address ?? '-' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400 text-right">{{ $log->created_at->format('d M Y H:i') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="px-6 py-8 text-center text-gray-400">No activity logs found</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div x-show="activeTab === 'settings'" class="space-y-6">
                <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-6">
                    <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100 mb-4">Limit Overrides</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Override the subscription plan limits for this company. Leave empty to use the plan default. Set to <strong>-1</strong> for unlimited.</p>

                    <form method="POST" action="/admin/company/{{ $company->id }}/update-limits" class="space-y-4">
                        @csrf
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider mb-1">Invoice Limit</label>
                                <div class="flex items-center gap-2">
                                    <input type="number" name="invoice_limit_override" value="{{ $company->invoice_limit_override }}" placeholder="Plan default" min="-1" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                                    @if($company->invoice_limit_override === -1)
                                    <span class="px-2 py-1 bg-emerald-100 text-emerald-800 text-xs font-bold rounded-full whitespace-nowrap">Unlimited</span>
                                    @elseif($company->invoice_limit_override !== null)
                                    <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs font-bold rounded-full whitespace-nowrap">Override</span>
                                    @else
                                    <span class="px-2 py-1 bg-gray-100 text-gray-500 dark:text-gray-400 text-xs font-bold rounded-full whitespace-nowrap">Plan</span>
                                    @endif
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider mb-1">User Limit</label>
                                <div class="flex items-center gap-2">
                                    <input type="number" name="user_limit_override" value="{{ $company->user_limit_override }}" placeholder="Plan default" min="-1" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                                    @if($company->user_limit_override === -1)
                                    <span class="px-2 py-1 bg-emerald-100 text-emerald-800 text-xs font-bold rounded-full whitespace-nowrap">Unlimited</span>
                                    @elseif($company->user_limit_override !== null)
                                    <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs font-bold rounded-full whitespace-nowrap">Override</span>
                                    @else
                                    <span class="px-2 py-1 bg-gray-100 text-gray-500 dark:text-gray-400 text-xs font-bold rounded-full whitespace-nowrap">Plan</span>
                                    @endif
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider mb-1">Branch Limit</label>
                                <div class="flex items-center gap-2">
                                    <input type="number" name="branch_limit_override" value="{{ $company->branch_limit_override }}" placeholder="Plan default" min="-1" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                                    @if($company->branch_limit_override === -1)
                                    <span class="px-2 py-1 bg-emerald-100 text-emerald-800 text-xs font-bold rounded-full whitespace-nowrap">Unlimited</span>
                                    @elseif($company->branch_limit_override !== null)
                                    <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs font-bold rounded-full whitespace-nowrap">Override</span>
                                    @else
                                    <span class="px-2 py-1 bg-gray-100 text-gray-500 dark:text-gray-400 text-xs font-bold rounded-full whitespace-nowrap">Plan</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
                            <p class="text-xs text-amber-700"><strong>Note:</strong> Empty = use plan default | <strong>-1</strong> = unlimited | Any positive number = custom limit</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg font-medium hover:bg-emerald-700 transition">Save Limits</button>
                            <button type="button" onclick="if(confirm('Reset all limits to plan defaults?')){document.getElementById('resetLimitsForm').submit()}" class="px-4 py-2 bg-gray-200 text-gray-700 dark:text-gray-300 text-sm rounded-lg font-medium hover:bg-gray-300 transition">Reset to Plan Defaults</button>
                        </div>
                    </form>
                    <form id="resetLimitsForm" method="POST" action="/admin/company/{{ $company->id }}/reset-limits" class="hidden">@csrf</form>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 p-6">
                    <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100 mb-4">Internal Account</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Internal accounts bypass invoice limits, subscription enforcement, and payment requirements. Audit logs and compliance engine remain active.</p>
                    <div class="flex items-center justify-between p-4 rounded-lg {{ $company->is_internal_account ? 'bg-emerald-50 border border-emerald-200' : 'bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700' }}">
                        <div class="flex items-center space-x-3">
                            <span class="inline-block w-3 h-3 rounded-full {{ $company->is_internal_account ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                            <span class="text-sm font-semibold {{ $company->is_internal_account ? 'text-emerald-800' : 'text-gray-700 dark:text-gray-300' }}">{{ $company->is_internal_account ? 'Internal Account Active' : 'Standard Account' }}</span>
                        </div>
                        <form method="POST" action="/admin/company/{{ $company->id }}/toggle-internal" onsubmit="return confirm('{{ $company->is_internal_account ? 'Remove internal account status? This will re-enable subscription enforcement.' : 'Enable internal account? This will bypass all subscription/billing limits.' }}')">
                            @csrf
                            <button type="submit" class="px-4 py-2 {{ $company->is_internal_account ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700' }} text-white text-sm rounded-lg font-medium transition">
                                {{ $company->is_internal_account ? 'Disable Internal' : 'Enable Internal' }}
                            </button>
                        </form>
                    </div>

                    <div class="flex items-center justify-between p-4 rounded-lg {{ $company->inventory_enabled ? 'bg-cyan-50 border border-cyan-200' : 'bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700' }}">
                        <div class="flex items-center space-x-3">
                            <span class="inline-block w-3 h-3 rounded-full {{ $company->inventory_enabled ? 'bg-cyan-500' : 'bg-gray-400' }}"></span>
                            <span class="text-sm font-semibold {{ $company->inventory_enabled ? 'text-cyan-800' : 'text-gray-700 dark:text-gray-300' }}">{{ $company->inventory_enabled ? 'Inventory Module Active' : 'Inventory Module Disabled' }}</span>
                        </div>
                        <form method="POST" action="/admin/company/{{ $company->id }}/toggle-inventory" onsubmit="return confirm('{{ $company->inventory_enabled ? 'Disable inventory module for this company?' : 'Enable inventory module? This will allow stock tracking, suppliers, and purchase orders.' }}')">
                            @csrf
                            <button type="submit" class="px-4 py-2 {{ $company->inventory_enabled ? 'bg-red-600 hover:bg-red-700' : 'bg-cyan-600 hover:bg-cyan-700' }} text-white text-sm rounded-lg font-medium transition">
                                {{ $company->inventory_enabled ? 'Disable Inventory' : 'Enable Inventory' }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-admin-layout>
