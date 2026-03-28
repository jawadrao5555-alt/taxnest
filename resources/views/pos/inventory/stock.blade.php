<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center shadow-lg shadow-purple-500/20">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            Stock Levels
        </h1>
        <a href="{{ route('pos.inventory.adjust') }}" class="mt-2 sm:mt-0 inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-700 hover:to-violet-700 text-white text-sm font-semibold rounded-xl transition shadow-lg shadow-purple-500/20">
            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Adjust Stock
        </a>
    </div>

    <div class="flex flex-wrap gap-2 mb-6">
        <a href="{{ route('pos.inventory.dashboard') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Dashboard</a>
        <a href="{{ route('pos.inventory.stock') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-purple-600 text-white shadow-sm">Stock Levels</a>
        <a href="{{ route('pos.inventory.movements') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Movements</a>
        <a href="{{ route('pos.inventory.low-stock') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Low Stock Alerts</a>
        <a href="{{ route('pos.inventory.adjust') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Adjust Stock</a>
    </div>

    @if(session('success'))
    <div class="mb-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-xl p-3 text-sm text-emerald-800 dark:text-emerald-300 flex items-center gap-2">
        <svg class="w-4 h-4 text-emerald-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        {{ session('success') }}
    </div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-5 mb-6">
        <form method="GET" action="{{ route('pos.inventory.stock') }}" class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search products..." class="w-full pl-10 rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2.5 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition">
            </div>
            <select name="filter" class="rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2.5 focus:ring-2 focus:ring-purple-500 transition">
                <option value="">All Stock</option>
                <option value="low" {{ request('filter') === 'low' ? 'selected' : '' }}>Low Stock</option>
                <option value="out" {{ request('filter') === 'out' ? 'selected' : '' }}>Out of Stock</option>
            </select>
            <button type="submit" class="px-5 py-2.5 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">Filter</button>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-100 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/50">
                        <th class="px-5 py-3.5 font-semibold">Product</th>
                        <th class="px-5 py-3.5 font-semibold">Stock Level</th>
                        <th class="px-5 py-3.5 text-right font-semibold hidden sm:table-cell">Min Level</th>
                        <th class="px-5 py-3.5 text-right font-semibold hidden lg:table-cell">Avg Cost</th>
                        <th class="px-5 py-3.5 text-right font-semibold hidden md:table-cell">Stock Value</th>
                        <th class="px-5 py-3.5 text-center font-semibold">Status</th>
                        <th class="px-5 py-3.5 text-right font-semibold hidden sm:table-cell">Min Level Setting</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
                    @forelse($stocks as $stock)
                    @php
                        $statusClass = 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400';
                        $statusText = 'In Stock';
                        $barColor = 'bg-emerald-500';
                        if ($stock->quantity <= 0) {
                            $statusClass = 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
                            $statusText = 'Out of Stock';
                            $barColor = 'bg-red-500';
                        } elseif ($stock->isLowStock()) {
                            $statusClass = 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400';
                            $statusText = 'Low Stock';
                            $barColor = 'bg-amber-500';
                        }
                        $maxLevel = max($stock->min_stock_level * 2, $stock->quantity, 1);
                        $barPct = min(($stock->quantity / $maxLevel) * 100, 100);
                    @endphp
                    <tr class="hover:bg-gray-50/80 dark:hover:bg-gray-800/30 transition">
                        <td class="px-5 py-4 font-semibold text-gray-900 dark:text-white">{{ $stock->product->name ?? 'Unknown' }}</td>
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <span class="font-bold text-sm {{ $stock->quantity <= 0 ? 'text-red-600' : ($stock->isLowStock() ? 'text-amber-600' : 'text-gray-900 dark:text-white') }}">{{ number_format($stock->quantity, 0) }}</span>
                                <div class="w-28 bg-gray-100 dark:bg-gray-700 rounded-full h-2.5 relative">
                                    <div class="h-2.5 rounded-full {{ $barColor }} transition-all duration-500" style="width: {{ max($barPct, 3) }}%"></div>
                                </div>
                                <span class="text-[10px] font-semibold text-gray-400">{{ round($barPct) }}%</span>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-right text-gray-600 dark:text-gray-400 hidden sm:table-cell">{{ number_format($stock->min_stock_level, 0) }}</td>
                        <td class="px-5 py-4 text-right text-gray-600 dark:text-gray-400 hidden lg:table-cell">PKR {{ number_format($stock->avg_purchase_price, 2) }}</td>
                        <td class="px-5 py-4 text-right font-medium text-gray-900 dark:text-white hidden md:table-cell">PKR {{ number_format($stock->quantity * $stock->avg_purchase_price, 0) }}</td>
                        <td class="px-5 py-4 text-center">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-bold {{ $statusClass }}">{{ $statusText }}</span>
                        </td>
                        <td class="px-5 py-4 text-right hidden sm:table-cell" x-data="{ editing: false, minLevel: {{ $stock->min_stock_level }}, saving: false }">
                            <template x-if="!editing">
                                <button @click="editing = true" class="text-xs text-purple-600 hover:text-purple-800 font-semibold transition">Edit</button>
                            </template>
                            <template x-if="editing">
                                <div class="flex items-center justify-end gap-1">
                                    <input type="number" x-model="minLevel" min="0" step="1" class="w-20 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-sm px-2 py-1.5 text-right focus:ring-2 focus:ring-purple-500">
                                    <button @click="saving = true; fetch('/pos/inventory/min-stock', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ product_id: {{ $stock->product_id }}, min_stock_level: minLevel }) }).then(r => r.json()).then(d => { saving = false; editing = false; }).catch(() => { saving = false; })" :disabled="saving" class="px-2.5 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-xs rounded-lg transition font-semibold">
                                        <span x-text="saving ? '...' : 'Save'"></span>
                                    </button>
                                    <button @click="editing = false" class="px-2.5 py-1.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition">X</button>
                                </div>
                            </template>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-5 py-16 text-center">
                            <div class="w-14 h-14 rounded-2xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-3">
                                <svg class="w-7 h-7 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            </div>
                            <p class="text-sm font-medium text-gray-500">No stock records found</p>
                            <p class="text-xs text-gray-400 mt-1">Add stock using the Adjust Stock button above</p>
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
