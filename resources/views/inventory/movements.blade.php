<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Stock Movement History</h2>
            <a href="{{ route('inventory.index') }}" class="inline-flex items-center px-3 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium hover:bg-gray-300 dark:hover:bg-gray-500 transition">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"/></svg>
                Back to Stock
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                    <form method="GET" action="{{ route('inventory.movements') }}" class="flex flex-col sm:flex-row gap-3">
                        <input type="text" name="search" value="{{ $search }}" placeholder="Search product or reference..."
                            class="flex-1 rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        <select name="type" class="rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                            <option value="">All Types</option>
                            <option value="purchase" {{ $typeFilter == 'purchase' ? 'selected' : '' }}>Purchase</option>
                            <option value="sale" {{ $typeFilter == 'sale' ? 'selected' : '' }}>Sale</option>
                            <option value="adjustment_in" {{ $typeFilter == 'adjustment_in' ? 'selected' : '' }}>Adjustment In</option>
                            <option value="adjustment_out" {{ $typeFilter == 'adjustment_out' ? 'selected' : '' }}>Adjustment Out</option>
                            <option value="opening" {{ $typeFilter == 'opening' ? 'selected' : '' }}>Opening</option>
                            <option value="return_in" {{ $typeFilter == 'return_in' ? 'selected' : '' }}>Return In</option>
                            <option value="return_out" {{ $typeFilter == 'return_out' ? 'selected' : '' }}>Return Out</option>
                        </select>
                        <input type="date" name="date_from" value="{{ $dateFrom }}" class="rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        <input type="date" name="date_to" value="{{ $dateTo }}" class="rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Filter</button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Product</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Branch</th>
                                <th class="px-4 py-3 text-right">Qty</th>
                                <th class="px-4 py-3 text-right">Unit Price</th>
                                <th class="px-4 py-3 text-right">Total</th>
                                <th class="px-4 py-3 text-right">Balance</th>
                                <th class="px-4 py-3">Reference</th>
                                <th class="px-4 py-3">By</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($movements as $mv)
                                @php
                                    $isIn = in_array($mv->type, ['purchase', 'adjustment_in', 'return_in', 'transfer_in', 'opening']);
                                    $typeLabels = [
                                        'purchase' => ['Purchase', 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'],
                                        'sale' => ['Sale', 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'],
                                        'adjustment_in' => ['Adjust In', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'],
                                        'adjustment_out' => ['Adjust Out', 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300'],
                                        'opening' => ['Opening', 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300'],
                                        'return_in' => ['Return In', 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
                                        'return_out' => ['Return Out', 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300'],
                                        'transfer_in' => ['Transfer In', 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-300'],
                                        'transfer_out' => ['Transfer Out', 'bg-pink-100 text-pink-700 dark:bg-pink-900/30 dark:text-pink-300'],
                                    ];
                                    $typeInfo = $typeLabels[$mv->type] ?? [ucfirst(str_replace('_', ' ', $mv->type)), 'bg-gray-100 text-gray-700'];
                                @endphp
                                <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/50' : '' }}">
                                    <td class="px-4 py-3 text-xs text-gray-500">{{ $mv->created_at->format('d M Y H:i') }}</td>
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $mv->product->name ?? 'Unknown' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full {{ $typeInfo[1] }}">{{ $typeInfo[0] }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">{{ $mv->branch->name ?? 'Main' }}</td>
                                    <td class="px-4 py-3 text-right font-bold {{ $isIn ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $isIn ? '+' : '-' }}{{ number_format($mv->quantity, $mv->quantity == intval($mv->quantity) ? 0 : 2) }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400">Rs {{ number_format($mv->unit_price, 2) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">Rs {{ number_format($mv->total_price, 0) }}</td>
                                    <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-gray-100">{{ number_format($mv->balance_after, $mv->balance_after == intval($mv->balance_after) ? 0 : 2) }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-500">{{ $mv->reference_number ?: ($mv->notes ? \Illuminate\Support\Str::limit($mv->notes, 30) : '-') }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-500">{{ $mv->creator->name ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No stock movements recorded yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($movements->hasPages())
                    <div class="p-4 border-t border-gray-200 dark:border-gray-700">{{ $movements->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
