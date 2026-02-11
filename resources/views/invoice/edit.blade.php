<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Edit Invoice {{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? '#' . $invoice->id }}</h2>
            <a href="/invoice/{{ $invoice->id }}" class="text-sm text-gray-600 hover:text-gray-800">Back to Invoice</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <form method="POST" action="/invoice/{{ $invoice->id }}" x-data="invoiceEditForm()" class="space-y-6">
                @csrf
                @method('PUT')

                @if(session('error'))
                <div class="bg-red-50 border border-red-200 rounded-2xl p-4">
                    <p class="text-sm text-red-700">{{ session('error') }}</p>
                </div>
                @endif

                @if($errors->any())
                <div class="bg-red-50 border border-red-200 rounded-2xl p-4">
                    <ul class="list-disc list-inside text-sm text-red-700">
                        @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif

                @if(isset($branches) && $branches->count() > 0)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Branch</h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Select Branch (Optional)</label>
                        <select name="branch_id" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="">— Select Branch —</option>
                            @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" {{ old('branch_id', $invoice->branch_id) == $branch->id ? 'selected' : '' }}>
                                {{ $branch->name }}{{ $branch->is_head_office ? ' (Head Office)' : '' }}
                            </option>
                            @endforeach
                        </select>
                        @error('branch_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                @endif

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Buyer Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Buyer Name</label>
                            <input type="text" name="buyer_name" x-model="buyer_name" required
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Buyer NTN</label>
                            <input type="text" name="buyer_ntn" x-model="buyer_ntn" required
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Invoice Items</h3>
                        <button type="button" @click="addItem()" class="inline-flex items-center px-3 py-1.5 bg-emerald-50 text-emerald-700 rounded-lg text-sm font-medium hover:bg-emerald-100 transition">+ Add Item</button>
                    </div>

                    <div x-show="scheduleError" x-cloak class="mb-4 bg-red-50 border border-red-200 rounded-lg p-3">
                        <p class="text-sm text-red-700 font-medium" x-text="scheduleError"></p>
                    </div>

                    <template x-for="(item, index) in items" :key="index">
                        <div class="border border-gray-200 rounded-xl p-4 mb-4">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-sm font-medium text-gray-600" x-text="'Item #' + (index + 1)"></span>
                                <button type="button" @click="removeItem(index)" x-show="items.length > 1" class="text-red-500 hover:text-red-700 text-sm">Remove</button>
                            </div>

                            <div class="mb-3">
                                <label class="block text-xs font-medium text-gray-500 mb-1">Product (optional - auto-fills schedule info)</label>
                                <div class="relative">
                                    <input type="text" x-model="item.productSearch" @input.debounce.300ms="searchProducts(index)" @focus="searchProducts(index)" @click.away="item.showDropdown = false" placeholder="Search products..."
                                        class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                    <div x-show="item.showDropdown && item.productResults.length > 0" class="absolute z-10 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                        <template x-for="product in item.productResults" :key="product.id">
                                            <button type="button" @click="selectProduct(index, product)" class="w-full text-left px-4 py-2 hover:bg-emerald-50 text-sm border-b border-gray-100 last:border-0">
                                                <span class="font-medium text-gray-800" x-text="product.name"></span>
                                                <span class="text-gray-500 text-xs ml-2" x-text="'HS: ' + product.hs_code + ' | ' + (product.schedule_type || 'standard') + ' | Rs. ' + product.default_price"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Schedule Type</label>
                                    <select :name="'items[' + index + '][schedule_type]'" x-model="item.schedule_type" @change="onScheduleChange(index)"
                                        class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                        <option value="standard">Standard Rate</option>
                                        <option value="reduced">Reduced Rate</option>
                                        <option value="3rd_schedule">3rd Schedule</option>
                                        <option value="exempt">Exempt</option>
                                        <option value="zero_rated">Zero Rated</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">HS Code</label>
                                    <input type="text" :name="'items[' + index + '][hs_code]'" x-model="item.hs_code" @blur="lookupHsCode(index)" required
                                        class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">PCT Code</label>
                                    <input type="text" :name="'items[' + index + '][pct_code]'" x-model="item.pct_code" placeholder="Auto from HS/Product"
                                        class="w-full rounded-lg border-gray-300 shadow-sm text-sm bg-gray-50 focus:ring-emerald-500 focus:border-emerald-500" readonly>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Tax Rate (%)</label>
                                    <input type="number" step="0.01" min="0" x-model="item.tax_rate" @input="calcTax(index)"
                                        class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3" x-show="item.requires_sro || item.requires_serial || item.requires_mrp" x-cloak>
                                <div x-show="item.requires_sro">
                                    <label class="block text-xs font-medium text-amber-600 mb-1">SRO Schedule No *</label>
                                    <input type="text" :name="'items[' + index + '][sro_schedule_no]'" x-model="item.sro_schedule_no" placeholder="e.g. SRO 1125(I)/2011"
                                        class="w-full rounded-lg border-amber-300 shadow-sm text-sm focus:ring-amber-500 focus:border-amber-500 bg-amber-50">
                                </div>
                                <div x-show="item.requires_serial">
                                    <label class="block text-xs font-medium text-amber-600 mb-1">Serial No *</label>
                                    <input type="text" :name="'items[' + index + '][serial_no]'" x-model="item.serial_no" placeholder="e.g. 42"
                                        class="w-full rounded-lg border-amber-300 shadow-sm text-sm focus:ring-amber-500 focus:border-amber-500 bg-amber-50">
                                </div>
                                <div x-show="item.requires_mrp">
                                    <label class="block text-xs font-medium text-amber-600 mb-1">MRP (Rs.) *</label>
                                    <input type="number" step="0.01" min="0" :name="'items[' + index + '][mrp]'" x-model="item.mrp" placeholder="0.00"
                                        class="w-full rounded-lg border-amber-300 shadow-sm text-sm focus:ring-amber-500 focus:border-amber-500 bg-amber-50">
                                </div>
                            </div>

                            <div x-show="item.hsLookupInfo" x-cloak class="mb-3 px-3 py-2 bg-blue-50 border border-blue-200 rounded-lg">
                                <p class="text-xs text-blue-700" x-text="item.hsLookupInfo"></p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                <div class="lg:col-span-1">
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Description</label>
                                    <input type="text" :name="'items[' + index + '][description]'" x-model="item.description" required class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Quantity</label>
                                    <input type="number" step="0.01" :name="'items[' + index + '][quantity]'" x-model="item.quantity" @input="calcTax(index)" required class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Price (Rs.)</label>
                                    <input type="number" step="0.01" :name="'items[' + index + '][price]'" x-model="item.price" @input="calcTax(index)" required class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                            </div>
                            <input type="hidden" :name="'items[' + index + '][tax]'" :value="item.tax">
                            <div class="mt-2 flex justify-between text-sm text-gray-500">
                                <span>Subtotal: <span class="font-medium text-gray-800" x-text="'Rs. ' + itemSubtotal(index)"></span></span>
                                <span>Tax (<span x-text="item.tax_rate"></span>%): <span class="font-medium text-gray-800" x-text="'Rs. ' + parseFloat(item.tax || 0).toFixed(2)"></span></span>
                                <span>Line Total: <span class="font-medium text-gray-800" x-text="'Rs. ' + itemTotal(index)"></span></span>
                            </div>
                        </div>
                    </template>

                    <div class="mt-4 p-4 bg-gray-50 rounded-lg flex justify-between items-center">
                        <span class="text-lg font-semibold text-gray-700">Grand Total</span>
                        <span class="text-2xl font-bold text-emerald-600" x-text="'Rs. ' + grandTotal()"></span>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Live Compliance Check</h3>
                        <button type="button" @click="checkCompliance()" :disabled="complianceLoading" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition disabled:opacity-50">
                            <svg x-show="complianceLoading" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            Check Compliance
                        </button>
                    </div>

                    <div x-show="complianceResult" x-cloak class="space-y-4">
                        <div class="flex items-center justify-between p-4 rounded-lg" :class="complianceResult?.badge?.bg">
                            <div class="flex items-center space-x-3">
                                <span class="text-2xl font-bold" :class="complianceResult?.badge?.text" x-text="complianceResult?.score"></span>
                                <span class="text-sm font-medium" :class="complianceResult?.badge?.text">Compliance Score</span>
                            </div>
                            <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold" :class="complianceResult?.badge?.bg + ' ' + complianceResult?.badge?.text" x-text="complianceResult?.risk_level"></span>
                        </div>

                        <template x-if="complianceResult?.flags">
                            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                                <template x-for="(flagged, flagName) in complianceResult.flags" :key="flagName">
                                    <div class="p-3 rounded-lg border" :class="flagged ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200'">
                                        <p class="text-xs font-medium" :class="flagged ? 'text-red-700' : 'text-green-700'" x-text="flagName.replace('_', ' ')"></p>
                                        <p class="text-sm font-bold" :class="flagged ? 'text-red-800' : 'text-green-800'" x-text="flagged ? 'FLAGGED' : 'OK'"></p>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <template x-if="complianceResult?.details && Object.keys(complianceResult.details).length > 0">
                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                <h4 class="text-sm font-semibold text-yellow-800 mb-2">Warnings</h4>
                                <template x-for="(detail, key) in complianceResult.details" :key="key">
                                    <p class="text-sm text-yellow-700" x-text="typeof detail === 'string' ? detail : JSON.stringify(detail)"></p>
                                </template>
                            </div>
                        </template>
                    </div>

                    <p x-show="!complianceResult && !complianceLoading" class="text-sm text-gray-400">Click "Check Compliance" to preview compliance status before submitting.</p>
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="/invoice/{{ $invoice->id }}" class="px-6 py-2.5 border border-gray-300 rounded-lg text-gray-700 font-medium hover:bg-gray-50 transition">Cancel</a>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">Update Invoice</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function invoiceEditForm() {
            const scheduleConfigs = {
                standard:     { tax_rate: 18, requires_sro: false, requires_serial: false, requires_mrp: false },
                reduced:      { tax_rate: 10, requires_sro: true,  requires_serial: true,  requires_mrp: false },
                '3rd_schedule': { tax_rate: 17, requires_sro: true, requires_serial: true, requires_mrp: true },
                exempt:       { tax_rate: 0,  requires_sro: true,  requires_serial: true,  requires_mrp: false },
                zero_rated:   { tax_rate: 0,  requires_sro: true,  requires_serial: false, requires_mrp: false },
            };

            function getRequirements(scheduleType) {
                let cfg = scheduleConfigs[scheduleType] || scheduleConfigs.standard;
                return { requires_sro: cfg.requires_sro, requires_serial: cfg.requires_serial, requires_mrp: cfg.requires_mrp };
            }

            return {
                buyer_name: @js($invoice->buyer_name),
                buyer_ntn: @js($invoice->buyer_ntn),
                items: {!! json_encode($invoice->items->map(function($i) {
                    $scheduleType = $i->schedule_type ?? 'standard';
                    $config = \App\Services\ScheduleEngine::getScheduleConfig($scheduleType);
                    return [
                        'product_id' => '',
                        'hs_code' => $i->hs_code,
                        'pct_code' => $i->pct_code ?? '',
                        'description' => $i->description,
                        'quantity' => $i->quantity,
                        'price' => $i->price,
                        'tax_rate' => $i->tax_rate ?? (($i->price * $i->quantity) > 0 ? round(($i->tax / ($i->price * $i->quantity)) * 100, 2) : 18),
                        'tax' => $i->tax,
                        'schedule_type' => $scheduleType,
                        'sro_schedule_no' => $i->sro_schedule_no ?? '',
                        'serial_no' => $i->serial_no ?? '',
                        'mrp' => $i->mrp ?? '',
                        'requires_sro' => $config['requires_sro'],
                        'requires_serial' => $config['requires_serial'],
                        'requires_mrp' => $config['requires_mrp'],
                        'productSearch' => '',
                        'showDropdown' => false,
                        'productResults' => [],
                        'hsLookupInfo' => '',
                    ];
                })) !!},
                complianceResult: null,
                complianceLoading: false,
                scheduleError: '',

                addItem() {
                    let item = {
                        product_id: '', hs_code: '', pct_code: '', description: '',
                        quantity: 1, price: 0, tax_rate: 18, tax: 0,
                        schedule_type: 'standard', sro_schedule_no: '', serial_no: '', mrp: '',
                        requires_sro: false, requires_serial: false, requires_mrp: false,
                        productSearch: '', showDropdown: false, productResults: [], hsLookupInfo: ''
                    };
                    if (this.items.length > 0) {
                        item.schedule_type = this.items[0].schedule_type;
                        let cfg = scheduleConfigs[item.schedule_type] || scheduleConfigs.standard;
                        item.tax_rate = cfg.tax_rate;
                        item.requires_sro = cfg.requires_sro;
                        item.requires_serial = cfg.requires_serial;
                        item.requires_mrp = cfg.requires_mrp;
                    }
                    this.items.push(item);
                },
                removeItem(index) { this.items.splice(index, 1); this.validateMixedSchedules(); },

                onScheduleChange(index) {
                    let item = this.items[index];
                    let cfg = scheduleConfigs[item.schedule_type] || scheduleConfigs.standard;
                    item.tax_rate = cfg.tax_rate;
                    item.requires_sro = cfg.requires_sro;
                    item.requires_serial = cfg.requires_serial;
                    item.requires_mrp = cfg.requires_mrp;
                    if (!cfg.requires_sro) item.sro_schedule_no = '';
                    if (!cfg.requires_serial) item.serial_no = '';
                    if (!cfg.requires_mrp) item.mrp = '';
                    this.calcTax(index);
                    this.validateMixedSchedules();
                },

                validateMixedSchedules() {
                    let types = [...new Set(this.items.map(i => i.schedule_type))];
                    if (types.length > 1) {
                        let labels = types.map(t => {
                            let map = { standard: 'Standard', reduced: 'Reduced', '3rd_schedule': '3rd Schedule', exempt: 'Exempt', zero_rated: 'Zero Rated' };
                            return map[t] || t;
                        });
                        this.scheduleError = 'Warning: Mixed schedule types detected (' + labels.join(', ') + '). All items should use the same schedule type.';
                    } else {
                        this.scheduleError = '';
                    }
                },

                async lookupHsCode(index) {
                    let item = this.items[index];
                    if (!item.hs_code || item.hs_code.length < 4) return;
                    if (item.product_id) return;
                    try {
                        let res = await fetch('/api/hs-lookup?hs_code=' + encodeURIComponent(item.hs_code));
                        let data = await res.json();
                        if (data && data.pct_code) {
                            item.pct_code = data.pct_code;
                            item.schedule_type = data.schedule_type;
                            item.tax_rate = data.tax_rate;
                            item.requires_sro = data.requires_sro;
                            item.requires_serial = data.requires_serial;
                            item.requires_mrp = data.requires_mrp;
                            item.hsLookupInfo = 'Auto-detected: PCT ' + data.pct_code + ' | Schedule: ' + data.schedule_type + ' | Tax: ' + data.tax_rate + '%';
                            this.calcTax(index);
                            this.validateMixedSchedules();
                        } else {
                            item.hsLookupInfo = '';
                        }
                    } catch(e) { item.hsLookupInfo = ''; }
                },

                calcTax(index) {
                    let item = this.items[index];
                    let subtotal = parseFloat(item.price || 0) * parseFloat(item.quantity || 0);
                    item.tax = parseFloat(((parseFloat(item.tax_rate || 0) / 100) * subtotal).toFixed(2));
                },
                itemSubtotal(index) {
                    let item = this.items[index];
                    return (parseFloat(item.price || 0) * parseFloat(item.quantity || 0)).toFixed(2);
                },
                itemTotal(index) {
                    let item = this.items[index];
                    return ((parseFloat(item.price || 0) * parseFloat(item.quantity || 0)) + parseFloat(item.tax || 0)).toFixed(2);
                },
                grandTotal() {
                    return this.items.reduce((total, item) => {
                        return total + (parseFloat(item.price || 0) * parseFloat(item.quantity || 0)) + parseFloat(item.tax || 0);
                    }, 0).toFixed(2);
                },

                async searchProducts(index) {
                    let item = this.items[index];
                    let q = item.productSearch || '';
                    if (q.length < 1) { item.showDropdown = false; return; }
                    try {
                        let res = await fetch('/api/products/search?q=' + encodeURIComponent(q));
                        item.productResults = await res.json();
                        item.showDropdown = true;
                    } catch(e) { item.showDropdown = false; }
                },

                selectProduct(index, product) {
                    let item = this.items[index];
                    item.product_id = product.id;
                    item.hs_code = product.hs_code;
                    item.pct_code = product.pct_code || '';
                    item.description = product.name;
                    item.price = parseFloat(product.default_price);
                    item.schedule_type = product.schedule_type || 'standard';
                    let cfg = scheduleConfigs[item.schedule_type] || scheduleConfigs.standard;
                    item.tax_rate = parseFloat(product.default_tax_rate);
                    item.requires_sro = product.requires_sro || cfg.requires_sro;
                    item.requires_serial = product.requires_serial || cfg.requires_serial;
                    item.requires_mrp = product.requires_mrp || cfg.requires_mrp;
                    if (product.sro_reference) item.sro_schedule_no = product.sro_reference;
                    item.productSearch = product.name;
                    item.showDropdown = false;
                    item.hsLookupInfo = 'From product: ' + product.name + ' | Schedule: ' + item.schedule_type;
                    this.calcTax(index);
                    this.validateMixedSchedules();
                },

                async checkCompliance() {
                    this.complianceLoading = true;
                    this.complianceResult = null;
                    try {
                        let res = await fetch('/api/compliance/check', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                            body: JSON.stringify({ buyer_name: this.buyer_name, buyer_ntn: this.buyer_ntn, items: this.items })
                        });
                        this.complianceResult = await res.json();
                    } catch(e) { console.error(e); }
                    this.complianceLoading = false;
                }
            };
        }
    </script>
</x-app-layout>
