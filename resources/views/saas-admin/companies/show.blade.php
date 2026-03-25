<x-admin-layout>
<div class="p-4 sm:p-6 max-w-5xl mx-auto" x-data="{ showDeleteModal: false }">
    <div class="flex flex-wrap items-center gap-3 mb-6">
        <a href="{{ route('saas.admin.companies') }}" class="text-gray-500 hover:text-indigo-400 transition text-sm">&larr; Back</a>
        <h1 class="text-2xl font-bold text-white truncate">{{ $company->name }}</h1>
        @php
            $sc = ['approved' => 'bg-emerald-900/30 text-emerald-400', 'active' => 'bg-emerald-900/30 text-emerald-400', 'pending' => 'bg-amber-900/30 text-amber-400', 'suspended' => 'bg-red-900/30 text-red-400', 'rejected' => 'bg-gray-800 text-gray-400'];
            $tc = ['di' => 'bg-emerald-900/30 text-emerald-400', 'pos' => 'bg-purple-900/30 text-purple-400'];
        @endphp
        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $sc[$company->status] ?? 'bg-gray-800 text-gray-400' }}">{{ $company->status }}</span>
        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $tc[$company->product_type] ?? 'bg-gray-800 text-gray-400' }}">{{ $company->product_type === 'di' ? 'Digital Invoice' : 'NestPOS' }}</span>
        @if(!$company->trashed())
        <a href="{{ route('saas.admin.companies.edit', $company->id) }}" class="ml-auto flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600/20 hover:bg-indigo-600/40 text-indigo-400 text-xs font-medium rounded-lg transition border border-indigo-800">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            Edit Profile
        </a>
        @endif
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Company Details</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-gray-400">Name</span><span class="text-white font-medium">{{ $company->name }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Owner</span><span class="text-white">{{ $company->owner_name ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">NTN</span><span class="text-white">{{ $company->ntn ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">CNIC</span><span class="text-white">{{ $company->cnic ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Email</span><span class="text-white">{{ $company->email ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Phone</span><span class="text-white">{{ $company->phone ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">City</span><span class="text-white">{{ $company->city ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Address</span><span class="text-white text-right max-w-[200px] truncate">{{ $company->address ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Province</span><span class="text-white">{{ $company->province ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Franchise</span><span class="text-white">{{ $company->franchise->name ?? 'None' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Created</span><span class="text-white">{{ $company->created_at->format('d M Y, h:i A') }}</span></div>
            </div>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">
                {{ $company->product_type === 'di' ? 'FBR Integration' : 'PRA Integration' }}
            </h3>
            <div class="space-y-2 text-sm">
                @if($company->product_type === 'di')
                <div class="flex justify-between"><span class="text-gray-400">FBR Environment</span><span class="text-white">{{ ucfirst($company->fbr_environment ?? 'N/A') }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">FBR Reg No</span><span class="text-white">{{ $company->fbr_registration_no ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">FBR Business</span><span class="text-white">{{ $company->fbr_business_name ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Token Expiry</span><span class="text-white">{{ $company->token_expiry_date ? $company->token_expiry_date->format('d M Y') : '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Connection</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs {{ $company->fbr_connection_status === 'connected' ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">{{ $company->fbr_connection_status ?? 'N/A' }}</span>
                </div>
                <div class="flex justify-between"><span class="text-gray-400">Last Submission</span><span class="text-white">{{ $company->last_successful_submission ? $company->last_successful_submission->format('d M Y h:i A') : '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Invoice Prefix</span><span class="text-white">{{ $company->invoice_number_prefix ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Compliance Score</span><span class="text-white">{{ $company->compliance_score ?? '—' }}%</span></div>
                @else
                <div class="flex justify-between"><span class="text-gray-400">PRA Environment</span><span class="text-white">{{ ucfirst($company->pra_environment ?? 'N/A') }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">POS ID</span><span class="text-white">{{ $company->pra_pos_id ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">PRA Reporting</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs {{ $company->pra_reporting_enabled ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">{{ $company->pra_reporting_enabled ? 'Enabled' : 'Disabled' }}</span>
                </div>
                <div class="flex justify-between"><span class="text-gray-400">Inventory</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs {{ $company->inventory_enabled ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">{{ $company->inventory_enabled ? 'Enabled' : 'Disabled' }}</span>
                </div>
                @endif
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Usage & Revenue</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-gray-400">Total Users</span><span class="text-white font-medium">{{ $extraStats['total_users'] }}</span></div>
                @if($company->product_type === 'di')
                <div class="flex justify-between"><span class="text-gray-400">Total Invoices</span><span class="text-white font-medium">{{ number_format($extraStats['total_invoices']) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Locked (FBR)</span><span class="text-emerald-400 font-medium">{{ number_format($extraStats['locked_invoices']) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Drafts</span><span class="text-amber-400 font-medium">{{ number_format($extraStats['draft_invoices']) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Total Revenue</span><span class="text-emerald-400 font-bold">PKR {{ number_format($extraStats['total_revenue'], 0) }}</span></div>
                @else
                <div class="flex justify-between"><span class="text-gray-400">Transactions</span><span class="text-white font-medium">{{ number_format($extraStats['total_transactions']) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Today's Txns</span><span class="text-white font-medium">{{ number_format($extraStats['today_transactions']) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Total Revenue</span><span class="text-purple-400 font-bold">PKR {{ number_format($extraStats['total_revenue'], 0) }}</span></div>
                @endif
                @if($extraStats['active_subscription'])
                <div class="pt-2 border-t border-gray-800">
                    <div class="flex justify-between"><span class="text-gray-400">Plan</span><span class="text-indigo-400 font-medium">{{ $extraStats['active_subscription']->pricingPlan->name ?? 'N/A' }}</span></div>
                    <div class="flex justify-between mt-1"><span class="text-gray-400">Billing</span><span class="text-white">{{ ucfirst(str_replace('_', ' ', $extraStats['active_subscription']->billing_cycle ?? 'N/A')) }}</span></div>
                </div>
                @else
                <div class="pt-2 border-t border-gray-800">
                    <p class="text-xs text-amber-400">No active subscription</p>
                </div>
                @endif
            </div>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Limit Overrides</h3>
            <p class="text-xs text-gray-500 mb-3">Set custom limits. Leave empty to use plan defaults.</p>
            <form method="POST" action="{{ route('saas.admin.companies.limits', $company->id) }}" class="space-y-3">
                @csrf
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Invoice / Transaction Limit</label>
                    <input type="number" name="invoice_limit_override" value="{{ $company->invoice_limit_override }}" placeholder="Plan default" min="0" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">User Limit</label>
                    <input type="number" name="user_limit_override" value="{{ $company->user_limit_override }}" placeholder="Plan default" min="0" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Branch Limit</label>
                    <input type="number" name="branch_limit_override" value="{{ $company->branch_limit_override }}" placeholder="Plan default" min="0" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <button type="submit" class="w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Update Limits</button>
            </form>
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
        <h3 class="text-sm font-semibold text-white mb-3">Quick Actions</h3>
        <div class="flex flex-wrap gap-2">
            @if(!$company->trashed())
                @if($company->status === 'pending')
                <form method="POST" action="{{ route('saas.admin.companies.approve', $company->id) }}">@csrf<button class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-lg transition font-medium">Approve</button></form>
                <form method="POST" action="{{ route('saas.admin.companies.reject', $company->id) }}">@csrf<button class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm rounded-lg transition font-medium">Reject</button></form>
                @elseif($company->status === 'approved' || $company->status === 'active')
                <form method="POST" action="{{ route('saas.admin.companies.suspend', $company->id) }}">@csrf<button class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm rounded-lg transition font-medium">Suspend</button></form>
                @elseif($company->status === 'suspended' || $company->status === 'rejected')
                <form method="POST" action="{{ route('saas.admin.companies.activate', $company->id) }}">@csrf<button class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-lg transition font-medium">Activate</button></form>
                @endif

                <button @click="showDeleteModal = true" class="px-4 py-2 bg-red-900/30 hover:bg-red-900/50 text-red-400 text-sm rounded-lg transition font-medium border border-red-800">Move to Bin</button>
            @else
                <p class="text-sm text-red-400">This company is in the bin (deleted on {{ $company->deleted_at->format('d M Y') }}).</p>
                <form method="POST" action="{{ route('saas.admin.companies.restore', $company->id) }}">@csrf<button class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-lg transition font-medium">Restore</button></form>
            @endif
        </div>
    </div>

    @if($company->product_type === 'di')
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <h3 class="text-sm font-semibold text-white mb-3">Change Company Type</h3>
        <p class="text-xs text-gray-500 mb-3">Switch between Digital Invoice and NestPOS.</p>
        <form method="POST" action="{{ route('saas.admin.companies.changeType', $company->id) }}" class="flex items-center gap-3">
            @csrf
            <input type="hidden" name="product_type" value="pos">
            <button type="submit" onclick="return confirm('Are you sure? This will change the company type to NestPOS.')" class="px-4 py-2 bg-purple-600/20 hover:bg-purple-600/40 text-purple-400 text-sm rounded-lg transition font-medium border border-purple-800">Switch to NestPOS</button>
        </form>
    </div>
    @else
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <h3 class="text-sm font-semibold text-white mb-3">Change Company Type</h3>
        <p class="text-xs text-gray-500 mb-3">Switch between Digital Invoice and NestPOS.</p>
        <form method="POST" action="{{ route('saas.admin.companies.changeType', $company->id) }}" class="flex items-center gap-3">
            @csrf
            <input type="hidden" name="product_type" value="di">
            <button type="submit" onclick="return confirm('Are you sure? This will change the company type to Digital Invoice.')" class="px-4 py-2 bg-emerald-600/20 hover:bg-emerald-600/40 text-emerald-400 text-sm rounded-lg transition font-medium border border-emerald-800">Switch to Digital Invoice</button>
        </form>
    </div>
    @endif

    <div x-show="showDeleteModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="showDeleteModal = false">
        <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 w-full max-w-md mx-4" @click.stop>
            <h3 class="text-lg font-bold text-white mb-2">Move to Bin</h3>
            <p class="text-sm text-gray-400 mb-4">This will soft-delete "{{ $company->name }}". You can restore it from the Bin later, or permanently delete it there.</p>
            <form method="POST" action="{{ route('saas.admin.companies.delete', $company->id) }}">
                @csrf
                <div class="mb-4">
                    <label class="text-xs text-gray-400 mb-1 block">Reason (optional)</label>
                    <input type="text" name="reason" placeholder="e.g. Inactive, Duplicate, etc." class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 placeholder-gray-600">
                </div>
                <div class="flex gap-2 justify-end">
                    <button type="button" @click="showDeleteModal = false" class="px-4 py-2 bg-gray-800 text-gray-300 text-sm rounded-lg hover:bg-gray-700 transition">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm rounded-lg font-medium transition">Move to Bin</button>
                </div>
            </form>
        </div>
    </div>
</div>
</x-admin-layout>
