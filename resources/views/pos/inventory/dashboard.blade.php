<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Inventory Dashboard</h1>
        <a href="{{ route('pos.inventory.adjust') }}" class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-lg transition shadow-sm">
            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Adjust Stock
        </a>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Total Products</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($totalProducts) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Stock Value</p>
            <p class="text-2xl font-bold text-emerald-600">PKR {{ number_format($totalStockValue, 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Low Stock Items</p>
            <p class="text-2xl font-bold {{ $lowStockItems->count() > 0 ? 'text-amber-600' : 'text-gray-900 dark:text-white' }}">{{ $lowStockItems->count() }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Out of Stock</p>
            <p class="text-2xl font-bold {{ $outOfStockCount > 0 ? 'text-red-600' : 'text-gray-900 dark:text-white' }}">{{ $outOfStockCount }}</p>
        </div>
    </div>

    @if($lowStockItems->count() > 0)
    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl p-5 mb-6">
        <div class="flex items-center gap-2 mb-3">
            <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            <h3 class="text-sm font-semibold text-amber-800 dark:text-amber-300">Low Stock Alerts</h3>
        </div>
        <div class="space-y-2">
            @foreach($lowStockItems->take(5) as $item)
            <div class="flex items-center justify-between text-sm">
                <span class="text-amber-900 dark:text-amber-200 font-medium">{{ $item->product->name ?? 'Unknown' }}</span>
                <div class="flex items-center gap-3">
                    <span class="text-amber-700 dark:text-amber-400">Stock: <strong>{{ number_format($item->quantity, 0) }}</strong></span>
                    <span class="text-amber-600 dark:text-amber-500 text-xs">Min: {{ number_format($item->min_stock_level, 0) }}</span>
                </div>
            </div>
            @endforeach
            @if($lowStockItems->count() > 5)
            <a href="{{ route('pos.inventory.low-stock') }}" class="text-xs text-amber-700 hover:underline">View all {{ $lowStockItems->count() }} alerts &rarr;</a>
            @endif
        </div>
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Recent Movements</h3>
            <div class="space-y-3">
                @forelse($recentMovements as $m)
                <div class="flex items-center justify-between text-sm border-b border-gray-50 dark:border-gray-800 pb-2">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">{{ $m->product->name ?? 'Unknown' }}</p>
                        <p class="text-xs text-gray-500">{{ ucwords(str_replace('_', ' ', $m->type)) }} &middot; {{ $m->created_at->diffForHumans() }}</p>
                    </div>
                    <span class="font-semibold {{ in_array($m->type, ['sale', 'adjustment_out', 'return_out', 'transfer_out']) ? 'text-red-500' : 'text-emerald-600' }}">
                        {{ in_array($m->type, ['sale', 'adjustment_out', 'return_out', 'transfer_out']) ? '-' : '+' }}{{ number_format($m->quantity, 0) }}
                    </span>
                </div>
                @empty
                <p class="text-sm text-gray-400 text-center py-4">No movements recorded yet</p>
                @endforelse
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Top Selling Products (30 Days)</h3>
            <div class="space-y-3">
                @forelse($topMovers as $i => $m)
                <div class="flex items-center justify-between text-sm border-b border-gray-50 dark:border-gray-800 pb-2">
                    <div class="flex items-center gap-2">
                        <span class="w-5 h-5 rounded-full bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center text-xs font-bold text-purple-700 dark:text-purple-400">{{ $i + 1 }}</span>
                        <span class="font-medium text-gray-900 dark:text-white">{{ $m->product->name ?? 'Unknown' }}</span>
                    </div>
                    <span class="font-semibold text-gray-700 dark:text-gray-300">{{ number_format($m->total_sold, 0) }} sold</span>
                </div>
                @empty
                <p class="text-sm text-gray-400 text-center py-4">No sales data yet</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
</x-pos-layout>
