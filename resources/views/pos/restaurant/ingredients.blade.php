<x-pos-layout>
<div x-data="{ showAddModal: false, showAdjustModal: false, adjustId: null, adjustName: '' }" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Ingredients</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Manage raw ingredients for recipes</p>
        </div>
        <button @click="showAddModal = true" class="px-4 py-2 rounded-lg bg-gradient-to-r from-purple-600 to-violet-600 text-white text-sm font-semibold hover:from-purple-700 hover:to-violet-700">+ Add Ingredient</button>
    </div>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif

    @if($ingredients->count() > 0)
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($ingredients as $ingredient)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="p-4">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">{{ $ingredient->name }}</h3>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Unit: {{ $ingredient->unit }}</span>
                    </div>
                    @if(!$ingredient->is_active)
                    <span class="text-xs bg-red-100 dark:bg-red-900/30 text-red-600 px-2 py-0.5 rounded-full">Inactive</span>
                    @endif
                </div>
                <div class="space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500 dark:text-gray-400">Current Stock</span>
                        <span class="font-medium {{ $ingredient->isLowStock() ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white' }}">{{ number_format($ingredient->current_stock, 2) }} {{ $ingredient->unit }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500 dark:text-gray-400">Min Level</span>
                        <span class="text-gray-700 dark:text-gray-300">{{ number_format($ingredient->min_stock_level, 2) }} {{ $ingredient->unit }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500 dark:text-gray-400">Cost/Unit</span>
                        <span class="text-gray-700 dark:text-gray-300">Rs. {{ number_format($ingredient->cost_per_unit, 2) }}</span>
                    </div>
                    @if($ingredient->isLowStock())
                    <div class="flex items-center gap-1 text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded-lg px-2 py-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        Low stock alert!
                    </div>
                    @endif
                    @php
                        $pct = $ingredient->min_stock_level > 0 ? min(100, ($ingredient->current_stock / ($ingredient->min_stock_level * 3)) * 100) : 100;
                    @endphp
                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                        <div class="h-2 rounded-full transition-all {{ $ingredient->isLowStock() ? 'bg-red-500' : 'bg-green-500' }}" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
            </div>
            <div class="px-4 py-3 bg-gray-50 dark:bg-gray-800/50 border-t border-gray-100 dark:border-gray-700 flex gap-2">
                <button @click="adjustId = {{ $ingredient->id }}; adjustName = '{{ $ingredient->name }}'; showAdjustModal = true" class="flex-1 py-1.5 text-xs rounded-lg bg-purple-600 text-white hover:bg-purple-700 font-medium">Adjust Stock</button>
                <form method="POST" action="{{ route('pos.restaurant.ingredients.delete', $ingredient->id) }}" onsubmit="return confirm('Delete?')" class="inline">
                    @csrf @method('DELETE')
                    <button class="py-1.5 px-3 text-xs rounded-lg border border-red-300 text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400">Delete</button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
    @else
    <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">No Ingredients Yet</h3>
        <p class="text-gray-500 dark:text-gray-400 mb-4">Add ingredients to build recipes.</p>
    </div>
    @endif

    <div x-show="showAddModal" x-transition.opacity class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showAddModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md">
            <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Add Ingredient</h3>
            </div>
            <form method="POST" action="{{ route('pos.restaurant.ingredients.store') }}" class="p-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                    <input type="text" name="name" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white" placeholder="e.g., Chicken Breast">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Unit</label>
                        <select name="unit" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                            <option value="kg">Kilogram (kg)</option>
                            <option value="g">Gram (g)</option>
                            <option value="ltr">Liter (ltr)</option>
                            <option value="ml">Milliliter (ml)</option>
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="dozen">Dozen</option>
                            <option value="pack">Pack</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cost/Unit (Rs.)</label>
                        <input type="number" name="cost_per_unit" step="0.01" min="0" value="0" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Opening Stock</label>
                        <input type="number" name="current_stock" step="0.01" min="0" value="0" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Min Stock Level</label>
                        <input type="number" name="min_stock_level" step="0.01" min="0" value="0" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    </div>
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 py-2.5 rounded-lg bg-purple-600 text-white hover:bg-purple-700 text-sm font-semibold">Add Ingredient</button>
                    <button type="button" @click="showAddModal = false" class="px-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div x-show="showAdjustModal" x-transition.opacity class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showAdjustModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm">
            <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Adjust Stock</h3>
                <p class="text-sm text-gray-500" x-text="adjustName"></p>
            </div>
            <form method="POST" :action="'/pos/restaurant/ingredients/' + adjustId + '/adjust'" class="p-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Adjustment (+ to add, - to deduct)</label>
                    <input type="number" name="adjustment" step="0.01" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white" placeholder="e.g., 10 or -5">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason</label>
                    <input type="text" name="reason" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white" placeholder="e.g., Purchase, Wastage, Count correction">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 py-2.5 rounded-lg bg-purple-600 text-white hover:bg-purple-700 text-sm font-semibold">Adjust</button>
                    <button type="button" @click="showAdjustModal = false" class="px-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
</x-pos-layout>
