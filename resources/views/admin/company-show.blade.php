<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <a href="/admin/companies" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <h2 class="font-bold text-xl text-gray-800 leading-tight">{{ $company->name }}</h2>
            </div>
        </div>
    </x-slot>

    <div class="py-8" x-data="{ activeTab: 'users' }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-8">
                <div class="grid grid-cols-2 md:grid-cols-5 gap-6">
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Company Name</p>
                        <p class="text-sm font-bold text-gray-900 mt-1">{{ $company->name }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">NTN</p>
                        <p class="text-sm font-mono font-bold text-gray-900 mt-1">{{ $company->ntn }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">FBR Environment</p>
                        <p class="text-sm font-bold mt-1 {{ $company->fbr_token ? 'text-emerald-600' : 'text-gray-400' }}">{{ $company->fbr_token ? 'Connected' : 'Not Connected' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Active Plan</p>
                        <p class="text-sm font-bold text-gray-900 mt-1">{{ $activePlan ?? 'None' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Compliance Score</p>
                        <p class="text-sm font-bold mt-1 {{ ($company->compliance_score ?? 0) >= 70 ? 'text-emerald-600' : (($company->compliance_score ?? 0) >= 40 ? 'text-orange-600' : 'text-red-600') }}">{{ $company->compliance_score ?? 'N/A' }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-5 gap-6 mt-6 pt-6 border-t border-gray-100">
                    <div class="bg-gray-50 rounded-xl p-4 text-center">
                        <p class="text-2xl font-extrabold text-gray-900">{{ $stats['total_users'] }}</p>
                        <p class="text-xs font-medium text-gray-500 mt-1">Total Users</p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-4 text-center">
                        <p class="text-2xl font-extrabold text-gray-900">{{ $stats['total_invoices'] }}</p>
                        <p class="text-xs font-medium text-gray-500 mt-1">Total Invoices</p>
                    </div>
                    <div class="bg-emerald-50 rounded-xl p-4 text-center">
                        <p class="text-2xl font-extrabold text-emerald-600">{{ $stats['locked'] }}</p>
                        <p class="text-xs font-medium text-gray-500 mt-1">Locked</p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-4 text-center">
                        <p class="text-2xl font-extrabold text-gray-500">{{ $stats['draft'] }}</p>
                        <p class="text-xs font-medium text-gray-500 mt-1">Draft</p>
                    </div>
                    <div class="bg-red-50 rounded-xl p-4 text-center">
                        <p class="text-2xl font-extrabold text-red-600">{{ $stats['failed'] }}</p>
                        <p class="text-xs font-medium text-gray-500 mt-1">Failed</p>
                    </div>
                </div>
            </div>

            <div class="mb-6 flex space-x-1 bg-gray-100 rounded-xl p-1">
                <button @click="activeTab = 'users'" :class="activeTab === 'users' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'" class="flex-1 px-4 py-2.5 rounded-lg text-sm font-semibold transition">Users</button>
                <button @click="activeTab = 'invoices'" :class="activeTab === 'invoices' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'" class="flex-1 px-4 py-2.5 rounded-lg text-sm font-semibold transition">Invoices</button>
                <button @click="activeTab = 'compliance'" :class="activeTab === 'compliance' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'" class="flex-1 px-4 py-2.5 rounded-lg text-sm font-semibold transition">Compliance</button>
                <button @click="activeTab = 'activity'" :class="activeTab === 'activity' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'" class="flex-1 px-4 py-2.5 rounded-lg text-sm font-semibold transition">Activity Logs</button>
            </div>

            <div x-show="activeTab === 'users'" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Invoices</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($users as $user)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $user->name }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $user->email }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold
                                    @if($user->role === 'company_admin') bg-blue-100 text-blue-800
                                    @elseif($user->role === 'employee') bg-gray-100 text-gray-700
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

            <div x-show="activeTab === 'invoices'" x-data="{ filter: 'all' }" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center space-x-2">
                    <button @click="filter = 'all'" :class="filter === 'all' ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600'" class="px-3 py-1.5 rounded-lg text-xs font-bold transition">All ({{ $stats['total_invoices'] }})</button>
                    <button @click="filter = 'draft'" :class="filter === 'draft' ? 'bg-gray-200 text-gray-800' : 'bg-gray-100 text-gray-600'" class="px-3 py-1.5 rounded-lg text-xs font-bold transition">Draft ({{ $stats['draft'] }})</button>
                    <button @click="filter = 'locked'" :class="filter === 'locked' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'" class="px-3 py-1.5 rounded-lg text-xs font-bold transition">Locked ({{ $stats['locked'] }})</button>
                    <button @click="filter = 'failed'" :class="filter === 'failed' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-600'" class="px-3 py-1.5 rounded-lg text-xs font-bold transition">Failed ({{ $stats['failed'] }})</button>
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Buyer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($invoices as $inv)
                        <tr class="hover:bg-gray-50" x-show="filter === 'all' || filter === '{{ $inv->status }}' || (filter === 'failed' && '{{ $inv->status }}' === 'failed')">
                            <td class="px-6 py-4 text-sm font-mono font-medium text-gray-900">{{ $inv->invoice_number ?? 'INV-'.$inv->id }}</td>
                            <td class="px-6 py-4 text-sm text-gray-700">{{ $inv->buyer_name }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold
                                    @if($inv->status === 'draft') bg-gray-200 text-gray-700
                                    @elseif($inv->status === 'locked') bg-green-100 text-green-800
                                    @elseif($inv->status === 'submitted') bg-blue-100 text-blue-800
                                    @else bg-red-100 text-red-800
                                    @endif">{{ ucfirst($inv->status) }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm font-semibold text-gray-900 text-right">Rs. {{ number_format($inv->total_amount, 2) }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500 text-right">{{ $inv->created_at->format('d M Y') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="px-6 py-8 text-center text-gray-400">No invoices found</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div x-show="activeTab === 'compliance'" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Avg Compliance Score</p>
                        <p class="text-3xl font-extrabold {{ ($compliance['avg_score'] ?? 0) >= 70 ? 'text-emerald-600' : (($compliance['avg_score'] ?? 0) >= 40 ? 'text-orange-600' : 'text-red-600') }}">{{ number_format($compliance['avg_score'] ?? 0, 1) }}</p>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Audit Probability</p>
                        <p class="text-3xl font-extrabold text-gray-900">{{ number_format($compliance['audit_probability'] ?? 0, 1) }}%</p>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Total Reports</p>
                        <p class="text-3xl font-extrabold text-gray-900">{{ $compliance['total_reports'] ?? 0 }}</p>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h4 class="text-sm font-bold text-gray-800 mb-4">Risk Distribution</h4>
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

            <div x-show="activeTab === 'activity'" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP Address</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($activityLogs as $log)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold
                                    @if(in_array($log->action, ['locked', 'submitted'])) bg-green-100 text-green-800
                                    @elseif($log->action === 'override') bg-orange-100 text-orange-800
                                    @else bg-gray-100 text-gray-700
                                    @endif">{{ ucfirst($log->action) }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-700">{{ $log->invoice->invoice_number ?? 'INV-'.$log->invoice_id }}</td>
                            <td class="px-6 py-4 text-sm text-gray-700">{{ $log->user->name ?? 'System' }}</td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-500">{{ $log->ip_address ?? '-' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500 text-right">{{ $log->created_at->format('d M Y H:i') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="px-6 py-8 text-center text-gray-400">No activity logs found</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</x-app-layout>
