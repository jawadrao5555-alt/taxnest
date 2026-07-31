<x-pos-layout>
<div x-data="{
        showAddModal: false,
        q: '',
        recipeNames: {{ json_encode($recipes->map(fn($g) => mb_strtolower($g->first()->product->name ?? ''))->values(), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' }},
        selectedProduct: '',
        rows: [],
        newRow() { return { mode: 'existing', ingredient_id: '', name: '', unit: 'kg', cost: '', qty: '' } },
        addRow() { this.rows.push(this.newRow()) },
        removeRow(i) { this.rows.splice(i, 1); if (this.rows.length === 0) this.addRow() },
        openAdd(pid = '') { this.selectedProduct = String(pid); if (this.rows.length === 0) this.addRow(); this.showAddModal = true }
    }" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.recipes_bom') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.recipes_subtitle') }}</p>
        </div>
        <button @click="openAdd()" class="px-4 py-2 rounded-lg bg-purple-600 text-white text-sm font-semibold hover:bg-purple-700">{{ __('pos.add_recipe_btn') }}</button>
    </div>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif

    @if($recipes->count() > 0)
    <div class="mb-4 relative w-full sm:max-w-md">
        <svg class="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input type="text" x-model="q" placeholder="{{ __('pos.ph_search_recipes') }}" autocomplete="off" name="recipe_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
               class="w-full pl-10 pr-9 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
        <button type="button" x-show="q" x-cloak @click="q = ''" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" aria-label="{{ __('pos.clear_search') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <div x-show="q.trim() && !recipeNames.some(n => n.includes(q.trim().toLowerCase()))" x-cloak class="mb-4 p-4 rounded-xl bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-sm text-gray-500 dark:text-gray-400 text-center">
        {{ __('pos.no_recipes_match') }} "<span class="font-semibold" x-text="q"></span>"
    </div>
    <div class="space-y-4">
        @foreach($recipes as $productId => $productRecipes)
        @php $product = $productRecipes->first()->product; @endphp
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
             data-search="{{ mb_strtolower($product->name ?? '') }}"
             x-show="!q.trim() || $el.dataset.search.includes(q.trim().toLowerCase())">
            <div class="px-5 py-4 bg-gray-50 dark:bg-gray-800/80 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="text-lg">🍳</span>
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">{{ $product->name ?? __('pos.unknown_product') }}</h3>
                        <span class="text-xs text-gray-500">{{ $productRecipes->count() }}{{ __('pos.sfx_ingredients') }}</span>
                    </div>
                </div>
                @php
                    $totalCost = $productRecipes->sum(fn($r) => $r->quantity_needed * ($r->ingredient->cost_per_unit ?? 0));
                @endphp
                <div class="flex items-center gap-3">
                    <span class="text-sm font-semibold text-purple-600 dark:text-purple-400">{{ __('pos.cost_rs_prefix') }}{{ number_format($totalCost, 2) }}</span>
                    <button @click="openAdd('{{ $productId }}')" class="px-3 py-1.5 text-xs rounded-lg border border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/20 font-semibold">{{ __('pos.add_ingredient_btn') }}</button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm table-cards">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-100 dark:border-gray-700">
                            <th class="px-5 py-2">{{ __('pos.ingredient_label') }}</th>
                            <th class="px-5 py-2">{{ __('pos.qty_needed') }}</th>
                            <th class="px-5 py-2">{{ __('pos.unit_label') }}</th>
                            <th class="px-5 py-2">{{ __('pos.cost_label') }}</th>
                            <th class="px-5 py-2">{{ __('pos.stock_label') }}</th>
                            <th class="px-5 py-2 text-right">{{ __('pos.actions_label') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($productRecipes as $recipe)
                        <tr class="border-b border-gray-50 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="px-5 py-3 font-medium text-gray-900 dark:text-white">{{ $recipe->ingredient->name ?? __('pos.unknown_word') }}</td>
                            <td class="px-5 py-3">
                                <form method="POST" action="{{ route('pos.restaurant.recipes.update', $recipe->id) }}" class="flex items-center gap-1.5">
                                    @csrf @method('PUT')
                                    <input type="number" name="quantity_needed" value="{{ $recipe->quantity_needed + 0 }}" step="0.0001" min="0.0001" required class="w-24 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm py-1">
                                    <button class="text-xs font-semibold text-purple-600 hover:text-purple-800 dark:text-purple-400">{{ __('pos.save_btn') }}</button>
                                </form>
                            </td>
                            <td class="px-5 py-3 text-gray-500">{{ $recipe->ingredient->unit ?? '' }}</td>
                            <td class="px-5 py-3">Rs. {{ number_format($recipe->quantity_needed * ($recipe->ingredient->cost_per_unit ?? 0), 2) }}</td>
                            <td class="px-5 py-3">
                                @if($recipe->ingredient && $recipe->ingredient->isLowStock())
                                <span class="text-red-600 dark:text-red-400 font-medium">{{ number_format($recipe->ingredient->current_stock, 2) }}</span>
                                @else
                                <span class="text-green-600 dark:text-green-400">{{ number_format($recipe->ingredient->current_stock ?? 0, 2) }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right">
                                <form method="POST" action="{{ route('pos.restaurant.recipes.delete', $recipe->id) }}" onsubmit="return confirm({{ Js::from(__('pos.confirm_remove_q')) }})" class="inline">
                                    @csrf @method('DELETE')
                                    <button class="text-red-500 hover:text-red-700 text-xs font-medium">{{ __('pos.remove_word') }}</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endforeach
    </div>
    @else
    <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">{{ __('pos.no_recipes_yet') }}</h3>
        <p class="text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.no_recipes_yet_hint') }}</p>
    </div>
    @endif

    <div x-show="showAddModal" x-transition.opacity x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showAddModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.add_recipe_ingredients') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.add_recipe_ingredients_hint') }}</p>
            </div>
            <form method="POST" action="{{ route('pos.restaurant.recipes.store') }}" class="p-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.product_label') }}</label>
                    <select name="product_id" x-model="selectedProduct" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        <option value="">{{ __('pos.select_product_opt') }}</option>
                        @foreach($products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Multi-ingredient repeater --}}
                <template x-for="(row, i) in rows" :key="i">
                    <div class="border-2 border-dashed border-purple-300 dark:border-purple-700 rounded-lg p-3 bg-purple-50/30 dark:bg-purple-900/10 space-y-2">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold text-purple-700 dark:text-purple-300" x-text="{{ Js::from(__('pos.ingredient_hash')) }} + (i + 1)"></span>
                            <div class="flex-1"></div>
                            <button type="button" @click="row.mode = 'existing'"
                                    :class="row.mode === 'existing' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300'"
                                    class="px-3 py-1 text-xs font-semibold rounded-md border border-purple-300 dark:border-purple-700">
                                {{ __('pos.existing_btn') }}
                            </button>
                            <button type="button" @click="row.mode = 'new'"
                                    :class="row.mode === 'new' ? 'bg-emerald-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300'"
                                    class="px-3 py-1 text-xs font-semibold rounded-md border border-emerald-300 dark:border-emerald-700">
                                {{ __('pos.new_short') }}
                            </button>
                            <button type="button" @click="removeRow(i)" x-show="rows.length > 1"
                                    class="px-2 py-1 text-xs rounded-md border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">✕</button>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 items-end">
                            <div class="sm:col-span-2" x-show="row.mode === 'existing'">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.ingredient_label') }}</label>
                                <select :name="'ingredients[' + i + '][ingredient_id]'" x-model="row.ingredient_id" :required="row.mode === 'existing'" :disabled="row.mode !== 'existing'" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                                    <option value="">{{ __('pos.select_ingredient_opt') }}</option>
                                    @foreach($ingredients as $ingredient)
                                    <option value="{{ $ingredient->id }}">{{ $ingredient->name }} ({{ $ingredient->unit }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2 grid grid-cols-3 gap-2" x-show="row.mode === 'new'">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.new_name_label') }}</label>
                                    <input type="text" :name="'ingredients[' + i + '][new_name]'" x-model="row.name" :required="row.mode === 'new'" :disabled="row.mode !== 'new'"
                                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm" placeholder="{{ __('pos.ph_eg_atta') }}">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.unit_label') }}</label>
                                    <select :name="'ingredients[' + i + '][new_unit]'" x-model="row.unit" :disabled="row.mode !== 'new'" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                                        <option value="kg">kg</option>
                                        <option value="g">g</option>
                                        <option value="ltr">ltr</option>
                                        <option value="ml">ml</option>
                                        <option value="pcs">pcs</option>
                                        <option value="dozen">dozen</option>
                                        <option value="pack">pack</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.cost_per_unit') }}</label>
                                    <input type="number" :name="'ingredients[' + i + '][new_cost]'" x-model="row.cost" step="0.01" min="0" :disabled="row.mode !== 'new'"
                                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm" placeholder="0.00">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.qty_needed') }}</label>
                                <input type="number" :name="'ingredients[' + i + '][quantity_needed]'" x-model="row.qty" step="0.0001" min="0.0001" required
                                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm" placeholder="{{ __('pos.ph_eg_025') }}">
                            </div>
                        </div>
                    </div>
                </template>

                <button type="button" @click="addRow()" class="w-full py-2 rounded-lg border-2 border-dashed border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300 text-sm font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20">
                    {{ __('pos.add_another_ingredient') }}
                </button>

                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 py-2.5 rounded-lg bg-purple-600 text-white hover:bg-purple-700 text-sm font-semibold">{{ __('pos.save_recipe') }}</button>
                    <button type="button" @click="showAddModal = false" class="px-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm">{{ __('pos.cancel') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
</x-pos-layout>
