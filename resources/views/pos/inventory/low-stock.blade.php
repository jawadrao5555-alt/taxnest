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
    @php
        $criticalItems = $alerts->filter(fn($i) => $i->min_stock_level > 0 && ($i->quantity / $i->min_stock_level) < 0.25);
        $warningItems = $alerts->filter(fn($i) => $i->min_stock_level > 0 && ($i->quantity / $i->min_stock_level) >= 0.25 && ($i->quantity / $i->min_stock_level) < 0.5);
        $lowItems = $alerts->filter(fn($i) => $i->min_stock_level > 0 && ($i->quantity / $i->min_stock_level) >= 0.5);
    @endphp
    <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-2xl border-2 {{ $criticalItems->count() > 0 ? 'border-red-300 dark:border-red-700' : 'border-gray-100 dark:border-gray-700' }} shadow-lg p-4 text-center">
            <div class="w-10 h-10 rounded-xl {{ $criticalItems->count() > 0 ? 'bg-red-100 dark:bg-red-900/30' : 'bg-gray-100 dark:bg-gray-800' }} flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 {{ $criticalItems->count() > 0 ? 'text-red-600 animate-pulse' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <p class="text-2xl font-black {{ $criticalItems->count() > 0 ? 'text-red-600' : 'text-gray-900 dark:text-white' }}">{{ $criticalItems->count() }}</p>
            <p class="text-[10px] font-bold uppercase tracking-wider {{ $criticalItems->count() > 0 ? 'text-red-500' : 'text-gray-400' }}">Critical (&lt;25%)</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border-2 {{ $warningItems->count() > 0 ? 'border-amber-300 dark:border-amber-700' : 'border-gray-100 dark:border-gray-700' }} shadow-lg p-4 text-center">
            <div class="w-10 h-10 rounded-xl {{ $warningItems->count() > 0 ? 'bg-amber-100 dark:bg-amber-900/30' : 'bg-gray-100 dark:bg-gray-800' }} flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 {{ $warningItems->count() > 0 ? 'text-amber-600' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <p class="text-2xl font-black {{ $warningItems->count() > 0 ? 'text-amber-600' : 'text-gray-900 dark:text-white' }}">{{ $warningItems->count() }}</p>
            <p class="text-[10px] font-bold uppercase tracking-wider {{ $warningItems->count() > 0 ? 'text-amber-500' : 'text-gray-400' }}">Warning (25-50%)</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border-2 {{ $lowItems->count() > 0 ? 'border-yellow-300 dark:border-yellow-700' : 'border-gray-100 dark:border-gray-700' }} shadow-lg p-4 text-center">
            <div class="w-10 h-10 rounded-xl {{ $lowItems->count() > 0 ? 'bg-yellow-100 dark:bg-yellow-900/30' : 'bg-gray-100 dark:bg-gray-800' }} flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 {{ $lowItems->count() > 0 ? 'text-yellow-600' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <p class="text-2xl font-black {{ $lowItems->count() > 0 ? 'text-yellow-600' : 'text-gray-900 dark:text-white' }}">{{ $lowItems->count() }}</p>
            <p class="text-[10px] font-bold uppercase tracking-wider {{ $lowItems->count() > 0 ? 'text-yellow-500' : 'text-gray-400' }}">Low (50-100%)</p>
        </div>
    </div>

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
                        <th class="px-5 py-3.5 font-semibold">Urgency</th>
                        <th class="px-5 py-3.5 font-semibold">Stock Level</th>
                        <th class="px-5 py-3.5 text-right font-semibold">Min Level</th>
                        <th class="px-5 py-3.5 text-right font-semibold">Shortage</th>
                        <th class="px-5 py-3.5 text-center font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
                    @foreach($alerts->sortBy(fn($i) => $i->min_stock_level > 0 ? $i->quantity / $i->min_stock_level : 999) as $item)
                    @php
                        $pct = $item->min_stock_level > 0 ? min(($item->quantity / $item->min_stock_level) * 100, 100) : 0;
                        $barColor = $pct < 25 ? 'bg-red-500' : ($pct < 50 ? 'bg-amber-500' : 'bg-yellow-400');
                        $shortage = $item->min_stock_level - $item->quantity;
                        $urgencyLabel = $pct < 25 ? 'Critical' : ($pct < 50 ? 'Warning' : 'Low');
                        $urgencyClass = $pct < 25 ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 ring-1 ring-red-200 dark:ring-red-800' : ($pct < 50 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 ring-1 ring-amber-200 dark:ring-amber-800' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 ring-1 ring-yellow-200 dark:ring-yellow-800');
                    @endphp
                    <tr class="hover:bg-gray-50/80 dark:hover:bg-gray-800/30 transition {{ $pct < 25 ? 'bg-red-50/30 dark:bg-red-900/5' : '' }}">
                        <td class="px-5 py-4 font-semibold text-gray-900 dark:text-white">{{ $item->product->name ?? 'Unknown' }}</td>
                        <td class="px-5 py-4">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider {{ $urgencyClass }}">
                                @if($pct < 25)<svg class="w-3 h-3 mr-1 animate-pulse" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="10" r="5"/></svg>@endif
                                {{ $urgencyLabel }}
                            </span>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <span class="font-bold {{ $pct < 25 ? 'text-red-600' : 'text-amber-600' }}">{{ number_format($item->quantity, 0) }}</span>
                                <div class="w-24 bg-gray-100 dark:bg-gray-700 rounded-full h-2.5">
                                    <div class="h-2.5 rounded-full {{ $barColor }} transition-all duration-500" style="width: {{ max($pct, 3) }}%"></div>
                                </div>
                                <span class="text-[10px] font-semibold text-gray-400">{{ round($pct) }}%</span>
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
