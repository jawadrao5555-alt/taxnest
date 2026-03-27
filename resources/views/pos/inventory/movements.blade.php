<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2 mb-4">
        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/20">
            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
        </div>
        Stock Movements
    </h1>

    <div class="flex flex-wrap gap-2 mb-6">
        <a href="{{ route('pos.inventory.dashboard') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Dashboard</a>
        <a href="{{ route('pos.inventory.stock') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Stock Levels</a>
        <a href="{{ route('pos.inventory.movements') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-purple-600 text-white shadow-sm">Movements</a>
        <a href="{{ route('pos.inventory.low-stock') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Low Stock Alerts</a>
        <a href="{{ route('pos.inventory.adjust') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">Adjust Stock</a>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-5 mb-6">
        <form method="GET" action="{{ route('pos.inventory.movements') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <select name="type" class="rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2.5 focus:ring-2 focus:ring-purple-500 transition">
                <option value="">All Types</option>
                <option value="sale" {{ request('type') === 'sale' ? 'selected' : '' }}>Sale</option>
                <option value="purchase" {{ request('type') === 'purchase' ? 'selected' : '' }}>Purchase</option>
                <option value="adjustment_in" {{ request('type') === 'adjustment_in' ? 'selected' : '' }}>Adjustment In</option>
                <option value="adjustment_out" {{ request('type') === 'adjustment_out' ? 'selected' : '' }}>Adjustment Out</option>
                <option value="opening" {{ request('type') === 'opening' ? 'selected' : '' }}>Opening</option>
            </select>
            <select name="product_id" class="rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2.5 focus:ring-2 focus:ring-purple-500 transition">
                <option value="">All Products</option>
                @foreach($products as $p)
                <option value="{{ $p->id }}" {{ request('product_id') == $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                @endforeach
            </select>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2.5 focus:ring-2 focus:ring-purple-500 transition" placeholder="From">
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2.5 focus:ring-2 focus:ring-purple-500 transition" placeholder="To">
            <div class="flex gap-2">
                <button type="submit" class="flex-1 px-4 py-2.5 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">Filter</button>
                <a href="{{ route('pos.inventory.movements') }}" class="px-3 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 text-sm rounded-xl hover:bg-gray-200 dark:hover:bg-gray-700 transition border border-gray-200 dark:border-gray-700">Clear</a>
            </div>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-100 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/50">
                        <th class="px-5 py-3.5 font-semibold">Date</th>
                        <th class="px-5 py-3.5 font-semibold">Product</th>
                        <th class="px-5 py-3.5 font-semibold">Type</th>
                        <th class="px-5 py-3.5 text-right font-semibold">Qty</th>
                        <th class="px-5 py-3.5 text-right font-semibold">Balance</th>
                        <th class="px-5 py-3.5 font-semibold hidden md:table-cell">Reference</th>
                        <th class="px-5 py-3.5 font-semibold hidden lg:table-cell">By</th>
                        <th class="px-5 py-3.5 font-semibold hidden lg:table-cell">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
                    @forelse($movements as $m)
                    @php
                        $isOut = in_array($m->type, ['sale', 'adjustment_out', 'return_out', 'transfer_out']);
                        $typeColors = [
                            'sale' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                            'purchase' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
                            'adjustment_in' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                            'adjustment_out' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
                            'opening' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
                        ];
                    @endphp
                    <tr class="hover:bg-gray-50/80 dark:hover:bg-gray-800/30 transition">
                        <td class="px-5 py-3.5 text-gray-600 dark:text-gray-400 whitespace-nowrap text-xs">{{ $m->created_at->format('d M Y H:i') }}</td>
                        <td class="px-5 py-3.5 font-semibold text-gray-900 dark:text-white">{{ $m->product->name ?? 'Unknown' }}</td>
                        <td class="px-5 py-3.5 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-bold {{ $typeColors[$m->type] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ ucwords(str_replace('_', ' ', $m->type)) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right font-bold {{ $isOut ? 'text-red-500' : 'text-emerald-600' }}">{{ $isOut ? '-' : '+' }}{{ number_format($m->quantity, 0) }}</td>
                        <td class="px-5 py-3.5 text-right font-medium text-gray-900 dark:text-white">{{ number_format($m->balance_after, 0) }}</td>
                        <td class="px-5 py-3.5 text-gray-500 dark:text-gray-400 text-xs hidden md:table-cell">{{ $m->reference_number ?? ($m->reference_type ? ucwords(str_replace('_', ' ', $m->reference_type)) : '—') }}</td>
                        <td class="px-5 py-3.5 text-gray-600 dark:text-gray-400 hidden lg:table-cell">{{ $m->creator->name ?? '—' }}</td>
                        <td class="px-5 py-3.5 text-gray-500 dark:text-gray-500 text-xs max-w-[200px] truncate hidden lg:table-cell">{{ $m->notes ?? '—' }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-5 py-16 text-center">
                            <div class="w-14 h-14 rounded-2xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-3">
                                <svg class="w-7 h-7 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
                            </div>
                            <p class="text-sm font-medium text-gray-500">No movements found</p>
                            <p class="text-xs text-gray-400 mt-1">Stock movements will appear here as transactions occur</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($movements->hasPages())
    <div class="mt-4">{{ $movements->links() }}</div>
    @endif
</div>
</x-pos-layout>
