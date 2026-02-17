<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Inventory Management</h2>
            <div class="flex items-center gap-2">
                <a href="{{ route('inventory.movements') }}" class="inline-flex items-center px-3 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
                    Movement History
                </a>
                <button onclick="document.getElementById('adjustModal').classList.remove('hidden')" class="inline-flex items-center px-3 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Stock Adjustment
                </button>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300 rounded-lg">{{ session('error') }}</div>
            @endif

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $stats['total_products'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Total Products</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">Rs {{ number_format($stats['total_value'], 0) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Total Value</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $stats['low_stock'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Low Stock</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ $stats['out_of_stock'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Out of Stock</p>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                    <form method="GET" action="{{ route('inventory.index') }}" class="flex flex-col sm:flex-row gap-3">
                        <input type="text" name="search" value="{{ $search }}" placeholder="Search product name or HS code..."
                            class="flex-1 rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        <select name="branch_id" class="rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                            <option value="">All Branches</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ $branchFilter == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        <select name="stock_filter" class="rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                            <option value="">All Stock</option>
                            <option value="in" {{ $stockFilter == 'in' ? 'selected' : '' }}>In Stock</option>
                            <option value="low" {{ $stockFilter == 'low' ? 'selected' : '' }}>Low Stock</option>
                            <option value="out" {{ $stockFilter == 'out' ? 'selected' : '' }}>Out of Stock</option>
                        </select>
                        <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Filter</button>
                        @if($search || $branchFilter || $stockFilter)
                            <a href="{{ route('inventory.index') }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium hover:bg-gray-300 dark:hover:bg-gray-500 transition text-center">Clear</a>
                        @endif
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Product</th>
                                <th class="px-4 py-3">HS Code</th>
                                <th class="px-4 py-3">Branch</th>
                                <th class="px-4 py-3 text-right">Qty</th>
                                <th class="px-4 py-3 text-right">Min Level</th>
                                <th class="px-4 py-3 text-right">Avg Price</th>
                                <th class="px-4 py-3 text-right">Value</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($stocks as $stock)
                                <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/50' : '' }}">
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $stock->product->name ?? 'Unknown' }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $stock->product->hs_code ?? '-' }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 text-xs">{{ $stock->branch->name ?? 'Main' }}</td>
                                    <td class="px-4 py-3 text-right font-bold {{ $stock->quantity <= 0 ? 'text-red-600' : ($stock->isLowStock() ? 'text-amber-600' : 'text-gray-900 dark:text-gray-100') }}">
                                        {{ number_format($stock->quantity, $stock->quantity == intval($stock->quantity) ? 0 : 2) }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-500 text-xs">{{ $stock->min_stock_level > 0 ? number_format($stock->min_stock_level, 0) : '-' }}</td>
                                    <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400">Rs {{ number_format($stock->avg_purchase_price, 2) }}</td>
                                    <td class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-300">Rs {{ number_format($stock->quantity * $stock->avg_purchase_price, 0) }}</td>
                                    <td class="px-4 py-3">
                                        @if($stock->quantity <= 0)
                                            <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">Out</span>
                                        @elseif($stock->isLowStock())
                                            <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">Low</span>
                                        @else
                                            <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300">OK</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-1.5">
                                            <a href="{{ route('inventory.product-movements', $stock->product_id) }}" class="text-blue-600 hover:text-blue-800 dark:text-blue-400" title="View History">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            </a>
                                            <button onclick="openMinStockModal({{ $stock->id }}, {{ $stock->min_stock_level }}, {{ $stock->max_stock_level ?? 0 }})" class="text-purple-600 hover:text-purple-800 dark:text-purple-400" title="Set Min/Max">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                        No inventory records yet. Use "Stock Adjustment" to add opening stock, or stock will be tracked automatically when purchase orders are received.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($stocks->hasPages())
                    <div class="p-4 border-t border-gray-200 dark:border-gray-700">{{ $stocks->links() }}</div>
                @endif
            </div>
        </div>
    </div>

    @php $products = \App\Models\Product::where('company_id', auth()->user()->company_id)->where('is_active', true)->orderBy('name')->get(); @endphp

    <div id="adjustModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/50" onclick="document.getElementById('adjustModal').classList.add('hidden')"></div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-lg w-full relative z-10">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Stock Adjustment</h3>
                    <button onclick="document.getElementById('adjustModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <form method="POST" action="{{ route('inventory.adjust') }}" class="p-6 space-y-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Product *</label>
                        <select name="product_id" required class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                            <option value="">Select Product</option>
                            @foreach($products as $p)
                                <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->hs_code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Branch</label>
                        <select name="branch_id" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                            <option value="">Main / Default</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Adjustment Type *</label>
                        <select name="type" required class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                            <option value="opening">Opening Stock</option>
                            <option value="adjustment_in">Stock In (Add)</option>
                            <option value="adjustment_out">Stock Out (Deduct)</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Quantity *</label>
                            <input type="number" name="quantity" required step="0.01" min="0.01" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Unit Price (Rs)</label>
                            <input type="number" name="unit_price" step="0.01" min="0" value="0" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notes</label>
                        <textarea name="notes" rows="2" placeholder="Reason for adjustment..." class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></textarea>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="document.getElementById('adjustModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Save Adjustment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="minStockModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/50" onclick="document.getElementById('minStockModal').classList.add('hidden')"></div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-sm w-full relative z-10">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Set Stock Levels</h3>
                </div>
                <form id="minStockForm" method="POST" class="p-6 space-y-4">
                    @csrf @method('PUT')
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Minimum Stock Level</label>
                        <input type="number" name="min_stock_level" id="minStockInput" step="0.01" min="0" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        <p class="text-xs text-gray-500 mt-1">Alert when stock goes below this level</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Maximum Stock Level</label>
                        <input type="number" name="max_stock_level" id="maxStockInput" step="0.01" min="0" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="document.getElementById('minStockModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-medium hover:bg-purple-700 transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openMinStockModal(stockId, minLevel, maxLevel) {
            document.getElementById('minStockForm').action = '/inventory/stock/' + stockId + '/min-stock';
            document.getElementById('minStockInput').value = minLevel || 0;
            document.getElementById('maxStockInput').value = maxLevel || '';
            document.getElementById('minStockModal').classList.remove('hidden');
        }
    </script>
</x-app-layout>
