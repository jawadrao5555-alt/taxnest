<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Stock Levels</h1>
        <a href="{{ route('pos.inventory.adjust') }}" class="mt-2 sm:mt-0 inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-lg transition shadow-sm">
            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Adjust Stock
        </a>
    </div>

    @if(session('success'))
    <div class="mb-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-lg p-3 text-sm text-emerald-800 dark:text-emerald-300">{{ session('success') }}</div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
        <form method="GET" action="{{ route('pos.inventory.stock') }}" class="flex flex-col sm:flex-row gap-3">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search products..." class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
            <select name="filter" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                <option value="">All Stock</option>
                <option value="low" {{ request('filter') === 'low' ? 'selected' : '' }}>Low Stock</option>
                <option value="out" {{ request('filter') === 'out' ? 'selected' : '' }}>Out of Stock</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-lg transition">Filter</button>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                        <th class="px-4 py-3">Product</th>
                        <th class="px-4 py-3 text-right">Current Stock</th>
                        <th class="px-4 py-3 text-right">Min Level</th>
                        <th class="px-4 py-3 text-right">Avg Cost</th>
                        <th class="px-4 py-3 text-right">Stock Value</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-right">Min Level Setting</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($stocks as $stock)
                    @php
                        $statusClass = 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400';
                        $statusText = 'In Stock';
                        if ($stock->quantity <= 0) {
                            $statusClass = 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
                            $statusText = 'Out of Stock';
                        } elseif ($stock->isLowStock()) {
                            $statusClass = 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400';
                            $statusText = 'Low Stock';
                        }
                    @endphp
                    <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}">
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $stock->product->name ?? 'Unknown' }}</td>
                        <td class="px-4 py-3 text-right font-bold {{ $stock->quantity <= 0 ? 'text-red-600' : ($stock->isLowStock() ? 'text-amber-600' : 'text-gray-900 dark:text-white') }}">{{ number_format($stock->quantity, 0) }}</td>
                        <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400">{{ number_format($stock->min_stock_level, 0) }}</td>
                        <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400">PKR {{ number_format($stock->avg_purchase_price, 2) }}</td>
                        <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($stock->quantity * $stock->avg_purchase_price, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $statusClass }}">{{ $statusText }}</span>
                        </td>
                        <td class="px-4 py-3 text-right" x-data="{ editing: false, minLevel: {{ $stock->min_stock_level }}, saving: false }">
                            <template x-if="!editing">
                                <button @click="editing = true" class="text-xs text-purple-600 hover:underline">Edit</button>
                            </template>
                            <template x-if="editing">
                                <div class="flex items-center justify-end gap-1">
                                    <input type="number" x-model="minLevel" min="0" step="1" class="w-20 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm px-2 py-1 text-right">
                                    <button @click="saving = true; fetch('/pos/inventory/min-stock', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ product_id: {{ $stock->product_id }}, min_stock_level: minLevel }) }).then(r => r.json()).then(d => { saving = false; editing = false; }).catch(() => { saving = false; })" :disabled="saving" class="px-2 py-1 bg-purple-600 hover:bg-purple-700 text-white text-xs rounded transition">
                                        <span x-text="saving ? '...' : 'Save'"></span>
                                    </button>
                                    <button @click="editing = false" class="px-2 py-1 bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs rounded">X</button>
                                </div>
                            </template>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-gray-400">
                            <p class="text-sm">No stock records found. Add stock using the Adjust Stock button.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($stocks->hasPages())
    <div class="mt-4">{{ $stocks->links() }}</div>
    @endif
</div>
</x-pos-layout>
