<x-pos-layout>
    <div class="pb-36" x-data="posEditInvoice()">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
                <div class="flex items-center space-x-3">
                    <a href="{{ route('pos.transaction.show', $transaction->id) }}" class="inline-flex items-center text-gray-500 hover:text-emerald-600 transition text-sm">
                        <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back
                    </a>
                    <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100">Edit Invoice: {{ $transaction->invoice_number }}</h2>
                </div>
                <div class="mt-2 sm:mt-0 flex items-center space-x-2">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                        Editing
                    </span>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold"
                        :class="praEnabled ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'">
                        <span class="w-2 h-2 rounded-full mr-1.5" :class="praEnabled ? 'bg-emerald-500' : 'bg-gray-400'"></span>
                        PRA: <span x-text="praEnabled ? 'Active' : 'Inactive'" class="ml-1"></span>
                    </span>
                </div>
            </div>

            @if($transaction->pra_status === 'failed' || $transaction->pra_status === 'offline')
            <div class="mb-6 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl p-4">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-amber-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    <div class="text-sm text-amber-800 dark:text-amber-300">This invoice previously failed PRA submission. After saving, it will be re-submitted to PRA automatically.</div>
                </div>
            </div>
            @endif

            <form method="POST" action="{{ route('pos.transaction.update', $transaction->id) }}" @submit.prevent="submitForm($event)" class="space-y-6">
                @csrf
                @method('PUT')

                @if($errors->any())
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl p-4">
                    <ul class="text-sm text-red-700 dark:text-red-400 list-disc pl-4">
                        @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif

                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Customer & Terminal</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Customer Name</label>
                            <input type="text" name="customer_name" x-model="customerName" placeholder="Walk-in Customer" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Phone</label>
                            <input type="text" name="customer_phone" x-model="customerPhone" placeholder="03XX-XXXXXXX" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Terminal</label>
                            <select name="terminal_id" x-model="terminalId" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition">
                                <option value="">Select Terminal</option>
                                @foreach($terminals as $terminal)
                                <option value="{{ $terminal->id }}">{{ $terminal->terminal_name }} ({{ $terminal->terminal_code }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Invoice Items</h3>
                        <button type="button" @click="addItem()" class="inline-flex items-center px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg transition">
                            <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Item
                        </button>
                    </div>

                    <div class="hidden sm:grid sm:grid-cols-12 gap-2 text-xs font-medium text-gray-500 dark:text-gray-400 mb-2 px-1">
                        <div class="col-span-2">Type</div>
                        <div class="col-span-4">Item Name</div>
                        <div class="col-span-2">Qty</div>
                        <div class="col-span-2">Unit Price</div>
                        <div class="col-span-1">Subtotal</div>
                        <div class="col-span-1"></div>
                    </div>

                    <template x-for="(item, index) in items" :key="index">
                        <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 mb-3 p-3 sm:p-1 bg-gray-50 dark:bg-gray-800/50 sm:bg-transparent sm:dark:bg-transparent rounded-lg sm:rounded-none border sm:border-0 border-gray-200 dark:border-gray-700">
                            <div class="sm:col-span-2">
                                <label class="block sm:hidden text-xs text-gray-500 mb-1">Type</label>
                                <select x-model="item.type" @change="onTypeChange(index)" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-2 py-2 focus:ring-2 focus:ring-emerald-500 transition">
                                    <option value="product">Product</option>
                                    <option value="service">Service</option>
                                </select>
                            </div>
                            <div class="sm:col-span-4 relative" x-data="{ open: false, search: '' }" x-init="search = item.name || ''">
                                <label class="block sm:hidden text-xs text-gray-500 mb-1">Item Name</label>
                                <div class="relative">
                                    <input type="text"
                                        x-model="search"
                                        @input="open = true; item.name = search; item.item_id = ''; recalculate()"
                                        @focus="open = true"
                                        @click.away="open = false"
                                        @keydown.escape="open = false"
                                        placeholder="Type or search product..."
                                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 pr-8 focus:ring-2 focus:ring-emerald-500 transition">
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                                        <template x-if="item.item_id">
                                            <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        </template>
                                    </div>
                                </div>
                                <div x-show="open" x-transition class="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl max-h-48 overflow-y-auto">
                                    <template x-if="item.type === 'product'">
                                        <div>
                                            <template x-for="p in products.filter(pr => !search || pr.name.toLowerCase().includes(search.toLowerCase()))" :key="p.id">
                                                <button type="button"
                                                    @click="search = p.name; item.name = p.name; item.item_id = p.id; item.unit_price = parseFloat(p.price || 0); open = false; recalculate()"
                                                    class="w-full text-left px-3 py-2 text-sm hover:bg-emerald-50 dark:hover:bg-emerald-900/20 flex justify-between items-center transition">
                                                    <span class="text-gray-900 dark:text-gray-100 font-medium" x-text="p.name"></span>
                                                    <span class="text-xs text-gray-500" x-text="'Rs ' + Number(p.price || 0).toLocaleString()"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="item.type === 'service'">
                                        <div>
                                            <template x-for="s in services.filter(sv => !search || sv.name.toLowerCase().includes(search.toLowerCase()))" :key="s.id">
                                                <button type="button"
                                                    @click="search = s.name; item.name = s.name; item.item_id = s.id; item.unit_price = parseFloat(s.price || 0); open = false; recalculate()"
                                                    class="w-full text-left px-3 py-2 text-sm hover:bg-emerald-50 dark:hover:bg-emerald-900/20 flex justify-between items-center transition">
                                                    <span class="text-gray-900 dark:text-gray-100 font-medium" x-text="s.name"></span>
                                                    <span class="text-xs text-gray-500" x-text="'Rs ' + Number(s.price || 0).toLocaleString()"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block sm:hidden text-xs text-gray-500 mb-1">Qty</label>
                                <input type="number" x-model.number="item.quantity" min="0.01" step="0.01" @input="recalculate()" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-2 py-2 focus:ring-2 focus:ring-emerald-500 transition text-center">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block sm:hidden text-xs text-gray-500 mb-1">Unit Price</label>
                                <input type="number" x-model.number="item.unit_price" min="0" step="0.01" @input="recalculate()" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-2 py-2 focus:ring-2 focus:ring-emerald-500 transition">
                            </div>
                            <div class="sm:col-span-1 flex items-center">
                                <label class="block sm:hidden text-xs text-gray-500 mb-1 mr-2">Subtotal</label>
                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200" x-text="'Rs ' + (item.quantity * item.unit_price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                            </div>
                            <div class="sm:col-span-1 flex items-center justify-end">
                                <button type="button" @click="removeItem(index)" x-show="items.length > 1" class="p-1.5 text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Discount</h3>
                        <div class="flex items-center space-x-2 mb-3">
                            <button type="button" @click="discountType = 'percentage'; recalculate()"
                                :class="discountType === 'percentage' ? 'bg-emerald-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700'"
                                class="px-4 py-2 rounded-lg text-sm font-medium transition">
                                % Percentage
                            </button>
                            <button type="button" @click="discountType = 'amount'; recalculate()"
                                :class="discountType === 'amount' ? 'bg-emerald-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700'"
                                class="px-4 py-2 rounded-lg text-sm font-medium transition">
                                Rs Amount
                            </button>
                        </div>
                        <div class="flex items-center space-x-3">
                            <input type="number" x-model.number="discountValue" min="0" step="0.01" @input="recalculate()" :max="discountType === 'percentage' ? 100 : subtotal" placeholder="0" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-emerald-500 transition">
                            <span class="text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                = Rs <span x-text="discountAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                            </span>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Payment Method</h3>
                        <div class="grid grid-cols-2 gap-2">
                            <template x-for="pm in paymentMethods" :key="pm.value">
                                <button type="button" @click="selectPaymentMethod(pm.value)"
                                    :class="paymentMethod === pm.value ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 ring-2 ring-emerald-500/30' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'"
                                    class="flex flex-col items-center p-3 rounded-xl border-2 transition cursor-pointer">
                                    <span class="text-xl mb-1" x-text="pm.icon"></span>
                                    <span class="text-xs font-semibold" :class="paymentMethod === pm.value ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'" x-text="pm.label"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Tax Calculation Summary</h3>
                    <div class="space-y-2">
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                            <span>Subtotal</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200" x-text="'Rs ' + subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        </div>
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                            <span>Discount <span x-show="discountValue > 0" class="text-xs">(<span x-text="discountType === 'percentage' ? discountValue + '%' : 'Fixed'"></span>)</span></span>
                            <span class="font-medium text-red-500" x-text="'- Rs ' + discountAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        </div>
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 border-t border-gray-100 dark:border-gray-800 pt-2">
                            <span>After Discount</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200" x-text="'Rs ' + afterDiscount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        </div>
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                            <span>Tax (<span x-text="taxRate + '%'"></span>)</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200" x-text="'Rs ' + taxAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        </div>
                        <div class="flex justify-between text-base font-bold text-gray-900 dark:text-white border-t-2 border-gray-200 dark:border-gray-700 pt-3 mt-2">
                            <span>Total</span>
                            <span class="text-emerald-600" x-text="'Rs ' + total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        </div>
                    </div>
                </div>

                <template x-for="(item, index) in items" :key="'hidden-'+index">
                    <div>
                        <input type="hidden" :name="'items['+index+'][type]'" :value="item.type">
                        <input type="hidden" :name="'items['+index+'][item_id]'" :value="item.item_id">
                        <input type="hidden" :name="'items['+index+'][name]'" :value="item.name">
                        <input type="hidden" :name="'items['+index+'][quantity]'" :value="item.quantity">
                        <input type="hidden" :name="'items['+index+'][unit_price]'" :value="item.unit_price">
                    </div>
                </template>
                <input type="hidden" name="discount_type" :value="discountType">
                <input type="hidden" name="discount_value" :value="discountValue">
                <input type="hidden" name="payment_method" :value="paymentMethod">
                <input type="hidden" name="terminal_id" :value="terminalId">

                <div class="flex justify-between">
                    <a href="{{ route('pos.transaction.show', $transaction->id) }}" class="inline-flex items-center px-5 py-3 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-semibold rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                        Cancel
                    </a>
                    <button type="submit" :disabled="items.length === 0 || submitting" class="inline-flex items-center px-6 py-3 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold rounded-xl shadow-sm transition">
                        <svg x-show="!submitting" class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <svg x-show="submitting" class="w-5 h-5 mr-2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <span x-text="submitting ? 'Saving...' : 'Save Changes'"></span>
                    </button>
                </div>
            </form>
        </div>

        <div class="fixed bottom-0 left-0 right-0 lg:left-64 z-20 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700 shadow-lg">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between">
                <div class="flex items-center space-x-6 text-sm">
                    <div>
                        <span class="text-gray-400 text-xs">Items</span>
                        <p class="font-semibold text-gray-800 dark:text-gray-200" x-text="items.length"></p>
                    </div>
                    <div>
                        <span class="text-gray-400 text-xs">Subtotal</span>
                        <p class="font-semibold text-gray-800 dark:text-gray-200" x-text="'Rs ' + subtotal.toLocaleString(undefined, {minimumFractionDigits: 2})"></p>
                    </div>
                    <div class="hidden sm:block">
                        <span class="text-gray-400 text-xs">Discount</span>
                        <p class="font-semibold text-red-500" x-text="'- Rs ' + discountAmount.toLocaleString(undefined, {minimumFractionDigits: 2})"></p>
                    </div>
                    <div class="hidden sm:block">
                        <span class="text-gray-400 text-xs">Tax</span>
                        <p class="font-semibold text-gray-800 dark:text-gray-200" x-text="'Rs ' + taxAmount.toLocaleString(undefined, {minimumFractionDigits: 2})"></p>
                    </div>
                </div>
                <div class="text-right">
                    <span class="text-gray-400 text-xs">Total</span>
                    <p class="font-bold text-lg text-emerald-600" x-text="'Rs ' + total.toLocaleString(undefined, {minimumFractionDigits: 2})"></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function posEditInvoice() {
            return {
                products: @json($products),
                services: @json($services),
                taxRules: @json($taxRules),
                praEnabled: {{ $company->pra_reporting_enabled ? 'true' : 'false' }},

                customerName: @json($transaction->customer_name ?? ''),
                customerPhone: @json($transaction->customer_phone ?? ''),
                terminalId: @json($transaction->terminal_id ?? ''),

                items: @json($transactionItems),

                discountType: @json($transaction->discount_type ?? 'percentage'),
                discountValue: {{ (float) ($transaction->discount_value ?? 0) }},
                paymentMethod: @json($transaction->payment_method ?? 'cash'),

                subtotal: 0,
                discountAmount: 0,
                afterDiscount: 0,
                taxRate: 0,
                taxAmount: 0,
                total: 0,

                submitting: false,

                paymentMethods: [
                    { value: 'cash', label: 'Cash', icon: '💵' },
                    { value: 'debit_card', label: 'Debit Card', icon: '💳' },
                    { value: 'credit_card', label: 'Credit Card', icon: '🏦' },
                    { value: 'qr_payment', label: 'QR / Raast', icon: '📱' }
                ],

                init() {
                    this.fetchTaxRate(this.paymentMethod);
                    this.recalculate();
                },

                addItem() {
                    this.items.push({ type: 'product', item_id: '', name: '', quantity: 1, unit_price: 0 });
                },

                removeItem(index) {
                    this.items.splice(index, 1);
                    this.recalculate();
                },

                onTypeChange(index) {
                    this.items[index].item_id = '';
                    this.items[index].name = '';
                    this.items[index].unit_price = 0;
                    this.recalculate();
                },

                selectPaymentMethod(method) {
                    this.paymentMethod = method;
                    this.fetchTaxRate(method);
                },

                fetchTaxRate(method) {
                    if (this.taxRules && this.taxRules[method]) {
                        this.taxRate = parseFloat(this.taxRules[method].tax_rate || 0);
                        this.recalculate();
                        return;
                    }

                    fetch('/pos/api/tax-rate?payment_method=' + method, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(r => r.json())
                    .then(data => {
                        this.taxRate = parseFloat(data.tax_rate || 0);
                        this.recalculate();
                    })
                    .catch(() => {
                        this.taxRate = 0;
                        this.recalculate();
                    });
                },

                recalculate() {
                    this.subtotal = 0;
                    this.items.forEach(item => {
                        this.subtotal += (parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0);
                    });
                    this.subtotal = Math.round(this.subtotal * 100) / 100;

                    if (this.discountType === 'percentage') {
                        this.discountAmount = Math.round(this.subtotal * (parseFloat(this.discountValue) || 0) / 100 * 100) / 100;
                    } else {
                        this.discountAmount = Math.min(parseFloat(this.discountValue) || 0, this.subtotal);
                    }

                    this.afterDiscount = Math.round((this.subtotal - this.discountAmount) * 100) / 100;
                    this.taxAmount = Math.round(this.afterDiscount * this.taxRate / 100 * 100) / 100;
                    this.total = Math.round((this.afterDiscount + this.taxAmount) * 100) / 100;
                },

                submitForm(event) {
                    if (this.items.length === 0) return;
                    this.submitting = true;
                    this.$nextTick(() => {
                        event.target.submit();
                    });
                }
            };
        }
    </script>
</x-pos-layout>
