<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">Stock Movements</h1>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <form method="GET" action="{{ route('pos.inventory.movements') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <select name="type" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                <option value="">All Types</option>
                <option value="sale" {{ request('type') === 'sale' ? 'selected' : '' }}>Sale</option>
                <option value="purchase" {{ request('type') === 'purchase' ? 'selected' : '' }}>Purchase</option>
                <option value="adjustment_in" {{ request('type') === 'adjustment_in' ? 'selected' : '' }}>Adjustment In</option>
                <option value="adjustment_out" {{ request('type') === 'adjustment_out' ? 'selected' : '' }}>Adjustment Out</option>
                <option value="opening" {{ request('type') === 'opening' ? 'selected' : '' }}>Opening</option>
            </select>
            <select name="product_id" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                <option value="">All Products</option>
                @foreach($products as $p)
                <option value="{{ $p->id }}" {{ request('product_id') == $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                @endforeach
            </select>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition" placeholder="From">
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition" placeholder="To">
            <div class="flex gap-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-lg transition">Filter</button>
                <a href="{{ route('pos.inventory.movements') }}" class="px-3 py-2 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 text-sm rounded-lg hover:bg-gray-200 dark:hover:bg-gray-700 transition">Clear</a>
            </div>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Product</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3 text-right">Qty</th>
                        <th class="px-4 py-3 text-right">Balance After</th>
                        <th class="px-4 py-3">Reference</th>
                        <th class="px-4 py-3">By</th>
                        <th class="px-4 py-3">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($movements as $m)
                    @php
                        $isOut = in_array($m->type, ['sale', 'adjustment_out', 'return_out', 'transfer_out']);
                        $typeColors = [
                            'sale' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
                            'purchase' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
                            'adjustment_in' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
                            'adjustment_out' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
                            'opening' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
                        ];
                    @endphp
                    <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}">
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $m->created_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $m->product->name ?? 'Unknown' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $typeColors[$m->type] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ ucwords(str_replace('_', ' ', $m->type)) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-bold {{ $isOut ? 'text-red-500' : 'text-emerald-600' }}">{{ $isOut ? '-' : '+' }}{{ number_format($m->quantity, 0) }}</td>
                        <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-white">{{ number_format($m->balance_after, 0) }}</td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400 text-xs">{{ $m->reference_number ?? ($m->reference_type ? ucwords(str_replace('_', ' ', $m->reference_type)) : '—') }}</td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $m->creator->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-500 text-xs max-w-[200px] truncate">{{ $m->notes ?? '—' }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-gray-400">No movements found.</td>
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
