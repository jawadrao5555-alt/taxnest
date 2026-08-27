<x-pos-layout>
<div x-data="{ showAddModal: false, showAdjustModal: false, adjustId: null, adjustName: '', showEditModal: false, edit: { id: null, code: '', name: '', unit: '', base_unit: '', conversion_factor: 1, cost: 0, min: 0, active: true }, openEdit(d) { this.edit = d; this.showEditModal = true }, q: '', ingredientNames: {{ json_encode($ingredients->map(fn($i) => mb_strtolower($i->name ?? ''))->values(), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' }} }" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.ingredients') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.ingredients_subtitle') }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('pos.restaurant.kitchen-report') }}" class="px-4 py-2 rounded-lg border border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300 text-sm font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20">Kitchen report</a>
            <button @click="showAddModal = true" class="px-4 py-2 rounded-lg bg-purple-600 text-white text-sm font-semibold hover:bg-purple-700">{{ __('pos.add_ingredient_btn') }}</button>
        </div>
    </div>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif

    @if($ingredients->count() > 0)
    <div class="mb-4 relative w-full sm:max-w-md">
        <svg class="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input type="text" x-model="q" placeholder="{{ __('pos.ph_search_ingredients') }}" autocomplete="off" name="ingredient_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
               class="w-full pl-10 pr-9 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
        <button type="button" x-show="q" x-cloak @click="q = ''" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" aria-label="{{ __('pos.clear_search') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <div x-show="q.trim() && !ingredientNames.some(n => n.includes(q.trim().toLowerCase()))" x-cloak class="mb-4 p-4 rounded-xl bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-sm text-gray-500 dark:text-gray-400 text-center">
        {{ __('pos.no_ingredients_match') }} "<span class="font-semibold" x-text="q"></span>"
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($ingredients as $ingredient)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
             data-search="{{ mb_strtolower($ingredient->name ?? '') }}"
             x-show="!q.trim() || $el.dataset.search.includes(q.trim().toLowerCase())">
            <div class="p-4">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">{{ $ingredient->name }}</h3>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.unit_colon') }} {{ $ingredient->unit }}</span>
                    </div>
                    @if(!$ingredient->is_active)
                    <span class="text-xs bg-red-100 dark:bg-red-900/30 text-red-600 px-2 py-0.5 rounded-full">{{ __('pos.inactive_word') }}</span>
                    @endif
                </div>
                <div class="space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.current_stock') }}</span>
                        <span class="font-medium {{ $ingredient->isLowStock() ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white' }}">{{ number_format($ingredient->current_stock, 4) }} {{ $ingredient->unit }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.min_level') }}</span>
                        <span class="text-gray-700 dark:text-gray-300">{{ number_format($ingredient->min_stock_level, 2) }} {{ $ingredient->unit }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('pos.cost_per_unit') }}</span>
                        <span class="text-gray-700 dark:text-gray-300">Rs. {{ number_format($ingredient->cost_per_unit, 2) }}</span>
                    </div>
                    @if($ingredient->isLowStock())
                    <div class="flex items-center gap-1 text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded-lg px-2 py-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        {{ __('pos.low_stock_alert') }}
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
                <button @click='openEdit({{ json_encode(['id' => $ingredient->id, 'code' => $ingredient->code ?? '', 'name' => $ingredient->name, 'unit' => $ingredient->unit, 'base_unit' => $ingredient->base_unit ?? $ingredient->unit, 'conversion_factor' => (float) ($ingredient->conversion_factor ?? 1), 'cost' => (float) $ingredient->cost_per_unit, 'min' => (float) $ingredient->min_stock_level, 'active' => (bool) $ingredient->is_active], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) }})' class="flex-1 py-1.5 text-xs rounded-lg border border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/20 font-medium">{{ __('pos.edit') }}</button>
                <button @click="adjustId = {{ $ingredient->id }}; adjustName = {{ json_encode($ingredient->name, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) }}; showAdjustModal = true" class="flex-1 py-1.5 text-xs rounded-lg bg-purple-600 text-white hover:bg-purple-700 font-medium">{{ __('pos.adjust_stock') }}</button>
                <form method="POST" action="{{ route('pos.restaurant.ingredients.delete', $ingredient->id) }}" onsubmit="return confirm({{ Js::from(__('pos.confirm_delete_q')) }})" class="inline">
                    @csrf @method('DELETE')
                    <button class="py-1.5 px-3 text-xs rounded-lg border border-red-300 text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400">{{ __('pos.delete') }}</button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
    @else
    <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">{{ __('pos.no_ingredients_yet') }}</h3>
        <p class="text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.no_ingredients_yet_hint') }}</p>
    </div>
    @endif

    <div x-show="showAddModal" x-transition.opacity class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showAddModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md">
            <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.add_ingredient') }}</h3>
            </div>
            <form method="POST" action="{{ route('pos.restaurant.ingredients.store') }}" class="p-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.name_label') }}</label>
                    <input type="text" name="code" maxlength="50" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white" placeholder="Code (optional)">
                    <input type="text" name="name" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white" placeholder="{{ __('pos.ph_eg_chicken_breast') }}">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.unit_label') }}</label>
                        <select name="unit" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                            <option value="kg">{{ __('pos.unit_kilogram') }}</option>
                            <option value="g">{{ __('pos.unit_gram') }}</option>
                            <option value="ltr">{{ __('pos.unit_liter') }}</option>
                            <option value="ml">{{ __('pos.unit_milliliter') }}</option>
                            <option value="pcs">{{ __('pos.unit_pieces') }}</option>
                            <option value="dozen">{{ __('pos.unit_dozen') }}</option>
                            <option value="pack">{{ __('pos.unit_pack') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.cost_per_unit_rs') }}</label>
                        <input type="number" name="cost_per_unit" step="0.01" min="0" value="0" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Base unit</label>
                        <select name="base_unit" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                            @foreach(\App\Services\RecipeInventoryService::UNITS as $unit)<option value="{{ $unit }}">{{ $unit }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Conversion factor</label>
                        <input type="number" name="conversion_factor" step="0.0001" min="0.0001" value="1" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.opening_stock') }}</label>
                        <input type="number" name="current_stock" step="0.01" min="0" value="0" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.min_stock_level') }}</label>
                        <input type="number" name="min_stock_level" step="0.01" min="0" value="0" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    </div>
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 py-2.5 rounded-lg bg-purple-600 text-white hover:bg-purple-700 text-sm font-semibold">{{ __('pos.add_ingredient') }}</button>
                    <button type="button" @click="showAddModal = false" class="px-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm">{{ __('pos.cancel') }}</button>
                </div>
            </form>
        </div>
    </div>

    <div x-show="showEditModal" x-transition.opacity x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showEditModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md">
            <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.edit_ingredient') }}</h3>
                <p class="text-sm text-gray-500" x-text="edit.name"></p>
            </div>
            <form method="POST" :action="'/pos/restaurant/ingredients/' + edit.id" class="p-5 space-y-4">
                @csrf @method('PUT')
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.name_label') }}</label>
                    <input type="text" name="code" x-model="edit.code" maxlength="50" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white" placeholder="Code (optional)">
                    <input type="text" name="name" x-model="edit.name" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.unit_label') }}</label>
                        <input type="text" name="unit" x-model="edit.unit" required maxlength="20" list="unit-options" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        <datalist id="unit-options">
                            <option value="kg"></option>
                            <option value="g"></option>
                            <option value="ltr"></option>
                            <option value="ml"></option>
                            <option value="pcs"></option>
                            <option value="dozen"></option>
                            <option value="pack"></option>
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.cost_per_unit_rs') }}</label>
                        <input type="number" name="cost_per_unit" x-model="edit.cost" step="0.01" min="0" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.min_stock_level') }}</label>
                    <input type="number" name="min_stock_level" x-model="edit.min" step="0.01" min="0" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Base unit</label>
                        <select name="base_unit" x-model="edit.base_unit" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                            @foreach(\App\Services\RecipeInventoryService::UNITS as $unit)<option value="{{ $unit }}">{{ $unit }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Conversion factor</label>
                        <input type="number" name="conversion_factor" x-model="edit.conversion_factor" step="0.0001" min="0.0001" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" x-model="edit.active" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    {{ __('pos.active_usable_in_recipes') }}
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('pos.use_adjust_stock_hint') }}</p>
                <div class="flex gap-2 pt-1">
                    <button type="submit" class="flex-1 py-2.5 rounded-lg bg-purple-600 text-white hover:bg-purple-700 text-sm font-semibold">{{ __('pos.save_changes') }}</button>
                    <button type="button" @click="showEditModal = false" class="px-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm">{{ __('pos.cancel') }}</button>
                </div>
            </form>
        </div>
    </div>

    <div x-show="showAdjustModal" x-transition.opacity class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showAdjustModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm">
            <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.adjust_stock') }}</h3>
                <p class="text-sm text-gray-500" x-text="adjustName"></p>
            </div>
            <form method="POST" :action="'/pos/restaurant/ingredients/' + adjustId + '/adjust'" class="p-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.adjustment_label') }}</label>
                    <input type="number" name="adjustment" step="0.01" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white" placeholder="{{ __('pos.ph_eg_10_or_minus_5') }}">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.reason_label') }}</label>
                    <input type="text" name="reason" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white" placeholder="{{ __('pos.ph_reason_examples') }}">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 py-2.5 rounded-lg bg-purple-600 text-white hover:bg-purple-700 text-sm font-semibold">{{ __('pos.adjust_btn') }}</button>
                    <button type="button" @click="showAdjustModal = false" class="px-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm">{{ __('pos.cancel') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
</x-pos-layout>
