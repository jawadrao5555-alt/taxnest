<x-admin-layout>
<div class="p-4 sm:p-6 max-w-5xl mx-auto">
    <div class="flex flex-wrap items-center gap-3 mb-6">
        <a href="{{ route('saas.admin.companies') }}" class="text-gray-500 hover:text-indigo-400 transition text-sm">&larr; Back</a>
        <h1 class="text-2xl font-bold text-white truncate">{{ $company->name }}</h1>
        @php $sc = ['approved' => 'bg-emerald-900/30 text-emerald-400', 'pending' => 'bg-amber-900/30 text-amber-400', 'suspended' => 'bg-red-900/30 text-red-400', 'rejected' => 'bg-gray-800 text-gray-400']; @endphp
        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $sc[$company->status] ?? 'bg-gray-800 text-gray-400' }}">{{ $company->status }}</span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Company Details</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-gray-400">NTN</span><span class="text-white">{{ $company->ntn ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Owner</span><span class="text-white">{{ $company->owner_name ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Phone</span><span class="text-white">{{ $company->phone ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Franchise</span><span class="text-white">{{ $company->franchise->name ?? 'None' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Created</span><span class="text-white">{{ $company->created_at->format('d M Y') }}</span></div>
            </div>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Usage Stats</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-gray-400">POS Transactions</span><span class="text-white font-medium">{{ number_format($usageStats->total_pos_transactions) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Total Sales</span><span class="text-emerald-400 font-medium">PKR {{ number_format($usageStats->total_sales_amount, 0) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Active Terminals</span><span class="text-white">{{ $usageStats->active_terminals }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Active Users</span><span class="text-white">{{ $usageStats->active_users }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Inventory Items</span><span class="text-white">{{ $usageStats->inventory_items }}</span></div>
            </div>
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <h3 class="text-sm font-semibold text-white mb-3">Actions</h3>
        <div class="flex flex-col sm:flex-row flex-wrap gap-2">
            @if($company->status === 'pending')
            <form method="POST" action="{{ route('saas.admin.companies.approve', $company->id) }}" class="w-full sm:w-auto">@csrf<button class="w-full sm:w-auto px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-lg transition">Approve Company</button></form>
            <form method="POST" action="{{ route('saas.admin.companies.reject', $company->id) }}" class="w-full sm:w-auto">@csrf<button class="w-full sm:w-auto px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm rounded-lg transition">Reject Company</button></form>
            @elseif($company->status === 'approved')
            <form method="POST" action="{{ route('saas.admin.companies.suspend', $company->id) }}">@csrf<button class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm rounded-lg transition">Suspend Company</button></form>
            @elseif($company->status === 'suspended' || $company->status === 'rejected')
            <form method="POST" action="{{ route('saas.admin.companies.activate', $company->id) }}">@csrf<button class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-lg transition">Activate Company</button></form>
            @endif
        </div>
    </div>
</div>
</x-admin-layout>
