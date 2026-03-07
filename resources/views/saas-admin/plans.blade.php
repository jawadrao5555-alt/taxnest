<x-admin-layout>
<div class="p-6 max-w-7xl mx-auto" x-data="{ activeTab: 'di' }">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Subscription Plans</h1>
            <p class="text-sm text-gray-400 mt-1">Changes auto-reflect on all landing & billing pages</p>
        </div>
    </div>

    @if(session('success'))
    <div class="bg-emerald-900/30 border border-emerald-700 rounded-lg p-3 mb-6 text-sm text-emerald-300">{{ session('success') }}</div>
    @endif

    <div class="flex items-center gap-2 mb-6">
        <button @click="activeTab = 'di'" :class="activeTab === 'di' ? 'bg-emerald-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">Digital Invoice Plans</button>
        <button @click="activeTab = 'pos'" :class="activeTab === 'pos' ? 'bg-purple-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">POS Plans</button>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6" x-data="{ showForm: false }">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-white">Create New Plan</h3>
            <button @click="showForm = !showForm" class="text-xs text-indigo-400 hover:underline" x-text="showForm ? 'Hide' : 'Show Form'"></button>
        </div>
        <form x-show="showForm" method="POST" action="{{ route('saas.admin.plans.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <input type="text" name="name" placeholder="Plan Name" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <div>
                    <select name="product_type" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        <option value="di">Digital Invoice</option>
                        <option value="pos">POS</option>
                    </select>
                </div>
                <input type="number" name="price" placeholder="Price (PKR)" step="1" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <input type="number" name="invoice_limit" placeholder="Invoice Limit (-1=unlimited)" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <input type="number" name="max_terminals" placeholder="Max Terminals (-1=unlimited)" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <input type="number" name="max_users" placeholder="Max Users (-1=unlimited)" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <input type="number" name="max_products" placeholder="Max Products (-1=unlimited)" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-1.5 text-sm text-gray-400"><input type="checkbox" name="inventory_enabled" value="1" checked class="rounded bg-gray-800 border-gray-600 text-indigo-500"> Inventory</label>
                    <label class="flex items-center gap-1.5 text-sm text-gray-400"><input type="checkbox" name="reports_enabled" value="1" checked class="rounded bg-gray-800 border-gray-600 text-indigo-500"> Reports</label>
                </div>
            </div>
            <div>
                <label class="text-[10px] text-gray-500 uppercase mb-1 block">Features (one per line — shown on landing page)</label>
                <textarea name="features_text" rows="4" placeholder="e.g. POS Billing&#10;Thermal Receipt&#10;PRA Integration" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></textarea>
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Create Plan</button>
        </form>
    </div>

    <div x-show="activeTab === 'di'">
        <div class="flex items-center gap-2 mb-4">
            <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
            <h2 class="text-lg font-bold text-white">Digital Invoice Plans</h2>
            <span class="text-xs text-gray-500">({{ $diPlans->count() }} plans — prices are monthly)</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($diPlans as $plan)
            @include('saas-admin.partials.plan-card', ['plan' => $plan, 'color' => 'emerald'])
            @endforeach
        </div>
    </div>

    <div x-show="activeTab === 'pos'" x-cloak>
        <div class="flex items-center gap-2 mb-4">
            <div class="w-2 h-2 rounded-full bg-purple-500"></div>
            <h2 class="text-lg font-bold text-white">POS Plans</h2>
            <span class="text-xs text-gray-500">({{ $posPlans->count() }} plans — prices are annual)</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($posPlans as $plan)
            @include('saas-admin.partials.plan-card', ['plan' => $plan, 'color' => 'purple'])
            @endforeach
        </div>
    </div>
</div>
</x-admin-layout>
