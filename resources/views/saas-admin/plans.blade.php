<x-admin-layout>
<div class="p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Subscription Plans</h1>
            <p class="text-sm text-gray-400 mt-1">Changes auto-reflect on all landing & billing pages</p>
        </div>
    </div>

    @if(session('success'))
    <div class="bg-emerald-900/30 border border-emerald-700 rounded-lg p-3 mb-6 text-sm text-emerald-300">{{ session('success') }}</div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6" x-data="{ showForm: false }">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-white">Create New Plan</h3>
            <button @click="showForm = !showForm" class="text-xs text-indigo-400 hover:underline" x-text="showForm ? 'Hide' : 'Show Form'"></button>
        </div>
        <form x-show="showForm" method="POST" action="{{ route('saas.admin.plans.store') }}" class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @csrf
            <input type="text" name="name" placeholder="Plan Name" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="number" name="price" placeholder="Monthly Price (PKR)" step="1" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="number" name="invoice_limit" placeholder="Invoice Limit (-1=unlimited)" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="number" name="max_terminals" placeholder="Max Terminals" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="number" name="max_users" placeholder="Max Users" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="number" name="max_products" placeholder="Max Products" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <div class="flex items-center gap-4">
                <label class="flex items-center gap-1.5 text-sm text-gray-400"><input type="checkbox" name="inventory_enabled" value="1" checked class="rounded bg-gray-800 border-gray-600 text-indigo-500"> Inventory</label>
                <label class="flex items-center gap-1.5 text-sm text-gray-400"><input type="checkbox" name="reports_enabled" value="1" checked class="rounded bg-gray-800 border-gray-600 text-indigo-500"> Reports</label>
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Create Plan</button>
        </form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($plans as $plan)
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5" x-data="{ editing: false }">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <h3 class="text-lg font-bold text-white">{{ $plan->name }}</h3>
                    @if($plan->is_trial)<span class="text-[10px] px-1.5 py-0.5 bg-blue-900/50 text-blue-300 rounded font-bold">TRIAL</span>@endif
                </div>
                <button @click="editing = !editing" class="text-xs px-2 py-1 rounded transition" :class="editing ? 'bg-red-600/20 text-red-400 hover:bg-red-600/30' : 'bg-indigo-600/20 text-indigo-400 hover:bg-indigo-600/30'" x-text="editing ? 'Cancel' : 'Edit'"></button>
            </div>

            <div x-show="!editing">
                <div class="text-2xl font-bold text-indigo-400 mb-3">PKR {{ number_format($plan->price, 0) }}<span class="text-sm text-gray-500 font-normal">/mo</span></div>
                <div class="space-y-1.5 text-sm">
                    <div class="flex justify-between"><span class="text-gray-400">Invoices/mo</span><span class="text-white">{{ $plan->invoice_limit > 0 ? number_format($plan->invoice_limit) : ($plan->invoice_limit == -1 ? 'Unlimited' : '0') }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Users</span><span class="text-white">{{ ($plan->user_limit ?? 0) > 0 ? $plan->user_limit : (($plan->user_limit ?? 0) == -1 ? 'Unlimited' : ($plan->max_users ?? 'N/A')) }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Terminals</span><span class="text-white">{{ $plan->max_terminals ?? 'Unlimited' }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Products</span><span class="text-white">{{ $plan->max_products ?? 'Unlimited' }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Inventory</span><span class="{{ $plan->inventory_enabled ? 'text-emerald-400' : 'text-red-400' }}">{{ $plan->inventory_enabled ? 'Yes' : 'No' }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Reports</span><span class="{{ $plan->reports_enabled ? 'text-emerald-400' : 'text-red-400' }}">{{ $plan->reports_enabled ? 'Yes' : 'No' }}</span></div>
                </div>
            </div>

            <form x-show="editing" method="POST" action="{{ route('saas.admin.plans.update', $plan->id) }}" class="space-y-3">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-[10px] text-gray-500 uppercase">Name</label>
                        <input type="text" name="name" value="{{ $plan->name }}" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-[10px] text-gray-500 uppercase">Price (PKR/mo)</label>
                        <input type="number" name="price" value="{{ intval($plan->price) }}" step="1" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-[10px] text-gray-500 uppercase">Invoice Limit</label>
                        <input type="number" name="invoice_limit" value="{{ $plan->invoice_limit }}" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-[10px] text-gray-500 uppercase">Max Terminals</label>
                        <input type="number" name="max_terminals" value="{{ $plan->max_terminals }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-[10px] text-gray-500 uppercase">Max Users</label>
                        <input type="number" name="max_users" value="{{ $plan->max_users }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-[10px] text-gray-500 uppercase">Max Products</label>
                        <input type="number" name="max_products" value="{{ $plan->max_products }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-1.5 text-sm text-gray-400"><input type="checkbox" name="inventory_enabled" value="1" {{ $plan->inventory_enabled ? 'checked' : '' }} class="rounded bg-gray-800 border-gray-600 text-indigo-500"> Inventory</label>
                    <label class="flex items-center gap-1.5 text-sm text-gray-400"><input type="checkbox" name="reports_enabled" value="1" {{ $plan->reports_enabled ? 'checked' : '' }} class="rounded bg-gray-800 border-gray-600 text-indigo-500"> Reports</label>
                </div>
                <button type="submit" class="w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Save Changes</button>
            </form>
        </div>
        @endforeach
    </div>
</div>
</x-admin-layout>
