<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto" x-data="{ activeTab: 'di' }">
    <h1 class="text-2xl font-bold text-white mb-6">Admin Dashboard</h1>

    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3 mb-8">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Total Companies</p>
            <p class="text-xl font-bold text-white">{{ $stats['total_companies'] }}</p>
        </div>
        <div class="bg-gray-900 border border-emerald-900/50 rounded-xl p-4">
            <p class="text-[10px] text-emerald-500 uppercase tracking-wide mb-1">DI Companies</p>
            <p class="text-xl font-bold text-emerald-400">{{ $stats['di_companies'] }}</p>
        </div>
        <div class="bg-gray-900 border border-purple-900/50 rounded-xl p-4">
            <p class="text-[10px] text-purple-500 uppercase tracking-wide mb-1">PRA POS</p>
            <p class="text-xl font-bold text-purple-400">{{ $stats['pos_companies'] }}</p>
        </div>
        <div class="bg-gray-900 border border-blue-900/50 rounded-xl p-4">
            <p class="text-[10px] text-blue-500 uppercase tracking-wide mb-1">FBR POS</p>
            <p class="text-xl font-bold text-blue-400">{{ $stats['fbrpos_companies'] }}</p>
        </div>
        <div class="bg-gray-900 border border-amber-900/50 rounded-xl p-4">
            <p class="text-[10px] text-amber-500 uppercase tracking-wide mb-1">Pending</p>
            <p class="text-xl font-bold {{ $stats['pending_companies'] > 0 ? 'text-amber-400' : 'text-gray-500 dark:text-gray-400' }}">{{ $stats['pending_companies'] }}</p>
        </div>
        <div class="bg-gray-900 border border-red-900/50 rounded-xl p-4">
            <p class="text-[10px] text-red-500 uppercase tracking-wide mb-1">Suspended</p>
            <p class="text-xl font-bold {{ $stats['suspended_companies'] > 0 ? 'text-red-400' : 'text-gray-500 dark:text-gray-400' }}">{{ $stats['suspended_companies'] }}</p>
        </div>
        <div class="bg-gray-900 border border-gray-700 rounded-xl p-4">
            <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">In Bin</p>
            <p class="text-xl font-bold {{ $stats['binned_companies'] > 0 ? 'text-gray-300' : 'text-gray-500 dark:text-gray-400' }}">{{ $stats['binned_companies'] }}</p>
            @if($stats['binned_companies'] > 0)
            <a href="{{ route('saas.admin.companies.bin') }}" class="text-[10px] text-indigo-400 hover:underline">View Bin</a>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 mb-8">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">DI Invoices</p>
            <p class="text-xl font-bold text-emerald-400">{{ number_format($stats['di_invoices']) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">PKR {{ number_format($stats['di_revenue'], 0) }} revenue</p>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">PRA POS Transactions</p>
            <p class="text-xl font-bold text-purple-400">{{ number_format($stats['pos_transactions']) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">PKR {{ number_format($stats['pos_revenue'], 0) }} revenue</p>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">FBR POS Transactions</p>
            <p class="text-xl font-bold text-blue-400">{{ number_format($stats['fbrpos_transactions']) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">PKR {{ number_format($stats['fbrpos_revenue'], 0) }} revenue</p>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Active Subscriptions</p>
            <p class="text-xl font-bold text-indigo-400">{{ $stats['active_subscriptions'] }}</p>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Total Users</p>
            <p class="text-xl font-bold text-white">{{ $stats['total_users'] }}</p>
        </div>
    </div>

    <div class="mb-8">
        <div class="flex border-b border-gray-800 mb-0">
            <button @click="activeTab = 'di'" :class="activeTab === 'di' ? 'border-emerald-500 text-emerald-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-300'" class="flex items-center gap-2 px-5 py-3 text-sm font-semibold border-b-2 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Digital Invoice ({{ $diCompaniesList->count() }})
            </button>
            <button @click="activeTab = 'pos'" :class="activeTab === 'pos' ? 'border-purple-500 text-purple-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-300'" class="flex items-center gap-2 px-5 py-3 text-sm font-semibold border-b-2 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                PRA POS ({{ $posCompaniesList->count() }})
            </button>
            <button @click="activeTab = 'fbrpos'" :class="activeTab === 'fbrpos' ? 'border-blue-500 text-blue-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-300'" class="flex items-center gap-2 px-5 py-3 text-sm font-semibold border-b-2 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                FBR POS ({{ $fbrposCompaniesList->count() }})
            </button>
        </div>

        <div x-show="activeTab === 'di'" class="bg-gray-900 border border-gray-800 border-t-0 rounded-b-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[10px] text-gray-500 dark:text-gray-400 uppercase border-b border-gray-800 bg-gray-800/30">
                            <th class="px-4 py-3">Company</th>
                            <th class="px-4 py-3 hidden sm:table-cell">NTN</th>
                            <th class="px-4 py-3 hidden md:table-cell">Owner</th>
                            <th class="px-4 py-3 text-center hidden sm:table-cell">Users</th>
                            <th class="px-4 py-3 text-center hidden md:table-cell">Invoices</th>
                            <th class="px-4 py-3 text-right hidden lg:table-cell">Revenue</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800/50">
                        @forelse($diCompaniesList as $company)
                        @php $sc = ['approved' => 'bg-emerald-900/30 text-emerald-400', 'pending' => 'bg-amber-900/30 text-amber-400', 'suspended' => 'bg-red-900/30 text-red-400', 'rejected' => 'bg-gray-800 text-gray-400']; @endphp
                        <tr class="hover:bg-gray-800/30">
                            <td class="px-4 py-3">
                                <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="text-white font-medium hover:text-emerald-400 transition">{{ $company->name }}</a>
                                <p class="text-[10px] text-gray-600 dark:text-gray-400">{{ $company->email ?? '' }}</p>
                            </td>
                            <td class="px-4 py-3 text-gray-400 text-xs hidden sm:table-cell">{{ $company->ntn ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-400 text-xs hidden md:table-cell">{{ $company->owner_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-center text-gray-300 hidden sm:table-cell">{{ $company->users_count }}</td>
                            <td class="px-4 py-3 text-center text-gray-300 hidden md:table-cell">{{ $company->invoices_count }}</td>
                            <td class="px-4 py-3 text-right text-emerald-400 text-xs hidden lg:table-cell">PKR {{ number_format($company->di_revenue, 0) }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium {{ $sc[$company->status] ?? 'bg-gray-800 text-gray-400' }}">{{ $company->status }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="px-2 py-1 bg-indigo-600/20 text-indigo-400 text-[10px] rounded hover:bg-indigo-600/40 transition">Manage</a>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No Digital Invoice companies found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div x-show="activeTab === 'pos'" x-cloak class="bg-gray-900 border border-gray-800 border-t-0 rounded-b-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[10px] text-gray-500 dark:text-gray-400 uppercase border-b border-gray-800 bg-gray-800/30">
                            <th class="px-4 py-3">Company</th>
                            <th class="px-4 py-3 hidden sm:table-cell">POS ID</th>
                            <th class="px-4 py-3 hidden md:table-cell">Owner</th>
                            <th class="px-4 py-3 text-center hidden sm:table-cell">Users</th>
                            <th class="px-4 py-3 text-center hidden md:table-cell">Transactions</th>
                            <th class="px-4 py-3 text-right hidden lg:table-cell">Revenue</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800/50">
                        @forelse($posCompaniesList as $company)
                        @php $sc = ['approved' => 'bg-emerald-900/30 text-emerald-400', 'active' => 'bg-emerald-900/30 text-emerald-400', 'pending' => 'bg-amber-900/30 text-amber-400', 'suspended' => 'bg-red-900/30 text-red-400', 'rejected' => 'bg-gray-800 text-gray-400']; @endphp
                        <tr class="hover:bg-gray-800/30">
                            <td class="px-4 py-3">
                                <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="text-white font-medium hover:text-purple-400 transition">{{ $company->name }}</a>
                                <p class="text-[10px] text-gray-600 dark:text-gray-400">{{ $company->email ?? '' }}</p>
                            </td>
                            <td class="px-4 py-3 text-gray-400 text-xs hidden sm:table-cell">{{ $company->pra_pos_id ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-400 text-xs hidden md:table-cell">{{ $company->owner_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-center text-gray-300 hidden sm:table-cell">{{ $company->users_count }}</td>
                            <td class="px-4 py-3 text-center text-gray-300 hidden md:table-cell">{{ number_format($company->pos_transaction_count) }}</td>
                            <td class="px-4 py-3 text-right text-purple-400 text-xs hidden lg:table-cell">PKR {{ number_format($company->pos_revenue, 0) }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium {{ $sc[$company->status] ?? 'bg-gray-800 text-gray-400' }}">{{ $company->status }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="px-2 py-1 bg-purple-600/20 text-purple-400 text-[10px] rounded hover:bg-purple-600/40 transition">Manage</a>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No PRA POS companies found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div x-show="activeTab === 'fbrpos'" x-cloak class="bg-gray-900 border border-gray-800 border-t-0 rounded-b-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[10px] text-gray-500 dark:text-gray-400 uppercase border-b border-gray-800 bg-gray-800/30">
                            <th class="px-4 py-3">Company</th>
                            <th class="px-4 py-3 hidden sm:table-cell">NTN</th>
                            <th class="px-4 py-3 hidden md:table-cell">Owner</th>
                            <th class="px-4 py-3 text-center hidden sm:table-cell">Users</th>
                            <th class="px-4 py-3 text-center hidden md:table-cell">Transactions</th>
                            <th class="px-4 py-3 text-right hidden lg:table-cell">Revenue</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800/50">
                        @forelse($fbrposCompaniesList as $company)
                        @php $sc = ['approved' => 'bg-emerald-900/30 text-emerald-400', 'active' => 'bg-emerald-900/30 text-emerald-400', 'pending' => 'bg-amber-900/30 text-amber-400', 'suspended' => 'bg-red-900/30 text-red-400', 'rejected' => 'bg-gray-800 text-gray-400']; @endphp
                        <tr class="hover:bg-gray-800/30">
                            <td class="px-4 py-3">
                                <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="text-white font-medium hover:text-blue-400 transition">{{ $company->name }}</a>
                                <p class="text-[10px] text-gray-600 dark:text-gray-400">{{ $company->email ?? '' }}</p>
                            </td>
                            <td class="px-4 py-3 text-gray-400 text-xs hidden sm:table-cell">{{ $company->ntn ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-400 text-xs hidden md:table-cell">{{ $company->owner_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-center text-gray-300 hidden sm:table-cell">{{ $company->users_count }}</td>
                            <td class="px-4 py-3 text-center text-gray-300 hidden md:table-cell">{{ number_format($company->fbrpos_transaction_count ?? 0) }}</td>
                            <td class="px-4 py-3 text-right text-blue-400 text-xs hidden lg:table-cell">PKR {{ number_format($company->fbrpos_revenue ?? 0, 0) }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium {{ $sc[$company->status] ?? 'bg-gray-800 text-gray-400' }}">{{ $company->status }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="px-2 py-1 bg-blue-600/20 text-blue-400 text-[10px] rounded hover:bg-blue-600/40 transition">Manage</a>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No FBR POS companies found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Platform Stats</h3>
            <div class="space-y-3">
                <div class="flex justify-between text-sm"><span class="text-gray-400">Total Users</span><span class="text-white font-medium">{{ $stats['total_users'] }}</span></div>
                <div class="flex justify-between text-sm"><span class="text-gray-400">Franchises</span><span class="text-white font-medium">{{ $stats['total_franchises'] }}</span></div>
                <div class="flex justify-between text-sm"><span class="text-gray-400">Active Subscriptions</span><span class="text-white font-medium">{{ $stats['active_subscriptions'] }}</span></div>
                <div class="flex justify-between text-sm"><span class="text-gray-400">Today's PRA POS</span><span class="text-white font-medium">{{ $stats['today_pos_transactions'] }} txns</span></div>
                <div class="flex justify-between text-sm"><span class="text-gray-400">Today's FBR POS</span><span class="text-white font-medium">{{ $stats['today_fbrpos_transactions'] }} txns</span></div>
            </div>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">System Controls</h3>
            <div class="space-y-2">
                @foreach($systemControls as $control)
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-400">{{ ucwords(str_replace('_', ' ', $control->key)) }}</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $control->value === 'enabled' ? 'bg-emerald-900/30 text-emerald-400' : 'bg-red-900/30 text-red-400' }}">{{ $control->value }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <h3 class="text-sm font-semibold text-white mb-3">Recent Audit Activity</h3>
        <div class="space-y-2">
            @forelse($recentAuditLogs as $log)
            <div class="flex items-center justify-between text-sm border-b border-gray-800 pb-2">
                <div><span class="text-gray-300">{{ $log->action }}</span>@if($log->target_type)<span class="text-gray-600 dark:text-gray-400"> - {{ $log->target_type }} #{{ $log->target_id }}</span>@endif</div>
                <span class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $log->created_at->diffForHumans() }}</span>
            </div>
            @empty
            <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">No audit logs yet</p>
            @endforelse
        </div>
    </div>
</div>
</x-admin-layout>
