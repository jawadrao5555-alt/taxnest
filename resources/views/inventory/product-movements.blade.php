<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ $product->name }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">HS: {{ $product->hs_code }} | Stock Movement History</p>
            </div>
            <a href="{{ route('inventory.index') }}" class="inline-flex items-center px-3 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium hover:bg-gray-300 dark:hover:bg-gray-500 transition">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"/></svg>
                Back
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if($stock)
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold {{ $stock->quantity <= 0 ? 'text-red-600' : ($stock->isLowStock() ? 'text-amber-600' : 'text-emerald-600') }}">{{ number_format($stock->quantity, $stock->quantity == intval($stock->quantity) ? 0 : 2) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Current Stock</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold text-blue-600">Rs {{ number_format($stock->avg_purchase_price, 2) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Avg Purchase Price</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold text-purple-600">Rs {{ number_format($stock->last_purchase_price, 2) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Last Purchase Price</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold text-gray-700 dark:text-gray-300">Rs {{ number_format($stock->quantity * $stock->avg_purchase_price, 0) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Stock Value</p>
                </div>
            </div>
            @endif

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Branch</th>
                                <th class="px-4 py-3 text-right">Qty</th>
                                <th class="px-4 py-3 text-right">Unit Price</th>
                                <th class="px-4 py-3 text-right">Balance After</th>
                                <th class="px-4 py-3">Reference</th>
                                <th class="px-4 py-3">Notes</th>
                                <th class="px-4 py-3">By</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($movements as $mv)
                                @php
                                    $isIn = in_array($mv->type, ['purchase', 'adjustment_in', 'return_in', 'transfer_in', 'opening']);
                                @endphp
                                <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/50' : '' }}">
                                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $mv->created_at->format('d M Y H:i') }}</td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full {{ $isIn ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' }}">
                                            {{ ucfirst(str_replace('_', ' ', $mv->type)) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">{{ $mv->branch->name ?? 'Main' }}</td>
                                    <td class="px-4 py-3 text-right font-bold {{ $isIn ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $isIn ? '+' : '-' }}{{ number_format($mv->quantity, 0) }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400">Rs {{ number_format($mv->unit_price, 2) }}</td>
                                    <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-gray-100">{{ number_format($mv->balance_after, 0) }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $mv->reference_number ?: '-' }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 max-w-[150px] truncate">{{ $mv->notes ?: '-' }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $mv->creator->name ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No movements recorded for this product.</td></tr>
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
