<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-white">Companies</h1>
        <div class="flex items-center gap-2">
            <a href="{{ route('saas.admin.companies.create') }}" class="flex items-center gap-2 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Company
            </a>
            <a href="{{ route('saas.admin.companies.bin') }}" class="flex items-center gap-2 px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-white text-xs rounded-lg transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Bin
            </a>
        </div>
    </div>

    {{-- Agent Health (Task 629): silent-print shops whose Desktop Agent has been
         offline > 2 hours — cashiers there are on Chrome popup fallback right now. --}}
    @if(($offlineAgents ?? collect())->isNotEmpty())
    <div class="bg-red-900/20 border border-red-800/60 rounded-xl p-4 mb-6">
        <div class="flex items-center gap-2 mb-3">
            <svg class="w-5 h-5 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
            <h2 class="text-sm font-bold text-red-300">Agent Health — {{ $offlineAgents->count() }} {{ $offlineAgents->count() === 1 ? 'shop' : 'shops' }} with Desktop Agent offline &gt; 2 hours (silent print enabled)</h2>
        </div>
        <div class="space-y-1.5">
            @foreach($offlineAgents as $oa)
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <a href="{{ route('saas.admin.companies.show', $oa->id) }}" class="text-white font-semibold hover:text-red-300 transition">{{ $oa->name }}</a>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold uppercase bg-gray-800 text-gray-400">{{ $oa->product_type }}</span>
                <span class="text-red-400">
                    @if($oa->agent_last_seen)
                        Last seen {{ $oa->agent_last_seen->diffForHumans() }} ({{ $oa->agent_last_seen->format('d M, h:i A') }})
                    @else
                        Agent never connected
                    @endif
                </span>
                <span class="text-gray-500">Silent print: ON{{ $oa->agent_version ? ' · v'.$oa->agent_version : '' }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name, NTN, owner..." class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-4 py-2 focus:ring-2 focus:ring-indigo-500">
            <select name="product_type" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-4 py-2 focus:ring-2 focus:ring-indigo-500">
                <option value="">All Types</option>
                <option value="di" {{ request('product_type') === 'di' ? 'selected' : '' }}>Digital Invoice</option>
                <option value="pos" {{ request('product_type') === 'pos' ? 'selected' : '' }}>PRA POS</option>
                <option value="fbrpos" {{ request('product_type') === 'fbrpos' ? 'selected' : '' }}>FBR POS</option>
            </select>
            <select name="status" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-4 py-2 focus:ring-2 focus:ring-indigo-500">
                <option value="">All Statuses</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Approved</option>
                <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
                <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Filter</button>
        </form>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-[10px] text-gray-500 dark:text-gray-400 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-4 py-3">Company</th>
                        <th class="px-4 py-3 hidden sm:table-cell">NTN</th>
                        <th class="px-4 py-3 text-center">Type</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 hidden sm:table-cell">Created</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($companies as $company)
                    @php
                        $statusColors = ['approved' => 'bg-emerald-900/30 text-emerald-400', 'active' => 'bg-emerald-900/30 text-emerald-400', 'pending' => 'bg-amber-900/30 text-amber-400', 'suspended' => 'bg-red-900/30 text-red-400', 'rejected' => 'bg-gray-800 text-gray-400'];
                        $typeColors = ['di' => 'bg-emerald-900/30 text-emerald-400', 'pos' => 'bg-purple-900/30 text-purple-400', 'fbrpos' => 'bg-blue-900/30 text-blue-400'];
                    @endphp
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="text-white font-medium hover:text-indigo-400 transition">{{ $company->name }}</a>
                            @if($company->agentLongOffline())
                            <span class="inline-flex items-center gap-1 ml-1 px-1.5 py-0.5 rounded bg-red-900/40 text-red-400 text-[10px] font-bold" title="Desktop Agent offline > 2 hours — silent print shop on popup fallback. Last seen: {{ $company->agent_last_seen ? $company->agent_last_seen->format('d M, h:i A') : 'never' }}">
                                <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Agent Offline
                            </span>
                            @endif
                            @if($company->agent_enabled && $latestAgentVersion && $company->agent_version && version_compare($company->agent_version, $latestAgentVersion, '<'))
                            @php
                                $agentStuck = !empty($company->agent_update_error);
                            @endphp
                            <span class="inline-flex items-center gap-1 ml-1 px-1.5 py-0.5 rounded text-[10px] font-bold {{ $agentStuck ? 'bg-red-900/50 text-red-400' : 'bg-amber-900/40 text-amber-400' }}"
                                  title="{{ $agentStuck ? 'Agent update stuck — ' . $company->agent_update_error . ' | Running: v' . $company->agent_version . ' → Latest: v' . $latestAgentVersion : 'Agent outdated — running v' . $company->agent_version . ', latest v' . $latestAgentVersion }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $agentStuck ? 'bg-red-500' : 'bg-amber-400' }}"></span>
                                {{ $agentStuck ? 'Update Stuck' : 'Agent Purana' }}
                            </span>
                            @endif
                            <p class="text-[10px] text-gray-600 dark:text-gray-400">{{ $company->owner_name ?? '' }}</p>
                            {{-- Package picked at registration — what approval will activate for 1 year --}}
                            @php $requestedPackage = \App\Services\RequestedPackageService::pendingSummary($company); @endphp
                            @if($requestedPackage)
                            <span class="inline-flex items-center mt-1 px-1.5 py-0.5 rounded bg-indigo-900/30 text-indigo-300 text-[10px] font-semibold">{{ $requestedPackage['badge'] }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-400 text-xs hidden sm:table-cell">{{ $company->ntn ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $typeColors[$company->product_type] ?? 'bg-gray-800 text-gray-400' }}">{{ $company->product_type }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium {{ $statusColors[$company->status] ?? 'bg-gray-800 text-gray-400' }}">{{ $company->status }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs hidden sm:table-cell">{{ $company->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center gap-1">
                                @if($company->status === 'pending')
                                <form method="POST" action="{{ route('saas.admin.companies.approve', $company->id) }}" class="inline">@csrf<button class="px-2 py-1 bg-emerald-600/20 text-emerald-400 text-[10px] rounded hover:bg-emerald-600/40 transition">Approve</button></form>
                                <form method="POST" action="{{ route('saas.admin.companies.reject', $company->id) }}" class="inline">@csrf<button class="px-2 py-1 bg-red-600/20 text-red-400 text-[10px] rounded hover:bg-red-600/40 transition">Reject</button></form>
                                @elseif($company->status === 'approved' || $company->status === 'active')
                                <form method="POST" action="{{ route('saas.admin.companies.suspend', $company->id) }}" class="inline">@csrf<button class="px-2 py-1 bg-amber-600/20 text-amber-400 text-[10px] rounded hover:bg-amber-600/40 transition">Suspend</button></form>
                                @elseif($company->status === 'suspended' || $company->status === 'rejected')
                                <form method="POST" action="{{ route('saas.admin.companies.activate', $company->id) }}" class="inline">@csrf<button class="px-2 py-1 bg-emerald-600/20 text-emerald-400 text-[10px] rounded hover:bg-emerald-600/40 transition">Activate</button></form>
                                @endif
                                <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="px-2 py-1 bg-indigo-600/20 text-indigo-400 text-[10px] rounded hover:bg-indigo-600/40 transition">View</a>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">No companies found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($companies->hasPages())<div class="mt-4">{{ $companies->links() }}</div>@endif
</div>
</x-admin-layout>
