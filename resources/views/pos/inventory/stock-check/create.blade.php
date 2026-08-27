<x-pos-layout>
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')

    <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2 mb-4">
        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-500 to-purple-700 flex items-center justify-center shadow-lg">
            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        </div>
        {{ __('pos.stock_check_new') }}
    </h1>

    @include('pos.inventory.stock-check.partials.flash')

    @if($openCheck)
    <div class="mb-6 rounded-2xl border-2 border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-5">
        <p class="text-sm font-semibold text-amber-900 dark:text-amber-300">{{ __('pos.stock_check_already_open', ['code' => $openCheck->code]) }}</p>
        <a href="{{ route('pos.inventory.stock-check.show', $openCheck->id) }}" class="mt-3 inline-flex items-center px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold rounded-xl transition">
            {{ __('pos.stock_check_continue') }}
        </a>
    </div>
    @endif

    <form method="POST" action="{{ route('pos.inventory.stock-check.store') }}" class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-6 space-y-6">
        @csrf

        @if(($multiBranch ?? false) && ($branches ?? collect())->isNotEmpty())
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('pos.branch_word') }}</label>
            <select name="branch_id" class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-4 py-3 focus:ring-2 focus:ring-purple-500">
                @foreach($branches as $b)
                <option value="{{ $b->id }}" {{ (int) ($activeBranchId ?? 0) === (int) $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5">{{ __('pos.stock_check_branch_hint') }}</p>
        </div>
        @endif

        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('pos.stock_check_what_to_count') }}</label>
            <div class="space-y-2">
                <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                    <input type="radio" name="scope" value="products" checked class="mt-1 text-purple-600 focus:ring-purple-500">
                    <span>
                        <span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.stock_check_scope_products') }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.stock_check_scope_products_hint') }}</span>
                    </span>
                </label>
                @if($hasIngredients)
                <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                    <input type="radio" name="scope" value="ingredients" class="mt-1 text-purple-600 focus:ring-purple-500">
                    <span>
                        <span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.stock_check_scope_ingredients') }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.stock_check_scope_ingredients_hint') }}</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                    <input type="radio" name="scope" value="both" class="mt-1 text-purple-600 focus:ring-purple-500">
                    <span>
                        <span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.stock_check_scope_both') }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.stock_check_scope_both_hint') }}</span>
                    </span>
                </label>
                @endif
            </div>
        </div>

        @if($categories->isNotEmpty())
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('pos.category_label') }}</label>
            <select name="category" class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-4 py-3 focus:ring-2 focus:ring-purple-500">
                <option value="">{{ __('pos.stock_check_all_categories') }}</option>
                @foreach($categories as $cat)
                <option value="{{ $cat }}">{{ $cat }}</option>
                @endforeach
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5">{{ __('pos.stock_check_category_hint') }}</p>
        </div>
        @endif

        <label class="flex items-start gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/60 cursor-pointer">
            <input type="checkbox" name="only_in_stock" value="1" class="mt-0.5 rounded text-purple-600 focus:ring-purple-500">
            <span>
                <span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.stock_check_only_in_stock') }}</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.stock_check_only_in_stock_hint') }}</span>
            </span>
        </label>

        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('pos.notes_label') }}</label>
            <input type="text" name="notes" maxlength="500" value="{{ old('notes') }}" placeholder="{{ __('pos.stock_check_notes_placeholder') }}"
                class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-4 py-3 focus:ring-2 focus:ring-purple-500">
        </div>

        <div class="flex flex-col sm:flex-row gap-3 pt-2">
            <button type="submit" class="flex-1 inline-flex items-center justify-center px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold rounded-xl transition shadow-sm">
                {{ __('pos.stock_check_start') }}
            </button>
            <a href="{{ route('pos.inventory.stock-check.index') }}" class="inline-flex items-center justify-center px-6 py-3 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-bold rounded-xl transition">
                {{ __('pos.cancel') }}
            </a>
        </div>
    </form>
</div>
</x-pos-layout>
