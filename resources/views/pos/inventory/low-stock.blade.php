<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2 mb-4">
        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/20">
            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        </div>
        Low Stock Alerts
    </h1>

    <div class="flex flex-wrap gap-2 mb-6">
        <a href="{{ route('pos.inventory.dashboard') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Dashboard</a>
        <a href="{{ route('pos.inventory.stock') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Stock Levels</a>
        <a href="{{ route('pos.inventory.movements') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Movements</a>
        <a href="{{ route('pos.inventory.low-stock') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-purple-600 text-white shadow-sm">Low Stock Alerts</a>
        <a href="{{ route('pos.inventory.adjust') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Adjust Stock</a>
    </div>

    @if($outOfStock->count() > 0)
    <div class="bg-gradient-to-r from-red-50 to-rose-50 dark:from-red-900/20 dark:to-rose-900/20 border border-red-200 dark:border-red-700 rounded-2xl p-5 mb-6">
        <div class="flex items-center gap-2 mb-4">
            <div class="w-8 h-8 rounded-lg bg-red-500/20 flex items-center justify-center">
                <svg class="w-4 h-4 text-red-600 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            </div>
            <h3 class="text-sm font-bold text-red-800 dark:text-red-300">Out of Stock ({{ $outOfStock->count() }} products)</h3>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach($outOfStock as $item)
            <div class="flex items-center justify-between bg-white/80 dark:bg-gray-900/80 rounded-xl p-3.5 border border-red-100 dark:border-red-800/50 backdrop-blur-sm">
                <span class="font-semibold text-sm text-gray-900 dark:text-white truncate mr-2">{{ $item->product->name ?? 'Unknown' }}</span>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-bold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400">0</span>
                    <a href="{{ route('pos.inventory.adjust') }}?product_id={{ $item->product_id }}" class="text-xs text-purple-600 hover:text-purple-800 font-bold transition">Restock</a>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    @if($alerts->count() > 0)
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 bg-gradient-to-r from-amber-50 to-orange-50 dark:from-amber-900/10 dark:to-orange-900/10">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <h3 class="text-sm font-bold text-amber-800 dark:text-amber-300">Below Minimum Stock Level ({{ $alerts->count() }} products)</h3>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-100 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/50">
                        <th class="px-5 py-3.5 font-semibold">Product</th>
                        <th class="px-5 py-3.5 font-semibold">Stock Level</th>
                        <th class="px-5 py-3.5 text-right font-semibold">Min Level</th>
                        <th class="px-5 py-3.5 text-right font-semibold">Shortage</th>
                        <th class="px-5 py-3.5 text-center font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
                    @foreach($alerts as $item)
                    @php
                        $pct = $item->min_stock_level > 0 ? min(($item->quantity / $item->min_stock_level) * 100, 100) : 0;
                        $barColor = $pct < 30 ? 'bg-red-500' : ($pct < 70 ? 'bg-amber-500' : 'bg-emerald-500');
                        $shortage = $item->min_stock_level - $item->quantity;
                    @endphp
                    <tr class="hover:bg-gray-50/80 dark:hover:bg-gray-800/30 transition">
                        <td class="px-5 py-4 font-semibold text-gray-900 dark:text-white">{{ $item->product->name ?? 'Unknown' }}</td>
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <span class="font-bold text-amber-600">{{ number_format($item->quantity, 0) }}</span>
                                <div class="w-20 bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                                    <div class="h-2 rounded-full {{ $barColor }} transition-all duration-500" style="width: {{ $pct }}%"></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-right text-gray-600 dark:text-gray-400">{{ number_format($item->min_stock_level, 0) }}</td>
                        <td class="px-5 py-4 text-right">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-bold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">-{{ number_format($shortage, 0) }}</span>
                        </td>
                        <td class="px-5 py-4 text-center">
                            <a href="{{ route('pos.inventory.adjust') }}?product_id={{ $item->product_id }}" class="inline-flex items-center px-3 py-1.5 bg-gradient-to-r from-purple-600 to-violet-600 text-white text-xs font-bold rounded-lg hover:from-purple-700 hover:to-violet-700 transition shadow-sm">
                                <svg class="w-3 h-3 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                Restock
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @else
    @if($outOfStock->count() === 0)
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-16 text-center">
        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-100 to-teal-50 flex items-center justify-center mx-auto mb-4 shadow-lg shadow-emerald-500/10">
            <svg class="w-8 h-8 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="text-xl font-bold text-gray-900 dark:text-white">All Stock Levels Healthy</p>
        <p class="text-sm text-gray-500 mt-2">No products are below their minimum stock levels. Great job keeping inventory in check!</p>
    </div>
    @endif
    @endif
</div>
</x-pos-layout>
