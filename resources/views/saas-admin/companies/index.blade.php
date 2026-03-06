<x-admin-layout>
<div class="p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">Companies</h1>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 mb-6">
        <form method="GET" class="flex flex-col sm:flex-row gap-3">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by name or NTN..." class="flex-1 bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-4 py-2 focus:ring-2 focus:ring-indigo-500">
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
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-4 py-3">Company</th>
                        <th class="px-4 py-3">NTN</th>
                        <th class="px-4 py-3">Franchise</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3">Created</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($companies as $company)
                    @php
                        $statusColors = ['approved' => 'bg-emerald-900/30 text-emerald-400', 'pending' => 'bg-amber-900/30 text-amber-400', 'suspended' => 'bg-red-900/30 text-red-400', 'rejected' => 'bg-gray-800 text-gray-400'];
                    @endphp
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="text-white font-medium hover:text-indigo-400 transition">{{ $company->name }}</a>
                        </td>
                        <td class="px-4 py-3 text-gray-400">{{ $company->ntn ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400">{{ $company->franchise->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $statusColors[$company->status] ?? 'bg-gray-800 text-gray-400' }}">{{ $company->status }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs">{{ $company->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center gap-1">
                                @if($company->status === 'pending')
                                <form method="POST" action="{{ route('saas.admin.companies.approve', $company->id) }}" class="inline">@csrf<button class="px-2 py-1 bg-emerald-600/20 text-emerald-400 text-xs rounded hover:bg-emerald-600/40 transition">Approve</button></form>
                                <form method="POST" action="{{ route('saas.admin.companies.reject', $company->id) }}" class="inline">@csrf<button class="px-2 py-1 bg-red-600/20 text-red-400 text-xs rounded hover:bg-red-600/40 transition">Reject</button></form>
                                @elseif($company->status === 'approved')
                                <form method="POST" action="{{ route('saas.admin.companies.suspend', $company->id) }}" class="inline">@csrf<button class="px-2 py-1 bg-amber-600/20 text-amber-400 text-xs rounded hover:bg-amber-600/40 transition">Suspend</button></form>
                                @elseif($company->status === 'suspended' || $company->status === 'rejected')
                                <form method="POST" action="{{ route('saas.admin.companies.activate', $company->id) }}" class="inline">@csrf<button class="px-2 py-1 bg-emerald-600/20 text-emerald-400 text-xs rounded hover:bg-emerald-600/40 transition">Activate</button></form>
                                @endif
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
