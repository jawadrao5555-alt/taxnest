<x-fbr-pos-layout>
<div class="max-w-3xl mx-auto" x-data="fbrProductForm()">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ isset($product) ? 'Edit Product' : 'New Product' }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ isset($product) ? 'Update product details and tax settings' : 'Add a new product with tax configuration' }}</p>
        </div>
        <a href="{{ route('fbrpos.products') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">Back to Products</a>
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
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-5 pb-3 border-b border-gray-200 dark:border-gray-700">Product Details</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Product Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $product->name ?? '') }}" required
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="e.g., Chicken Burger">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Price (PKR) <span class="text-red-500">*</span></label>
                    <input type="number" step="0.01" min="0" name="default_price" x-model="price" required
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="0.00">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">HS Code <span class="text-gray-400 text-xs">(Optional)</span></label>
                    <input type="text" name="hs_code" value="{{ old('hs_code', $product->hs_code ?? '') }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="00000000">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">UoM</label>
                    <select name="uom" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        @php $currentUom = old('uom', $product->uom ?? 'U'); @endphp
                        <option value="U" {{ $currentUom == 'U' ? 'selected' : '' }}>Units</option>
                        <option value="KG" {{ $currentUom == 'KG' ? 'selected' : '' }}>KG</option>
                        <option value="LTR" {{ $currentUom == 'LTR' ? 'selected' : '' }}>Liters</option>
                        <option value="MTR" {{ $currentUom == 'MTR' ? 'selected' : '' }}>Meters</option>
                        <option value="PCS" {{ $currentUom == 'PCS' ? 'selected' : '' }}>Pieces</option>
                        <option value="PKT" {{ $currentUom == 'PKT' ? 'selected' : '' }}>Packets</option>
                        <option value="DOZ" {{ $currentUom == 'DOZ' ? 'selected' : '' }}>Dozen</option>
                        <option value="BOX" {{ $currentUom == 'BOX' ? 'selected' : '' }}>Box</option>
                        <option value="SET" {{ $currentUom == 'SET' ? 'selected' : '' }}>Set</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-5 pb-3 border-b border-gray-200 dark:border-gray-700">Tax Configuration</h3>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
                <label class="relative flex cursor-pointer rounded-xl border-2 p-4 transition"
                    :class="taxType === 'taxable' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300'">
                    <input type="radio" name="tax_type" value="taxable" x-model="taxType" class="sr-only">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="w-6 h-6 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center text-xs font-bold">%</span>
                            <span class="font-semibold text-gray-900 dark:text-white text-sm">Taxable</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Standard 18% GST applies</p>
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
                            <span class="font-semibold text-gray-900 dark:text-white text-sm">Exempt</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">No tax (food, medicine etc)</p>
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
                            <span class="font-semibold text-gray-900 dark:text-white text-sm">Custom Rate</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Set your own tax rate</p>
                    </div>
                    <div class="absolute top-2 right-2" x-show="taxType === 'custom'">
                        <svg class="w-5 h-5 text-purple-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    </div>
                </label>
            </div>

            <div x-show="taxType === 'custom'" x-transition class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Custom Tax Rate (%) <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" min="0" max="100" x-model="taxRate"
                    class="w-full md:w-48 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                    placeholder="e.g., 5">
            </div>
            <input type="hidden" name="default_tax_rate" :value="taxType === 'taxable' ? 18 : (taxType === 'exempt' ? 0 : taxRate)">

            <div class="p-4 rounded-xl border transition"
                :class="effectiveRate > 0 ? 'bg-blue-50 dark:bg-blue-900/10 border-blue-200 dark:border-blue-800' : 'bg-green-50 dark:bg-green-900/10 border-green-200 dark:border-green-800'">
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Price</p>
                        <p class="text-sm font-bold text-gray-800 dark:text-gray-200">PKR <span x-text="formatNum(price)">0.00</span></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Tax</p>
                        <p class="text-sm font-bold" :class="effectiveRate > 0 ? 'text-amber-600' : 'text-green-600'" x-text="effectiveRate + '%'">0%</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Total</p>
                        <p class="text-sm font-bold text-blue-700 dark:text-blue-400">PKR <span x-text="formatNum(calcTotal())">0.00</span></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('fbrpos.products') }}" class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-50 dark:hover:bg-gray-800 transition">Cancel</a>
            <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition">
                {{ isset($product) ? 'Update Product' : 'Create Product' }}
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
