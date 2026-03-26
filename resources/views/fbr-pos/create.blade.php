<x-fbr-pos-layout>
<div class="max-w-5xl mx-auto" x-data="fbrPosInvoice()">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">New FBR POS Sale</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Create a new point of sale transaction</p>
        </div>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $company->fbr_pos_environment === 'production' ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' }}">
            {{ strtoupper($company->fbr_pos_environment ?? 'sandbox') }}
        </span>
    </div>

    @if($errors->any())
    <div class="mb-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
        <ul class="list-disc list-inside text-sm">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('fbrpos.store') }}">
        @csrf

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-semibold text-gray-900 dark:text-white">Items</h3>
                        <button type="button" @click="addItem()" class="text-sm text-emerald-600 hover:text-emerald-700 font-medium">+ Add Item</button>
                    </div>

                    <template x-for="(item, index) in items" :key="index">
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-3">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400" x-text="'Item #' + (index + 1)"></span>
                                <button type="button" @click="removeItem(index)" x-show="items.length > 1" class="text-red-500 hover:text-red-700 text-xs">Remove</button>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                                <div class="sm:col-span-4">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Item Name *</label>
                                    <input type="text" :name="'items['+index+'][item_name]'" x-model="item.item_name" required
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-emerald-500 focus:border-emerald-500"
                                        placeholder="Product name">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">HS Code</label>
                                    <input type="text" :name="'items['+index+'][hs_code]'" x-model="item.hs_code"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-emerald-500 focus:border-emerald-500"
                                        placeholder="00000000">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Qty *</label>
                                    <input type="number" :name="'items['+index+'][quantity]'" x-model.number="item.quantity" min="1" required
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Unit Price *</label>
                                    <input type="number" :name="'items['+index+'][unit_price]'" x-model.number="item.unit_price" min="0.01" step="0.01" required
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Tax %</label>
                                    <div class="flex items-center gap-2">
                                        <input type="number" :name="'items['+index+'][tax_rate]'" x-model.number="item.tax_rate" min="0" max="100" step="0.01"
                                            :disabled="item.is_tax_exempt"
                                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-emerald-500 focus:border-emerald-500 disabled:opacity-50">
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center justify-between mt-2">
                                <label class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 cursor-pointer">
                                    <input type="checkbox" :name="'items['+index+'][is_tax_exempt]'" x-model="item.is_tax_exempt" value="1"
                                        class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500 w-3.5 h-3.5">
                                    Tax Exempt
                                </label>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white" x-text="'PKR ' + formatNum(lineTotal(item))"></span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="space-y-4">
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Customer (Optional)</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Name</label>
                            <input type="text" name="customer_name" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="Walk-in Customer">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Phone</label>
                            <input type="text" name="customer_phone" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="0300-1234567">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">NTN</label>
                            <input type="text" name="customer_ntn" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="Optional">
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Payment</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Method *</label>
                            <select name="payment_method" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="online">Online</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Discount Type</label>
                            <select name="discount_type" x-model="discountType" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">None</option>
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (PKR)</option>
                            </select>
                        </div>
                        <div x-show="discountType">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Discount Value</label>
                            <input type="number" name="discount_value" x-model.number="discountValue" min="0" step="0.01"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-xl border border-emerald-200 dark:border-emerald-800 p-5">
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span>Subtotal</span>
                            <span x-text="'PKR ' + formatNum(calcSubtotal())"></span>
                        </div>
                        <div class="flex justify-between text-gray-600 dark:text-gray-400" x-show="calcDiscount() > 0">
                            <span>Discount</span>
                            <span class="text-red-600" x-text="'-PKR ' + formatNum(calcDiscount())"></span>
                        </div>
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span>Tax</span>
                            <span x-text="'PKR ' + formatNum(calcTax())"></span>
                        </div>
                        <div class="flex justify-between font-bold text-lg text-emerald-800 dark:text-emerald-300 pt-2 border-t border-emerald-200 dark:border-emerald-700">
                            <span>Total</span>
                            <span x-text="'PKR ' + formatNum(calcTotal())"></span>
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full py-3 bg-emerald-600 text-white font-semibold rounded-lg hover:bg-emerald-700 transition text-sm">
                    Complete Sale
                </button>
            </div>
        </div>
    </form>
</div>

<script>
function fbrPosInvoice() {
    return {
        items: [{ item_name: '', hs_code: '', quantity: 1, unit_price: 0, tax_rate: 18, is_tax_exempt: false }],
        discountType: '',
        discountValue: 0,
        addItem() {
            this.items.push({ item_name: '', hs_code: '', quantity: 1, unit_price: 0, tax_rate: 18, is_tax_exempt: false });
        },
        removeItem(index) {
            this.items.splice(index, 1);
        },
        lineTotal(item) {
            let sub = (item.quantity || 0) * (item.unit_price || 0);
            let taxRate = item.is_tax_exempt ? 0 : (item.tax_rate || 0);
            return sub + (sub * taxRate / 100);
        },
        calcSubtotal() {
            return this.items.reduce((sum, item) => sum + ((item.quantity || 0) * (item.unit_price || 0)), 0);
        },
        calcDiscount() {
            let sub = this.calcSubtotal();
            if (this.discountType === 'percentage') return sub * (this.discountValue || 0) / 100;
            if (this.discountType === 'fixed') return Math.min(this.discountValue || 0, sub);
            return 0;
        },
        calcTax() {
            return this.items.reduce((sum, item) => {
                let sub = (item.quantity || 0) * (item.unit_price || 0);
                let taxRate = item.is_tax_exempt ? 0 : (item.tax_rate || 0);
                return sum + (sub * taxRate / 100);
            }, 0);
        },
        calcTotal() {
            return this.calcSubtotal() - this.calcDiscount() + this.calcTax();
        },
        formatNum(n) {
            return Number(n).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    };
}
</script>
</x-fbr-pos-layout>
