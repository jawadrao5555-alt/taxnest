<x-pos-layout>
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.adjust_stock') }}</h1>
        <a href="{{ route('pos.inventory.stock') }}" class="inline-flex items-center text-gray-500 hover:text-purple-600 transition text-sm">
            <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            {{ __('pos.back_to_stock') }}
        </a>
    </div>

    <div class="flex flex-wrap gap-2 mb-6">
        <a href="{{ route('pos.inventory.dashboard') }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">{{ __('pos.dashboard') }}</a>
        <a href="{{ route('pos.inventory.stock') }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">{{ __('pos.stock_levels') }}</a>
        <a href="{{ route('pos.inventory.movements') }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">{{ __('pos.movements') }}</a>
        <a href="{{ route('pos.inventory.low-stock') }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">{{ __('pos.low_stock_alerts') }}</a>
        <a href="{{ route('pos.inventory.adjust') }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-purple-600 text-white">{{ __('pos.adjust_stock') }}</a>
        @if($canTransfer ?? false)
        <a href="{{ route('pos.inventory.transfers') }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">{{ __('pos.branch_transfer') }}</a>
        @endif
        <a href="{{ route('pos.inventory.stock-check.index') }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">{{ __('pos.stock_check') }}<x-new-badge feature="stock_check" class="ml-1" /></a>
    </div>

    @if(session('error'))
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg p-3 text-sm text-red-800 dark:text-red-300">{{ session('error') }}</div>
    @endif

    @if($errors->any())
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg p-3">
        <ul class="text-sm text-red-800 dark:text-red-300 list-disc pl-4">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('pos.inventory.adjust') }}" class="space-y-6"
          data-local-core-command="stock.adjust" data-local-core-aggregate-field="product_id">
        @csrf
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.product_col') }}</label>
                <select name="product_id" required class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                    <option value="">{{ __('pos.select_product') }}</option>
                    @foreach($products as $p)
                    <option value="{{ $p->id }}" {{ old('product_id', request('product_id')) == $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Per-branch stock (Task 1354): an adjustment must name the shop it
                 lands in, otherwise an owner on the all-branches view has no way
                 to say WHERE the maal actually is. --}}
            @if($multiBranch ?? false)
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.branch_word') }}</label>
                <select name="branch_id" required class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                    @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (int) old('branch_id', request('branch_id', $activeBranchId ?? 0)) === (int) $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.adjust_branch_hint') }}</p>
            </div>
            @endif

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.adjustment_type') }}</label>
                <div class="grid grid-cols-3 gap-2" x-data="{ type: '{{ old('type', 'add') }}' }">
                    <label @click="type = 'add'" :class="type === 'add' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 ring-2 ring-emerald-500/30' : 'border-gray-200 dark:border-gray-700'" class="cursor-pointer flex flex-col items-center p-3 rounded-xl border-2 transition">
                        <input type="radio" name="type" value="add" x-model="type" class="hidden">
                        <span class="text-lg mb-1">+</span>
                        <span class="text-xs font-semibold" :class="type === 'add' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">{{ __('pos.add_stock') }}</span>
                    </label>
                    <label @click="type = 'remove'" :class="type === 'remove' ? 'border-red-500 bg-red-50 dark:bg-red-900/20 ring-2 ring-red-500/30' : 'border-gray-200 dark:border-gray-700'" class="cursor-pointer flex flex-col items-center p-3 rounded-xl border-2 transition">
                        <input type="radio" name="type" value="remove" x-model="type" class="hidden">
                        <span class="text-lg mb-1">-</span>
                        <span class="text-xs font-semibold" :class="type === 'remove' ? 'text-red-700 dark:text-red-300' : 'text-gray-600 dark:text-gray-400'">{{ __('pos.remove_stock') }}</span>
                    </label>
                    <label @click="type = 'set'" :class="type === 'set' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20 ring-2 ring-blue-500/30' : 'border-gray-200 dark:border-gray-700'" class="cursor-pointer flex flex-col items-center p-3 rounded-xl border-2 transition">
                        <input type="radio" name="type" value="set" x-model="type" class="hidden">
                        <span class="text-lg mb-1">=</span>
                        <span class="text-xs font-semibold" :class="type === 'set' ? 'text-blue-700 dark:text-blue-300' : 'text-gray-600 dark:text-gray-400'">{{ __('pos.set_exact') }}</span>
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.quantity_label') }}</label>
                    <input type="number" name="quantity" value="{{ old('quantity') }}" min="0.01" step="0.01" required placeholder="{{ __('pos.ph_enter_quantity') }}" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                </div>
                <div x-show="type === 'add'" x-transition>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.purchase_price_per_unit') }} <span class="text-gray-400">{{ __('pos.paren_optional_cap') }}</span></label>
                    <input type="number" name="purchase_price" value="{{ old('purchase_price') }}" min="0" step="0.01" placeholder="PKR 0.00" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                    <p class="text-xs text-gray-400 mt-1">{{ __('pos.updates_avg_cost_hint') }}</p>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.reason_label') }}</label>
                <select name="reason" required class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                    <option value="">{{ __('pos.select_reason') }}</option>
                    <option value="New Purchase" {{ old('reason') === 'New Purchase' ? 'selected' : '' }}>{{ __('pos.reason_new_purchase') }}</option>
                    <option value="Physical Count" {{ old('reason') === 'Physical Count' ? 'selected' : '' }}>{{ __('pos.reason_physical_count') }}</option>
                    <option value="Damaged/Expired" {{ old('reason') === 'Damaged/Expired' ? 'selected' : '' }}>{{ __('pos.reason_damaged_expired') }}</option>
                    <option value="Return from Customer" {{ old('reason') === 'Return from Customer' ? 'selected' : '' }}>{{ __('pos.reason_return_customer') }}</option>
                    <option value="Opening Stock" {{ old('reason') === 'Opening Stock' ? 'selected' : '' }}>{{ __('pos.reason_opening_stock') }}</option>
                    <option value="Correction" {{ old('reason') === 'Correction' ? 'selected' : '' }}>{{ __('pos.reason_correction') }}</option>
                    <option value="Other" {{ old('reason') === 'Other' ? 'selected' : '' }}>{{ __('pos.reason_other') }}</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.notes_col') }} <span class="text-gray-400">{{ __('pos.paren_optional_cap') }}</span></label>
                <textarea name="notes" rows="2" placeholder="{{ __('pos.ph_additional_details') }}" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">{{ old('notes') }}</textarea>
            </div>
        </div>

        <button type="submit" class="w-full px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white font-semibold rounded-xl shadow-sm transition">
            {{ __('pos.adjust_stock') }}
        </button>
    </form>
</div>
</x-pos-layout>
