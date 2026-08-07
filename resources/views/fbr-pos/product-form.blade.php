<x-fbr-pos-layout>
<div class="max-w-3xl mx-auto" x-data="fbrProductForm()">
    @include('fbr-pos.partials.back-link')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ isset($product) ? __('pos.edit_product') : __('pos.new_product') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ isset($product) ? __('pos.update_product_tax_settings') : __('pos.add_product_tax_config') }}</p>
        </div>
        <a href="{{ route('fbrpos.products') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">{{ __('pos.back_to_products') }}</a>
    </div>

    @if($errors->any())
    <div class="mb-4 p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-xl">
        <ul class="list-disc list-inside text-sm">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ isset($product) ? route('fbrpos.products.update', $product->id) : route('fbrpos.products.store') }}" class="space-y-6">
        @csrf
        @if(isset($product)) @method('PUT') @endif

        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-5 pb-3 border-b border-gray-200 dark:border-gray-700">{{ __('pos.product_details') }}</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.product_name_label') }} <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $product->name ?? '') }}" required
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="{{ __('pos.ph_chicken_burger') }}">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.price_pkr') }} <span class="text-red-500">*</span></label>
                    <input type="number" step="0.01" min="0" name="default_price" x-model="price" required
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="0.00">
                    <label class="mt-2 flex items-start gap-2 cursor-pointer p-2.5 rounded-lg border-2 transition"
                        :class="isPriceEditable ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800'">
                        <input type="checkbox" name="is_price_editable" value="1" x-model="isPriceEditable"
                            class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                        <div>
                            <span class="text-xs font-bold text-gray-800 dark:text-gray-100" x-text="isPriceEditable ? @js(__('pos.price_editable_at_pos')) : @js(__('pos.price_fixed_locked_pos'))"></span>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5" x-text="isPriceEditable ? @js(__('pos.price_editable_hint')) : @js(__('pos.price_fixed_hint'))"></p>
                        </div>
                    </label>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.hs_code_col') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_optional') }}</span></label>
                    <input type="text" name="hs_code" value="{{ old('hs_code', $product->hs_code ?? '') }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="00000000">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.uom_label') }}</label>
                    <select name="uom" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        @php
                            $currentUom = old('uom', $product->uom ?? 'U');
                            $uomList = [
                                'U' => 'Units',
                                'PCS' => 'Pieces',
                                'KG' => 'Kilogram',
                                'GM' => 'Gram',
                                'LTR' => 'Liter',
                                'ML' => 'Milliliter',
                                'MTR' => 'Meter',
                                'SQM' => 'Square Meter',
                                'FT' => 'Feet',
                                'IN' => 'Inch',
                                'YDS' => 'Yards',
                                'PKT' => 'Packet',
                                'DOZ' => 'Dozen',
                                'BOX' => 'Box',
                                'CTN' => 'Carton',
                                'BAG' => 'Bag',
                                'BTL' => 'Bottle',
                                'TIN' => 'Tin',
                                'CAN' => 'Can',
                                'BUN' => 'Bundle',
                                'ROL' => 'Roll',
                                'SET' => 'Set',
                            ];
                        @endphp
                        @foreach($uomList as $code => $label)
                            <option value="{{ $code }}" {{ $currentUom == $code ? 'selected' : '' }}>{{ $code }} — {{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.barcode_label') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_ean_upc_optional') }}</span></label>
                    <input type="text" name="barcode" value="{{ old('barcode', $product->barcode ?? '') }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono"
                        placeholder="{{ __('pos.ph_scan_barcode') }}">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.sku_label') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_internal_optional') }}</span></label>
                    <input type="text" name="sku" value="{{ old('sku', $product->sku ?? '') }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono"
                        placeholder="{{ __('pos.ph_sku_example') }}">
                </div>
                @php
                    // Retail Core (Aug 2026): stock fields — current stock shown on edit.
                    $stockRow = isset($product)
                        ? \App\Models\InventoryStock::where('company_id', $product->company_id)->where('product_id', $product->id)->whereNull('branch_id')->first()
                        : null;
                    $hasOpening = isset($product) && \App\Models\InventoryMovement::where('company_id', $product->company_id)->where('product_id', $product->id)->where('type', \App\Models\InventoryMovement::TYPE_OPENING)->exists();
                @endphp
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Opening Stock <span class="text-gray-400 text-xs">(optional)</span></label>
                    @if($hasOpening)
                        <div class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-2 text-sm text-gray-600 dark:text-gray-300">
                            Current stock: <strong>{{ $stockRow ? rtrim(rtrim(number_format($stockRow->quantity, 3, '.', ''), '0'), '.') : 0 }} {{ $product->uom ?? 'U' }}</strong>
                            <span class="text-xs text-gray-400 block">{{ __('pos.stock_receive_from_page_hint') }}</span>
                        </div>
                    @else
                        <input type="number" step="0.001" min="0" name="opening_stock" value="{{ old('opening_stock') }}"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            placeholder="0">
                    @endif
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Min Stock Alert Level <span class="text-gray-400 text-xs">(optional)</span></label>
                    <input type="number" step="0.001" min="0" name="min_stock_level" value="{{ old('min_stock_level', $stockRow->min_stock_level ?? '') }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="e.g. 10">
                    <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.min_level_alert_hint') }}</p>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-5 pb-3 border-b border-gray-200 dark:border-gray-700">{{ __('pos.tax_configuration') }}</h3>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
                <label class="relative flex cursor-pointer rounded-xl border-2 p-4 transition"
                    :class="taxType === 'taxable' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300'">
                    <input type="radio" name="tax_type" value="taxable" x-model="taxType" class="sr-only">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="w-6 h-6 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center text-xs font-bold">%</span>
                            <span class="font-semibold text-gray-900 dark:text-white text-sm">{{ __('pos.taxable_word') }}</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.standard_gst_applies') }}</p>
                    </div>
                    <div class="absolute top-2 right-2" x-show="taxType === 'taxable'">
                        <svg class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    </div>
                </label>

                <label class="relative flex cursor-pointer rounded-xl border-2 p-4 transition"
                    :class="taxType === 'exempt' ? 'border-green-500 bg-green-50 dark:bg-green-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300'">
                    <input type="radio" name="tax_type" value="exempt" x-model="taxType" class="sr-only">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="w-6 h-6 rounded-full bg-green-100 text-green-700 flex items-center justify-center text-xs font-bold">0</span>
                            <span class="font-semibold text-gray-900 dark:text-white text-sm">{{ __('pos.exempt_word') }}</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.no_tax_food_medicine') }}</p>
                    </div>
                    <div class="absolute top-2 right-2" x-show="taxType === 'exempt'">
                        <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    </div>
                </label>

                <label class="relative flex cursor-pointer rounded-xl border-2 p-4 transition"
                    :class="taxType === 'custom' ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300'">
                    <input type="radio" name="tax_type" value="custom" x-model="taxType" class="sr-only">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="w-6 h-6 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center text-xs font-bold">C</span>
                            <span class="font-semibold text-gray-900 dark:text-white text-sm">{{ __('pos.custom_rate_word') }}</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.set_own_tax_rate') }}</p>
                    </div>
                    <div class="absolute top-2 right-2" x-show="taxType === 'custom'">
                        <svg class="w-5 h-5 text-purple-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    </div>
                </label>
            </div>

            <div x-show="taxType === 'custom'" x-transition class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.custom_tax_rate_pct') }} <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" min="0" max="100" x-model="taxRate"
                    class="w-full md:w-48 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                    placeholder="{{ __('pos.ph_eg_5') }}">
            </div>
            <input type="hidden" name="default_tax_rate" :value="taxType === 'taxable' ? 18 : (taxType === 'exempt' ? 0 : taxRate)">

            <div class="p-4 rounded-xl border transition"
                :class="effectiveRate > 0 ? 'bg-blue-50 dark:bg-blue-900/10 border-blue-200 dark:border-blue-800' : 'bg-green-50 dark:bg-green-900/10 border-green-200 dark:border-green-800'">
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.price_col') }}</p>
                        <p class="text-sm font-bold text-gray-800 dark:text-gray-200">PKR <span x-text="formatNum(price)">0.00</span></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.tax_word') }}</p>
                        <p class="text-sm font-bold" :class="effectiveRate > 0 ? 'text-amber-600' : 'text-green-600'" x-text="effectiveRate + '%'">0%</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.total_word') }}</p>
                        <p class="text-sm font-bold text-blue-700 dark:text-blue-400">PKR <span x-text="formatNum(calcTotal())">0.00</span></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('fbrpos.products') }}" class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-50 dark:hover:bg-gray-800 transition">{{ __('pos.cancel') }}</a>
            <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition">
                {{ isset($product) ? __('pos.update_product') : __('pos.create_product') }}
            </button>
        </div>
    </form>
</div>

<script>
function fbrProductForm() {
    return {
        taxType: '{{ old("tax_type", $product->tax_type ?? "taxable") }}',
        taxRate: '{{ old("default_tax_rate", $product->default_tax_rate ?? 18) }}',
        price: '{{ old("default_price", $product->default_price ?? 0) }}',
        isPriceEditable: {{ old('is_price_editable', isset($product) ? ($product->is_price_editable ? 'true' : 'false') : 'true') }},

        get effectiveRate() {
            if (this.taxType === 'exempt') return 0;
            if (this.taxType === 'taxable') return 18;
            return parseFloat(this.taxRate) || 0;
        },

        calcTotal() {
            let p = parseFloat(this.price) || 0;
            return p + (p * this.effectiveRate / 100);
        },

        formatNum(n) {
            return Number(n).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    };
}
</script>
</x-fbr-pos-layout>
