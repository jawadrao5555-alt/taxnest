<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">Low Stock Alerts</h1>

    <div class="flex flex-wrap gap-2 mb-6">
        <a href="{{ route('pos.inventory.dashboard') }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Dashboard</a>
        <a href="{{ route('pos.inventory.stock') }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Stock Levels</a>
        <a href="{{ route('pos.inventory.movements') }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Movements</a>
        <a href="{{ route('pos.inventory.low-stock') }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-purple-600 text-white">Low Stock Alerts</a>
        <a href="{{ route('pos.inventory.adjust') }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Adjust Stock</a>
    </div>

    @if($outOfStock->count() > 0)
    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl p-5 mb-6">
        <div class="flex items-center gap-2 mb-3">
            <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            <h3 class="text-sm font-semibold text-red-800 dark:text-red-300">Out of Stock ({{ $outOfStock->count() }} products)</h3>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach($outOfStock as $item)
            <div class="flex items-center justify-between bg-white dark:bg-gray-900 rounded-lg p-3 border border-red-100 dark:border-red-800">
                <span class="font-medium text-sm text-gray-900 dark:text-white">{{ $item->product->name ?? 'Unknown' }}</span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400">{{ number_format($item->quantity, 0) }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    @if($alerts->count() > 0)
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Below Minimum Stock Level ({{ $alerts->count() }} products)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                        <th class="px-4 py-3">Product</th>
                        <th class="px-4 py-3 text-right">Current Stock</th>
                        <th class="px-4 py-3 text-right">Min Level</th>
                        <th class="px-4 py-3 text-right">Shortage</th>
                        <th class="px-4 py-3 text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($alerts as $item)
                    <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}">
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $item->product->name ?? 'Unknown' }}</td>
                        <td class="px-4 py-3 text-right font-bold text-amber-600">{{ number_format($item->quantity, 0) }}</td>
                        <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400">{{ number_format($item->min_stock_level, 0) }}</td>
                        <td class="px-4 py-3 text-right font-bold text-red-600">{{ number_format($item->min_stock_level - $item->quantity, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            <a href="{{ route('pos.inventory.adjust') }}?product_id={{ $item->product_id }}" class="text-xs text-purple-600 hover:underline font-medium">Restock</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @else
    @if($outOfStock->count() === 0)
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-12 text-center">
        <svg class="w-12 h-12 mx-auto mb-3 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <p class="text-lg font-semibold text-gray-900 dark:text-white">All stock levels are healthy</p>
        <p class="text-sm text-gray-500 mt-1">No products are below their minimum stock levels.</p>
    </div>
    @endif
    @endif
</div>
</x-pos-layout>
