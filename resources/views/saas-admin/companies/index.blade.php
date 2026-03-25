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

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name, NTN, owner..." class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-4 py-2 focus:ring-2 focus:ring-indigo-500">
            <select name="product_type" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-4 py-2 focus:ring-2 focus:ring-indigo-500">
                <option value="">All Types</option>
                <option value="di" {{ request('product_type') === 'di' ? 'selected' : '' }}>Digital Invoice</option>
                <option value="pos" {{ request('product_type') === 'pos' ? 'selected' : '' }}>NestPOS</option>
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
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[10px] text-gray-500 uppercase border-b border-gray-800 bg-gray-800/50">
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
                        $typeColors = ['di' => 'bg-emerald-900/30 text-emerald-400', 'pos' => 'bg-purple-900/30 text-purple-400'];
                    @endphp
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="text-white font-medium hover:text-indigo-400 transition">{{ $company->name }}</a>
                            <p class="text-[10px] text-gray-600">{{ $company->owner_name ?? '' }}</p>
                        </td>
                        <td class="px-4 py-3 text-gray-400 text-xs hidden sm:table-cell">{{ $company->ntn ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $typeColors[$company->product_type] ?? 'bg-gray-800 text-gray-400' }}">{{ $company->product_type }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium {{ $statusColors[$company->status] ?? 'bg-gray-800 text-gray-400' }}">{{ $company->status }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs hidden sm:table-cell">{{ $company->created_at->format('d M Y') }}</td>
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
                    <tr><td colspan="6" class="px-4 py-12 text-center text-gray-500">No companies found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($companies->hasPages())<div class="mt-4">{{ $companies->links() }}</div>@endif
</div>
</x-admin-layout>
