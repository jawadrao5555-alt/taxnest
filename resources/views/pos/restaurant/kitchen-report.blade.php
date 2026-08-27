<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-start justify-between gap-4 mb-6 flex-wrap">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Kitchen inventory report</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Recipe consumption, prepared returns, wastage and PRA credit-note reconciliation.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('pos.restaurant.ingredients') }}" class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-sm text-gray-700 dark:text-gray-300">Ingredients</a>
            <a href="{{ route('pos.restaurant.recipes') }}" class="px-4 py-2 rounded-lg border border-purple-300 dark:border-purple-700 text-sm text-purple-700 dark:text-purple-300">Recipes</a>
        </div>
    </div>

    <form method="GET" class="mb-6 flex flex-wrap items-end gap-3 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
        <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">From</label>
            <input type="date" name="from" value="{{ $from }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">To</label>
            <input type="date" name="to" value="{{ $to }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
        </div>
        <button class="px-4 py-2 rounded-lg bg-purple-600 text-white text-sm font-semibold">Apply</button>
        <a href="{{ route('pos.restaurant.kitchen-report') }}" class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-sm text-gray-700 dark:text-gray-300">Clear</a>
    </form>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <p class="text-xs text-gray-500 dark:text-gray-400">Ingredients consumed</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ number_format((float) $consumedQty, 4) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <p class="text-xs text-gray-500 dark:text-gray-400">Normal-restock quantity</p>
            <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">{{ number_format((float) $returnedQty, 4) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <p class="text-xs text-gray-500 dark:text-gray-400">Recorded wastage value</p>
            <p class="text-2xl font-bold text-red-600 dark:text-red-400 mt-1">Rs. {{ number_format((float) $wastageValue, 2) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h2 class="font-semibold text-gray-900 dark:text-white">Food cost by ingredient</h2>
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Rs. {{ number_format((float) $foodCostTotal, 2) }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-xs text-gray-500 dark:text-gray-400"><tr><th class="text-left px-4 py-3">Ingredient</th><th class="text-right px-4 py-3">Consumed</th><th class="text-right px-4 py-3">Cost</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($foodCost as $row)
                        <tr><td class="px-4 py-3 text-gray-900 dark:text-white">{{ $row->ingredient_name }} <span class="text-xs text-gray-400">{{ $row->unit }}</span></td><td class="px-4 py-3 text-right">{{ number_format((float) $row->quantity, 4) }}</td><td class="px-4 py-3 text-right">Rs. {{ number_format((float) $row->cost, 2) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-8 text-center text-gray-500">No recipe cost recorded in this period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h2 class="font-semibold text-gray-900 dark:text-white">Low-stock ingredients</h2>
                <span class="text-sm font-semibold text-red-600 dark:text-red-400">{{ $lowStock->count() }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-xs text-gray-500 dark:text-gray-400"><tr><th class="text-left px-4 py-3">Ingredient</th><th class="text-right px-4 py-3">Current</th><th class="text-right px-4 py-3">Minimum</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($lowStock as $row)
                        <tr><td class="px-4 py-3 text-gray-900 dark:text-white">{{ $row->name }} <span class="text-xs text-gray-400">{{ $row->unit }}</span></td><td class="px-4 py-3 text-right text-red-600 dark:text-red-400">{{ number_format((float) $row->current_stock, 4) }}</td><td class="px-4 py-3 text-right">{{ number_format((float) $row->min_stock_level, 4) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-8 text-center text-emerald-600">No low-stock ingredients.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h2 class="font-semibold text-gray-900 dark:text-white">Return dispositions</h2>
                <span class="text-sm text-gray-500">{{ number_format((float) $returnTotal, 3) }} units</span>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @forelse($returnSummary as $row)
                <div class="px-4 py-3 flex items-center justify-between"><span class="text-gray-700 dark:text-gray-300">{{ str_replace('_', ' ', $row->return_disposition) }}</span><span class="text-sm text-gray-900 dark:text-white">{{ number_format((float) $row->quantity, 3) }} <span class="text-gray-400">/ Rs. {{ number_format((float) $row->subtotal, 2) }}</span></span></div>
            @empty
                <div class="px-4 py-8 text-center text-gray-500">No return dispositions recorded.</div>
            @endforelse
            </div>
        </section>

        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-white">Kitchen movements</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-xs text-gray-500 dark:text-gray-400">
                        <tr><th class="text-left px-4 py-3">Ingredient</th><th class="text-left px-4 py-3">Type</th><th class="text-right px-4 py-3">Qty</th><th class="text-left px-4 py-3">Date</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($movements as $movement)
                        <tr>
                            <td class="px-4 py-3 text-gray-900 dark:text-white">{{ $movement->ingredient_name ?? 'Unknown' }} <span class="text-xs text-gray-400">{{ $movement->unit }}</span></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ str_replace('_', ' ', $movement->type) }}</td>
                            <td class="px-4 py-3 text-right text-gray-900 dark:text-white">{{ number_format((float) $movement->quantity, 4) }}</td>
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $movement->created_at }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No kitchen movements in this period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-white">Prepared cooked-return pool</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-xs text-gray-500 dark:text-gray-400">
                        <tr><th class="text-left px-4 py-3">Product</th><th class="text-right px-4 py-3">Remaining</th><th class="text-left px-4 py-3">Status</th><th class="text-left px-4 py-3">Expires</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($prepared as $row)
                        <tr>
                            <td class="px-4 py-3 text-gray-900 dark:text-white">{{ $row->product_name ?? 'Product #' . $row->product_id }}</td>
                            <td class="px-4 py-3 text-right text-gray-900 dark:text-white">{{ number_format((float) $row->remaining_quantity, 4) }}</td>
                            <td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs {{ $row->status === 'available' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">{{ $row->status }}</span></td>
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $row->expires_at ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No prepared returns recorded.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-white">Wastage / loss</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-xs text-gray-500 dark:text-gray-400">
                        <tr><th class="text-left px-4 py-3">Item</th><th class="text-right px-4 py-3">Qty</th><th class="text-right px-4 py-3">Value</th><th class="text-left px-4 py-3">Return</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($wastage as $row)
                        <tr><td class="px-4 py-3 text-gray-900 dark:text-white">{{ $row->item_name }}</td><td class="px-4 py-3 text-right">{{ number_format((float) $row->quantity, 3) }}</td><td class="px-4 py-3 text-right">Rs. {{ number_format((float) $row->subtotal, 2) }}</td><td class="px-4 py-3 text-gray-500">{{ $row->invoice_number }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No wastage returns recorded.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-white">PRA return reconciliation</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-xs text-gray-500 dark:text-gray-400">
                        <tr><th class="text-left px-4 py-3">Credit note</th><th class="text-left px-4 py-3">Parent</th><th class="text-left px-4 py-3">Status</th><th class="text-left px-4 py-3">PRA #</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($praReturns as $row)
                        <tr><td class="px-4 py-3 text-gray-900 dark:text-white">{{ $row->invoice_number }}</td><td class="px-4 py-3 text-gray-500">{{ $row->parent_invoice_number ?: '—' }}</td><td class="px-4 py-3">{{ $row->pra_status ?: 'local/reporting off' }}</td><td class="px-4 py-3 text-gray-500">{{ $row->pra_invoice_number ?: 'Not submitted' }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No return credit notes recorded.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
</x-pos-layout>