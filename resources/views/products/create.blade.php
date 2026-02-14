<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Create Product</h2>
            <a href="/products" class="text-sm text-gray-600 hover:text-gray-800">Back to Products</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8" x-data="productForm()">
            <form method="POST" action="/products" class="space-y-6" id="productForm" @submit="validateForm($event)">
                @csrf

                @if($errors->any())
                    <div class="p-4 bg-red-50 border border-red-200 rounded-xl">
                        <ul class="list-disc list-inside text-sm text-red-700">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-6 pb-4 border-b border-gray-200 dark:border-gray-700">Product Information</h3>
                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Product Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" value="{{ old('name') }}" required
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-6 pb-4 border-b border-gray-200 dark:border-gray-700">FBR & Tariff Mapping</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">HS Code <span class="text-red-500">*</span></label>
                            <input type="text" name="hs_code" value="{{ old('hs_code') }}" required placeholder="25232900"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <p class="text-gray-500 text-xs mt-2">e.g., 25232900 for Cement</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">PCT Code <span class="text-gray-400 text-xs">(Optional)</span></label>
                            <input type="text" name="pct_code" value="{{ old('pct_code') }}"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Schedule Type <span class="text-red-500">*</span></label>
                            <select name="schedule_type" required x-model="scheduleType" @change="updateRules()"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">Select Schedule Type</option>
                                <option value="standard">Standard</option>
                                <option value="reduced">Reduced</option>
                                <option value="3rd_schedule">3rd Schedule</option>
                                <option value="exempt">Exempt</option>
                                <option value="zero_rated">Zero Rated</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Default Tax Rate (%) <span class="text-red-500">*</span></label>
                            <input type="number" step="1" min="0" max="100" name="default_tax_rate" x-model="taxRate" @input="updateRules()" required
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>

                    <div x-show="requiresMrp" x-cloak class="mb-6">
                        <div class="p-4 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-700 rounded-lg">
                            <label class="block text-sm font-medium text-purple-700 dark:text-purple-300 mb-1">MRP / Retail Price (Rs.) <span class="text-red-500">*</span></label>
                            <p class="text-xs text-purple-600 dark:text-purple-400 mb-2">Required for 3rd Schedule items</p>
                            <input type="number" step="0.01" min="0" name="mrp" x-model="mrp" @input="calcTax()"
                                class="w-full rounded-lg border-purple-300 dark:border-purple-600 bg-white dark:bg-gray-800 shadow-sm focus:ring-purple-500 focus:border-purple-500">
                            @error('mrp') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div x-show="requiresSro" x-cloak class="mb-6">
                        <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg">
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-sm font-medium text-amber-700 dark:text-amber-300">SRO Reference <span class="text-red-500">*</span></label>
                                <button type="button" @click="sroLookupOpen = !sroLookupOpen" class="text-xs text-emerald-600 hover:text-emerald-700 font-medium flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                                    SRO Lookup
                                </button>
                            </div>
                            <input type="text" name="sro_reference" x-model="sroReference" value="{{ old('sro_reference') }}" placeholder="e.g., SRO 1125(I)/2011"
                                class="w-full rounded-lg border-amber-300 dark:border-amber-600 bg-white dark:bg-gray-800 shadow-sm focus:ring-amber-500 focus:border-amber-500">
                            @error('sro_reference') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror

                            <div x-show="sroLookupOpen" x-cloak class="mt-2 border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 p-3">
                                <div class="flex gap-2 mb-2">
                                    <input type="text" x-model="sroSearch" @keydown.enter.prevent="searchSro()" placeholder="Search SRO rules..." class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                    <button type="button" @click="searchSro()" class="px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-sm hover:bg-emerald-700">Search</button>
                                </div>
                                <div class="max-h-48 overflow-y-auto space-y-1" x-html="sroResults"></div>
                            </div>
                        </div>
                    </div>

                    <div x-show="requiresSerial" x-cloak class="mb-6">
                        <div class="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg">
                            <label class="block text-sm font-medium text-blue-700 dark:text-blue-300 mb-1">SRO Item Serial Number <span class="text-red-500">*</span></label>
                            <p class="text-xs text-blue-600 dark:text-blue-400 mb-2">Required for this schedule type</p>
                            <input type="text" name="serial_number" x-model="serialNumber" value="{{ old('serial_number') }}" placeholder="e.g., 1, 2, 3..."
                                class="w-full rounded-lg border-blue-300 dark:border-blue-600 bg-white dark:bg-gray-800 shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            @error('serial_number') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Unit of Measure <span class="text-red-500">*</span></label>
                            <select name="uom" required class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">Select UOM</option>
                                @foreach(['PCS', 'KG', 'LTR', 'MTR', 'SQM', 'CBM', 'DOZ', 'SET', 'PKT'] as $unit)
                                    <option value="{{ $unit }}" {{ old('uom', 'PCS') === $unit ? 'selected' : '' }}>{{ $unit }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-6 pb-4 border-b border-gray-200 dark:border-gray-700">Pricing & Tax Calculation</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Default Price (Rs.) <span class="text-red-500">*</span></label>
                            <input type="number" step="0.01" min="0" name="default_price" x-model="price" @input="calcTax()" required
                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>

                    <div class="p-4 rounded-lg border" :class="taxAmount > 0 ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-700' : 'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700'">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Tax Calculation Preview (per unit)</h4>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <div class="text-center p-2 bg-white dark:bg-gray-900 rounded-lg border border-gray-100 dark:border-gray-700">
                                <p class="text-xs text-gray-500 mb-1">Price</p>
                                <p class="text-sm font-bold text-gray-800 dark:text-gray-200">Rs. <span x-text="formatNum(price)">0.00</span></p>
                            </div>
                            <div class="text-center p-2 bg-white dark:bg-gray-900 rounded-lg border border-gray-100 dark:border-gray-700">
                                <p class="text-xs text-gray-500 mb-1">Tax Rate</p>
                                <p class="text-sm font-bold" :class="parseFloat(taxRate) > 0 ? 'text-amber-600' : 'text-gray-800 dark:text-gray-200'"><span x-text="taxRate">0</span>%</p>
                            </div>
                            <div class="text-center p-2 bg-white dark:bg-gray-900 rounded-lg border border-gray-100 dark:border-gray-700">
                                <p class="text-xs text-gray-500 mb-1">Tax Amount</p>
                                <p class="text-sm font-bold text-red-600">Rs. <span x-text="formatNum(taxAmount)">0.00</span></p>
                            </div>
                            <div class="text-center p-2 rounded-lg border-2" :class="taxAmount > 0 ? 'bg-emerald-100 dark:bg-emerald-900/40 border-emerald-300 dark:border-emerald-600' : 'bg-white dark:bg-gray-900 border-gray-100 dark:border-gray-700'">
                                <p class="text-xs text-gray-500 mb-1">Total (incl. Tax)</p>
                                <p class="text-sm font-bold text-emerald-700 dark:text-emerald-400">Rs. <span x-text="formatNum(totalWithTax)">0.00</span></p>
                            </div>
                        </div>
                        <div x-show="requiresMrp && parseFloat(mrp) > 0" x-cloak class="mt-3 p-2 bg-purple-50 dark:bg-purple-900/20 rounded-lg border border-purple-200 dark:border-purple-700">
                            <div class="flex justify-between items-center">
                                <span class="text-xs text-purple-600 dark:text-purple-400">MRP / Retail Price</span>
                                <span class="text-sm font-bold text-purple-700 dark:text-purple-300">Rs. <span x-text="formatNum(mrp)">0.00</span></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="/products" class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-50 dark:hover:bg-gray-800 transition">Cancel</a>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">
                        Create Product
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function productForm() {
        return {
            scheduleType: '{{ old("schedule_type", "") }}',
            taxRate: '{{ old("default_tax_rate", "18") }}',
            price: '{{ old("default_price", "0") }}',
            mrp: '{{ old("mrp", "") }}',
            sroReference: '{{ old("sro_reference", "") }}',
            serialNumber: '{{ old("serial_number", "") }}',
            requiresSro: false,
            requiresSerial: false,
            requiresMrp: false,
            taxAmount: 0,
            totalWithTax: 0,
            sroLookupOpen: false,
            sroSearch: '',
            sroResults: '',

            init() {
                this.updateRules();
                this.calcTax();
                this.$watch('scheduleType', () => this.updateRules());
                this.$watch('taxRate', () => { this.updateRules(); this.calcTax(); });
                this.$watch('price', () => this.calcTax());
            },

            updateRules() {
                const st = this.scheduleType;
                const rate = parseFloat(this.taxRate) || 0;

                this.requiresSro = false;
                this.requiresSerial = false;
                this.requiresMrp = false;

                if (st === '3rd_schedule') {
                    this.requiresMrp = true;
                    if (rate < 18) {
                        this.requiresSro = true;
                        this.requiresSerial = true;
                    }
                } else if (st === 'reduced') {
                    this.requiresSro = true;
                    this.requiresSerial = true;
                } else if (st === 'exempt') {
                    this.requiresSro = true;
                }
                this.calcTax();
            },

            calcTax() {
                const p = parseFloat(this.price) || 0;
                const r = parseFloat(this.taxRate) || 0;
                this.taxAmount = (p * r) / 100;
                this.totalWithTax = p + this.taxAmount;
            },

            formatNum(val) {
                return (parseFloat(val) || 0).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            },

            validateForm(e) {
                let errors = [];
                if (this.requiresSro && !this.sroReference.trim()) errors.push('SRO Reference is required');
                if (this.requiresSerial && !this.serialNumber.trim()) errors.push('Serial Number is required');
                if (this.requiresMrp && (!this.mrp || parseFloat(this.mrp) <= 0)) errors.push('MRP / Retail Price is required for this schedule type');

                if (errors.length > 0) {
                    e.preventDefault();
                    alert(errors.join('\n'));
                }
            },

            selectSro(sroNumber) {
                this.sroReference = sroNumber;
                this.sroLookupOpen = false;
            },

            searchSro() {
                const q = this.sroSearch;
                const st = this.scheduleType;
                if (q.length < 2 && !st) {
                    this.sroResults = '<p class="text-xs text-gray-500">Type at least 2 characters</p>';
                    return;
                }
                this.sroResults = '<p class="text-xs text-gray-500">Searching...</p>';
                let url = '/api/sro-reference/search?q=' + encodeURIComponent(q);
                if (st) url += '&schedule_type=' + encodeURIComponent(st);
                fetch(url).then(r => r.json()).then(data => {
                    const rules = data.sro_rules || [];
                    if (rules.length === 0) {
                        this.sroResults = '<p class="text-xs text-gray-500">No results found</p>';
                        return;
                    }
                    let html = '';
                    rules.forEach(r => {
                        const badge = {exempt:'bg-green-100 text-green-700', zero_rated:'bg-blue-100 text-blue-700', '3rd_schedule':'bg-purple-100 text-purple-700', reduced:'bg-amber-100 text-amber-700'}[r.schedule_type] || 'bg-gray-100 text-gray-700';
                        html += '<div class="p-2 bg-white border border-gray-200 rounded cursor-pointer hover:bg-emerald-50 transition" onclick="document.querySelector(\'[x-data]\')._x_dataStack[0].selectSro(\'' + (r.sro_number || '').replace(/'/g, "\\'") + '\')">' +
                            '<div class="flex items-center justify-between">' +
                            '<span class="text-sm font-medium text-gray-800">' + (r.sro_number || '') + '</span>' +
                            '<span class="text-xs px-1.5 py-0.5 rounded ' + badge + '">' + (r.schedule_type || '').replace('_', ' ') + '</span>' +
                            '</div>' +
                            '<p class="text-xs text-gray-500 mt-0.5">' + (r.description || '') + '</p>' +
                            (r.serial_no ? '<p class="text-xs text-gray-400">Serial: ' + r.serial_no + '</p>' : '') +
                            '</div>';
                    });
                    this.sroResults = html;
                }).catch(() => {
                    this.sroResults = '<p class="text-xs text-red-500">Search failed</p>';
                });
            }
        }
    }
    </script>
</x-app-layout>
