<x-admin-layout>
<div class="p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">Subscription Plans</h1>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6" x-data="{ showForm: false }">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-white">Create New Plan</h3>
            <button @click="showForm = !showForm" class="text-xs text-indigo-400 hover:underline" x-text="showForm ? 'Hide' : 'Show Form'"></button>
        </div>
        <form x-show="showForm" method="POST" action="{{ route('saas.admin.plans.store') }}" class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @csrf
            <input type="text" name="name" placeholder="Plan Name" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="number" name="price" placeholder="Price (PKR)" step="0.01" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="number" name="invoice_limit" placeholder="Invoice Limit" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="number" name="max_terminals" placeholder="Max Terminals (empty=unlimited)" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
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
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-lg font-bold text-white">{{ $plan->name }}</h3>
                <span class="text-xl font-bold text-indigo-400">PKR {{ number_format($plan->price, 0) }}</span>
            </div>
            <div class="space-y-2 text-sm mb-4">
                <div class="flex justify-between"><span class="text-gray-400">Invoice Limit</span><span class="text-white">{{ $plan->invoice_limit > 0 ? number_format($plan->invoice_limit) : 'Unlimited' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Max Terminals</span><span class="text-white">{{ $plan->max_terminals ?? 'Unlimited' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Max Users</span><span class="text-white">{{ $plan->max_users ?? 'Unlimited' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Max Products</span><span class="text-white">{{ $plan->max_products ?? 'Unlimited' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Inventory</span><span class="{{ $plan->inventory_enabled ? 'text-emerald-400' : 'text-red-400' }}">{{ $plan->inventory_enabled ? 'Yes' : 'No' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Reports</span><span class="{{ $plan->reports_enabled ? 'text-emerald-400' : 'text-red-400' }}">{{ $plan->reports_enabled ? 'Yes' : 'No' }}</span></div>
            </div>
        </div>
        @endforeach
    </div>
</div>
</x-admin-layout>
