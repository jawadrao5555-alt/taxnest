<x-fbr-pos-layout>
<div class="max-w-5xl mx-auto" x-data="fbrPosInvoice()">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">New FBR POS Sale</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Create a new point of sale transaction</p>
        </div>
        <div class="flex items-center gap-2">
            @if(!$fbrReportingEnabled)
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                LOCAL MODE
            </span>
            @endif
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $company->fbr_pos_environment === 'production' ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' }}">
                {{ strtoupper($company->fbr_pos_environment ?? 'sandbox') }}
            </span>
        </div>
    </div>

    @if(!$fbrReportingEnabled)
    <div class="mb-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-300 px-4 py-3 rounded-lg">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            <span class="text-sm font-medium">FBR Reporting is OFF — this invoice will be saved locally as <strong>FLOCAL-xxxx</strong> and will NOT be submitted to FBR.</span>
        </div>
    </div>
    @endif

    {{-- ============ Phase 2 Top Action Bar ============ --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-3">
        <div class="flex items-center gap-3 flex-wrap">
            <div class="flex items-center gap-2">
                <label class="text-xs font-semibold text-gray-700 dark:text-gray-300">Counter:</label>
                <select x-model="terminalId" name="terminal_id" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs px-2 py-1 shadow-sm">
                    <option value="">-- Select --</option>
                    @foreach($terminals as $t)
                        <option value="{{ $t->id }}">{{ $t->terminal_name }}</option>
                    @endforeach
                </select>
                <a href="{{ route('fbrpos.phase2.terminals') }}" class="text-xs text-blue-600 hover:underline">+ Add</a>
            </div>
            @if($currentShift)
                <span class="px-2 py-1 bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300 rounded text-xs font-bold">
                    SHIFT #{{ $currentShift->id }} OPEN · Rs {{ number_format($currentShift->opening_cash, 0) }}
                </span>
            @else
                <a href="{{ route('fbrpos.phase2.shifts') }}" class="px-2 py-1 bg-red-100 text-red-700 hover:bg-red-200 rounded text-xs font-bold">⚠ NO SHIFT — Open Now</a>
            @endif
        </div>
        <div class="flex items-center gap-2">
            <button type="button" @click="holdSale()" class="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-xs font-bold shadow">⏸ Hold</button>
            <button type="button" @click="openRecall()" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-xs font-bold shadow">⏵ Recall <span x-show="heldList.length > 0" class="ml-1 bg-white text-purple-700 rounded-full px-1.5 text-[10px]" x-text="heldList.length"></span></button>
            <a href="{{ route('fbrpos.phase2.shifts') }}" class="px-3 py-1.5 bg-gray-700 hover:bg-gray-800 text-white rounded-lg text-xs font-bold shadow">$ Drawer</a>
        </div>
    </div>

    {{-- Recall Modal --}}
    <div x-show="recallOpen" x-cloak @click.self="recallOpen = false" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-lg w-full p-5 max-h-[80vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-3"><h3 class="font-bold dark:text-white">Held Sales</h3><button type="button" @click="recallOpen = false" class="text-gray-500 hover:text-gray-800">✕</button></div>
            <template x-if="heldList.length === 0"><p class="text-sm text-gray-500 py-6 text-center">No held sales</p></template>
            <template x-for="h in heldList" :key="h.id">
                <div class="border dark:border-gray-700 rounded-lg p-3 mb-2 flex items-center justify-between">
                    <div>
                        <div class="font-semibold dark:text-white" x-text="h.hold_name"></div>
                        <div class="text-xs text-gray-500" x-text="(h.customer_name || 'Walk-in') + ' · ' + new Date(h.created_at).toLocaleString()"></div>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" @click="recallSale(h.id)" class="px-3 py-1 bg-emerald-600 text-white rounded text-xs font-bold">Recall</button>
                        <button type="button" @click="deleteHeld(h.id)" class="px-3 py-1 bg-red-600 text-white rounded text-xs">Delete</button>
                    </div>
                </div>
            </template>
        </div>
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
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-semibold text-gray-900 dark:text-white">Items</h3>
                        <div class="flex items-center gap-3">
                            <div class="relative" x-data="{ searchOpen: false, searchQuery: '', searchResults: [] }">
                                <button type="button" @click="searchOpen = !searchOpen; $nextTick(() => $refs.productSearch && $refs.productSearch.focus())"
                                    class="text-sm text-blue-600 hover:text-blue-700 font-medium flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                    Search Product
                                </button>
                                <div x-show="searchOpen" @click.away="searchOpen = false" x-cloak x-transition
                                    class="absolute right-0 top-8 w-80 bg-white dark:bg-gray-800 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 z-50 p-3">
                                    <input type="text" x-ref="productSearch" x-model="searchQuery"
                                        @input.debounce.300ms="
                                            if(searchQuery.length >= 1) {
                                                fetch('{{ route('fbrpos.api.products.search') }}?q=' + encodeURIComponent(searchQuery))
                                                    .then(r => r.json())
                                                    .then(data => searchResults = data)
                                            } else { searchResults = [] }
                                        "
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500 mb-2"
                                        placeholder="Type product name / barcode / SKU...">
                                    <div class="max-h-60 overflow-y-auto space-y-1">
                                        <template x-for="p in searchResults" :key="p.id">
                                            <button type="button" @click="addProductItem(p); searchOpen = false; searchQuery = ''; searchResults = [];"
                                                class="w-full text-left px-3 py-2.5 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 transition flex items-center justify-between group">
                                                <div>
                                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="p.name"></p>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                                        <span x-text="'PKR ' + Number(p.default_price).toFixed(2)"></span>
                                                        <span class="mx-1">|</span>
                                                        <span x-text="p.hs_code || 'No HS'"></span>
                                                        <template x-if="p.barcode"><span class="ml-1 font-mono text-[10px]" x-text="'· ' + p.barcode"></span></template>
                                                    </p>
                                                </div>
                                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full"
                                                    :class="p.tax_type === 'exempt' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'"
                                                    x-text="p.tax_type === 'exempt' ? 'Exempt' : (p.default_tax_rate + '%')"></span>
                                            </button>
                                        </template>
                                        <p x-show="searchQuery.length >= 1 && searchResults.length === 0" class="text-xs text-gray-500 dark:text-gray-400 text-center py-3">No products found</p>
                                        <p x-show="searchQuery.length < 1" class="text-xs text-gray-500 dark:text-gray-400 text-center py-3">Type to search saved products</p>
                                    </div>
                                </div>
                            </div>
                            <button type="button" @click="addItem()" class="text-sm text-blue-600 hover:text-blue-700 font-medium">+ Add Manual</button>
                        </div>
                    </div>

                    <div class="mb-4 flex items-center gap-2 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-2">
                        <svg class="w-5 h-5 text-blue-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10m4-10v10m4-10v10m4-10v10m-12 0h16"/></svg>
                        <input type="text" x-ref="barcodeInput" x-model="barcodeBuffer"
                            @keydown.enter.prevent="scanBarcode()"
                            autocomplete="off"
                            class="flex-1 bg-transparent border-0 focus:ring-0 text-sm font-mono dark:text-white placeholder-gray-400"
                            placeholder="Scan barcode here (or type code + Enter) — auto-adds product">
                        <span x-show="scanStatus" :class="scanStatus.ok ? 'text-green-600' : 'text-red-600'" class="text-xs font-semibold" x-text="scanStatus.msg"></span>
                    </div>

                    <template x-for="(item, index) in items" :key="index">
                        <div class="border rounded-lg p-4 mb-3 transition"
                            :class="item.is_tax_exempt ? 'border-green-300 dark:border-green-700 bg-green-50/30 dark:bg-green-900/10' : 'border-gray-200 dark:border-gray-700'">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400" x-text="'Item #' + (index + 1)"></span>
                                    <span x-show="item.is_tax_exempt"
                                        class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">EXEMPT</span>
                                    <span x-show="!item.is_tax_exempt && item.tax_rate != 18"
                                        class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300"
                                        x-text="item.tax_rate + '% TAX'"></span>
                                    <span x-show="!item.is_tax_exempt && item.tax_rate == 18"
                                        class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">18% GST</span>
                                </div>
                                <button type="button" @click="removeItem(index)" x-show="items.length > 1" class="text-red-500 hover:text-red-700 text-xs">Remove</button>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                                <input type="hidden" :name="'items['+index+'][product_id]'" :value="item.product_id || ''">
                                <div class="sm:col-span-4">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Item Name *</label>
                                    <input type="text" :name="'items['+index+'][item_name]'" x-model="item.item_name" required
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                        placeholder="Product name">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">HS Code <span class="text-gray-400 font-normal">(Optional)</span></label>
                                    <input type="text" :name="'items['+index+'][hs_code]'" x-model="item.hs_code"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                        placeholder="00000000">
                                </div>
                                <div class="sm:col-span-1">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">UoM</label>
                                    <select :name="'items['+index+'][uom]'" x-model="item.uom"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                        <template x-for="u in uomOptions" :key="u">
                                            <option :value="u" x-text="u"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="sm:col-span-1">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Qty *</label>
                                    <div class="flex items-stretch">
                                        <button type="button" tabindex="-1" @click="decQty(item)" class="px-1.5 rounded-l-lg border border-r-0 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-sm font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-100">−</button>
                                        <input type="text" inputmode="decimal" autocomplete="off" maxlength="10"
                                            :name="'items['+index+'][quantity]'"
                                            :value="item.quantity"
                                            @input="item.quantity = sanitizeQty($event.target.value)"
                                            @focus="$nextTick(() => $event.target.select())"
                                            @mousedown="if(document.activeElement !== $event.target){ $event.preventDefault(); $event.target.focus(); $event.target.select(); }"
                                            @blur="if(!item.quantity || parseFloat(item.quantity) <= 0){ item.quantity = 1; }"
                                            required
                                            class="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500 text-center font-semibold"
                                            placeholder="1">
                                        <button type="button" tabindex="-1" @click="incQty(item)" class="px-1.5 rounded-r-lg border border-l-0 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-sm font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-100">+</button>
                                    </div>
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Unit Price *</label>
                                    <input type="number" :name="'items['+index+'][unit_price]'" x-model.number="item.unit_price" min="0.01" step="0.01" required
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                        placeholder="0.00">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Tax %</label>
                                    <input type="number" :name="'items['+index+'][tax_rate]'" x-model.number="item.tax_rate" min="0" max="100" step="0.01"
                                        :disabled="item.is_tax_exempt"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500 disabled:opacity-50"
                                        placeholder="18">
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center justify-between mt-2 gap-3">
                                <div class="flex items-center gap-3">
                                    <label class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 cursor-pointer">
                                        <input type="checkbox" :name="'items['+index+'][is_tax_exempt]'" x-model="item.is_tax_exempt" value="1"
                                            class="rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5">
                                        Tax Exempt
                                    </label>
                                    <label class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                        <span>Item Discount (PKR):</span>
                                        <input type="number" :name="'items['+index+'][item_discount]'" x-model.number="item.item_discount" min="0" step="0.01"
                                            class="w-24 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                            placeholder="0.00">
                                    </label>
                                </div>
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
                            <input type="text" name="customer_name" x-model="customerName" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="Walk-in Customer">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Phone <span class="text-emerald-600 text-[10px]" x-show="loyaltyEnabled">(loyalty lookup)</span></label>
                            <div class="flex gap-1">
                                <input type="text" name="customer_phone" x-model="customerPhone" @blur="lookupCustomer()" class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="0300-1234567">
                                <button type="button" @click="lookupCustomer()" class="px-3 bg-blue-600 text-white rounded-lg text-sm">Find</button>
                            </div>
                            <input type="hidden" name="customer_id" x-model="customerId">
                            <div x-show="customerPoints !== null" class="mt-2 bg-emerald-50 dark:bg-emerald-900/30 p-2 rounded text-xs">
                                <strong x-text="customerName + ': ' + customerPoints + ' pts'"></strong>
                                <template x-if="customerPoints >= loyaltyMinRedeem">
                                    <div class="mt-1 flex items-center gap-1">
                                        <input type="number" name="loyalty_points_redeemed" x-model.number="loyaltyRedeem" :max="customerPoints" min="0" class="w-20 border rounded px-1 py-0.5 text-xs" placeholder="Pts">
                                        <span x-text="'= Rs ' + (loyaltyRedeem * loyaltyPointValue).toFixed(0)"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">NTN</label>
                            <input type="text" name="customer_ntn" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="Optional">
                        </div>
                    </div>
                </div>

                {{-- Promo Code --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-3">Promo Code</h3>
                    <div class="flex gap-2">
                        <input type="text" x-model="promoCode" placeholder="Enter code" class="flex-1 uppercase rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm">
                        <button type="button" @click="applyPromo()" class="px-3 bg-emerald-600 text-white rounded-lg text-sm font-bold">Apply</button>
                    </div>
                    <input type="hidden" name="promotion_id" x-model="promotionId">
                    <input type="hidden" name="promotion_code" x-model="promoCode">
                    <div x-show="promoMessage" :class="promoOk ? 'text-emerald-700' : 'text-red-700'" class="text-xs mt-2 font-semibold" x-text="promoMessage"></div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Payment</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Method *</label>
                            <select name="payment_method" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="online">Online</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Discount Type</label>
                            <select name="discount_type" x-model="discountType" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="">None</option>
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (PKR)</option>
                            </select>
                        </div>
                        <div x-show="discountType">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Discount Value</label>
                            <input type="number" name="discount_value" x-model.number="discountValue" min="0" step="0.01"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                </div>

                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-800 p-5">
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span>Subtotal</span>
                            <span x-text="'PKR ' + formatNum(calcSubtotal())"></span>
                        </div>
                        <div class="flex justify-between text-gray-600 dark:text-gray-400" x-show="calcDiscount() > 0">
                            <span>Discount</span>
                            <span class="text-red-600" x-text="'-PKR ' + formatNum(calcDiscount())"></span>
                        </div>
                        <div class="flex justify-between text-gray-600 dark:text-gray-400" x-show="promoDiscount > 0">
                            <span>Promo <span class="text-xs" x-text="'(' + promoCode + ')'"></span></span>
                            <span class="text-emerald-600" x-text="'-PKR ' + formatNum(promoDiscount)"></span>
                        </div>
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span>Tax</span>
                            <span x-text="'PKR ' + formatNum(calcTax())"></span>
                        </div>
                        @if($fbrReportingEnabled)
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span>FBR POS Fee <span class="text-xs">(SRO 1279/2021)</span></span>
                            <span>PKR 1.00</span>
                        </div>
                        @endif
                        <div class="flex justify-between text-gray-600 dark:text-gray-400" x-show="loyaltyRedeem > 0">
                            <span>Loyalty Redeemed <span class="text-xs" x-text="'(' + loyaltyRedeem + ' pts)'"></span></span>
                            <span class="text-emerald-600" x-text="'-PKR ' + formatNum(loyaltyRedeem * loyaltyPointValue)"></span>
                        </div>
                        <div class="flex justify-between font-bold text-lg text-blue-800 dark:text-blue-300 pt-2 border-t border-blue-200 dark:border-blue-700">
                            <span>Total</span>
                            <span x-text="'PKR ' + formatNum(calcTotal())"></span>
                        </div>
                    </div>

                    {{-- ⚡ Fast Payment Section --}}
                    <div class="mt-3 pt-3 border-t border-blue-200 dark:border-blue-700 space-y-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center justify-between">
                                <span>💵 Cash Received</span>
                                <button type="button" @click="cashReceived = calcTotal(); $nextTick(() => $refs.cashInput && $refs.cashInput.focus())" class="text-emerald-600 hover:text-emerald-800 text-xs font-bold underline">EXACT</button>
                            </label>
                            <input type="number" name="cash_received" x-model.number="cashReceived" x-ref="cashInput"
                                @keydown.enter.prevent="$refs.completeBtn && $refs.completeBtn.click()"
                                step="0.01" min="0" placeholder="Tendered (Enter = pay)"
                                class="w-full rounded-lg border-2 border-emerald-400 dark:border-emerald-600 dark:bg-gray-800 dark:text-white text-xl font-bold py-3 px-3 shadow-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        {{-- Quick tender buttons --}}
                        <div class="grid grid-cols-4 gap-1">
                            <button type="button" @click="addTender(100)" class="py-2 bg-gray-200 hover:bg-emerald-200 dark:bg-gray-700 dark:hover:bg-emerald-800 text-gray-900 dark:text-white rounded font-bold text-xs">+100</button>
                            <button type="button" @click="addTender(500)" class="py-2 bg-gray-200 hover:bg-emerald-200 dark:bg-gray-700 dark:hover:bg-emerald-800 text-gray-900 dark:text-white rounded font-bold text-xs">+500</button>
                            <button type="button" @click="addTender(1000)" class="py-2 bg-gray-200 hover:bg-emerald-200 dark:bg-gray-700 dark:hover:bg-emerald-800 text-gray-900 dark:text-white rounded font-bold text-xs">+1K</button>
                            <button type="button" @click="addTender(5000)" class="py-2 bg-gray-200 hover:bg-emerald-200 dark:bg-gray-700 dark:hover:bg-emerald-800 text-gray-900 dark:text-white rounded font-bold text-xs">+5K</button>
                        </div>
                        <div class="grid grid-cols-2 gap-1 mt-1">
                            <button type="button" @click="setNote(500)" class="py-1.5 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/40 text-blue-700 dark:text-blue-300 rounded font-bold text-xs">500 note</button>
                            <button type="button" @click="setNote(1000)" class="py-1.5 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/40 text-blue-700 dark:text-blue-300 rounded font-bold text-xs">1000 note</button>
                            <button type="button" @click="setNote(5000)" class="py-1.5 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/40 text-blue-700 dark:text-blue-300 rounded font-bold text-xs">5000 note</button>
                            <button type="button" @click="cashReceived = 0" class="py-1.5 bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:hover:bg-red-900/40 text-red-700 dark:text-red-300 rounded font-bold text-xs">Clear</button>
                        </div>
                        {{-- HUGE Change Due display --}}
                        <div class="mt-2 p-3 rounded-lg text-center"
                            :class="cashReceived <= 0 ? 'bg-gray-100 dark:bg-gray-800' : (changeDue() >= 0 ? 'bg-emerald-100 dark:bg-emerald-900/40' : 'bg-red-100 dark:bg-red-900/40')">
                            <div class="text-xs font-semibold uppercase" :class="changeDue() >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'"
                                x-text="cashReceived <= 0 ? 'CHANGE DUE' : (changeDue() >= 0 ? 'CHANGE TO RETURN' : 'STILL OWED')"></div>
                            <div class="text-3xl font-black tabular-nums tracking-tight"
                                :class="cashReceived <= 0 ? 'text-gray-400' : (changeDue() >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300')"
                                x-text="'Rs ' + formatNum(Math.abs(changeDue()))"></div>
                        </div>
                    </div>
                </div>

                <button type="submit" x-ref="completeBtn"
                    class="w-full py-5 bg-gradient-to-r from-emerald-600 to-blue-600 hover:from-emerald-700 hover:to-blue-700 text-white font-black rounded-xl transition text-lg shadow-xl tracking-wide">
                    ✓ COMPLETE SALE <span class="opacity-70 text-xs font-normal">(F9 / Enter)</span>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
function fbrPosInvoice() {
    return {
        uomOptions: ['U','PCS','KG','GM','LTR','ML','MTR','SQM','FT','IN','YDS','PKT','DOZ','BOX','CTN','BAG','BTL','TIN','CAN','BUN','ROL','SET'],
        items: [{ item_name: '', hs_code: '', uom: 'U', quantity: 1, unit_price: 0, tax_rate: 18, is_tax_exempt: false, item_discount: 0 }],
        discountType: '',
        discountValue: 0,
        barcodeBuffer: '',
        scanStatus: null,
        // Phase 2 state
        terminalId: @json($terminals->first()?->id ?? ''),
        customerId: '',
        customerName: '',
        customerPhone: '',
        customerPoints: null,
        loyaltyEnabled: @json((bool) $loyaltySettings->is_enabled),
        loyaltyPointValue: @json((float) $loyaltySettings->point_value),
        loyaltyMinRedeem: @json((int) $loyaltySettings->min_redeem_points),
        loyaltyRedeem: 0,
        promoCode: '',
        promotionId: '',
        promoDiscount: 0,
        promoMessage: '',
        promoOk: false,
        cashReceived: 0,
        recallOpen: false,
        heldList: [],
        init() {
            this.$nextTick(() => { this.$refs.barcodeInput && this.$refs.barcodeInput.focus(); });
            this.loadHeld();
            // Global keyboard shortcuts: F9 = complete sale, F2 = focus cash, Esc = clear cash
            window.addEventListener('keydown', (e) => {
                if (e.key === 'F9') { e.preventDefault(); this.$refs.completeBtn && this.$refs.completeBtn.click(); }
                else if (e.key === 'F2') { e.preventDefault(); this.cashReceived = this.calcTotal(); this.$refs.cashInput && this.$refs.cashInput.focus(); }
                else if (e.key === 'F4') { e.preventDefault(); this.holdSale(); }
                else if (e.key === 'F5') { e.preventDefault(); this.openRecall(); }
            });
        },
        async loadHeld() {
            try { const r = await fetch("{{ route('fbrpos.phase2.held.list') }}"); this.heldList = await r.json(); } catch(e) {}
        },
        openRecall() { this.loadHeld(); this.recallOpen = true; },
        async holdSale() {
            const name = prompt('Hold name (e.g. "Customer at Counter")', this.customerName || ('Hold ' + new Date().toLocaleTimeString()));
            if (!name) return;
            const cart = { items: this.items, discountType: this.discountType, discountValue: this.discountValue,
                customer_name: this.customerName, customer_phone: this.customerPhone };
            const fd = new FormData();
            fd.append('hold_name', name);
            fd.append('customer_name', this.customerName || '');
            fd.append('customer_phone', this.customerPhone || '');
            fd.append('terminal_id', this.terminalId || '');
            fd.append('cart_data', JSON.stringify(cart));
            // also send as nested keys
            Object.keys(cart).forEach(k => { if (k !== 'items') fd.append('cart_data[' + k + ']', cart[k] || ''); });
            cart.items.forEach((it, i) => {
                Object.keys(it).forEach(k => fd.append('cart_data[items][' + i + '][' + k + ']', it[k] ?? ''));
            });
            try {
                const r = await fetch("{{ route('fbrpos.phase2.hold') }}", { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }, body: fd });
                if (r.ok) { alert('Sale held: ' + name); this.resetCart(); this.loadHeld(); }
            } catch(e) { alert('Failed to hold: ' + e.message); }
        },
        async recallSale(id) {
            try {
                const r = await fetch("/fbr-pos/api/held/" + id + "/recall");
                const data = await r.json();
                if (data.success && data.cart) {
                    this.items = data.cart.items || this.items;
                    this.discountType = data.cart.discountType || '';
                    this.discountValue = data.cart.discountValue || 0;
                    this.customerName = data.cart.customer_name || '';
                    this.customerPhone = data.cart.customer_phone || '';
                    this.recallOpen = false;
                    this.loadHeld();
                    if (this.customerPhone) this.lookupCustomer();
                }
            } catch(e) {}
        },
        async deleteHeld(id) {
            if (!confirm('Delete held sale?')) return;
            await fetch("/fbr-pos/api/held/" + id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });
            this.loadHeld();
        },
        resetCart() {
            this.items = [{ item_name: '', hs_code: '', uom: 'U', quantity: 1, unit_price: 0, tax_rate: 18, is_tax_exempt: false, item_discount: 0 }];
            this.discountType = ''; this.discountValue = 0;
            this.customerName = ''; this.customerPhone = ''; this.customerId = ''; this.customerPoints = null;
            this.promoCode = ''; this.promotionId = ''; this.promoDiscount = 0; this.promoMessage = '';
            this.loyaltyRedeem = 0; this.cashReceived = 0;
        },
        async lookupCustomer() {
            if (!this.customerPhone || this.customerPhone.length < 4) { this.customerPoints = null; this.customerId = ''; return; }
            try {
                const r = await fetch("/fbr-pos/api/customer/" + encodeURIComponent(this.customerPhone) + "/points");
                const d = await r.json();
                if (d.ok) {
                    this.customerId = d.id;
                    this.customerName = this.customerName || d.name;
                    this.customerPoints = d.points;
                    this.loyaltyEnabled = d.enabled;
                    this.loyaltyPointValue = d.point_value;
                    this.loyaltyMinRedeem = d.min_redeem;
                } else { this.customerPoints = null; this.customerId = ''; }
            } catch(e) {}
        },
        async applyPromo() {
            if (!this.promoCode) { this.promoMessage = 'Enter promo code'; this.promoOk = false; return; }
            const fd = new FormData();
            fd.append('code', this.promoCode);
            fd.append('subtotal', this.calcSubtotal());
            try {
                const r = await fetch("{{ route('fbrpos.phase2.promo.validate') }}", { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }, body: fd });
                const d = await r.json();
                if (d.ok) {
                    this.promotionId = d.promotion_id;
                    this.promoDiscount = d.discount;
                    this.promoMessage = '✓ ' + d.promotion_name + ' applied: -Rs ' + d.discount;
                    this.promoOk = true;
                } else {
                    this.promotionId = ''; this.promoDiscount = 0;
                    this.promoMessage = '✗ ' + (d.msg || 'Invalid'); this.promoOk = false;
                }
            } catch(e) { this.promoMessage = 'Error'; this.promoOk = false; }
        },
        changeDue() {
            return Math.round((parseFloat(this.cashReceived || 0) - this.calcTotal()) * 100) / 100;
        },
        addTender(amount) {
            const cur = parseFloat(this.cashReceived || 0);
            this.cashReceived = Math.round((cur + amount) * 100) / 100;
            this.$nextTick(() => this.$refs.cashInput && this.$refs.cashInput.focus());
        },
        setNote(amount) {
            const total = this.calcTotal();
            const notes = Math.ceil(total / amount);
            this.cashReceived = notes * amount;
            this.$nextTick(() => this.$refs.cashInput && this.$refs.cashInput.focus());
        },
        sanitizeQty(v) {
            if (v === '' || v === null || v === undefined) return '';
            let s = String(v).replace(/[^0-9.]/g, '');
            const parts = s.split('.');
            if (parts.length > 2) s = parts[0] + '.' + parts.slice(1).join('');
            return s;
        },
        incQty(item) {
            let cur = parseFloat(item.quantity) || 0;
            item.quantity = (cur + 1).toString();
        },
        decQty(item) {
            let cur = parseFloat(item.quantity) || 0;
            let next = cur - 1;
            if (next < 1) next = 1;
            item.quantity = next.toString();
        },
        addItem() {
            this.items.push({ item_name: '', hs_code: '', uom: 'U', quantity: 1, unit_price: 0, tax_rate: 18, is_tax_exempt: false, item_discount: 0 });
        },
        addProductItem(p) {
            let isExempt = p.tax_type === 'exempt';
            let taxRate = isExempt ? 0 : (parseFloat(p.default_tax_rate) || 18);
            // If same product already in cart, just increment qty
            const existing = this.items.find(it => it.product_id && p.id && it.product_id === p.id);
            if (existing) {
                existing.quantity = (parseFloat(existing.quantity) || 0) + 1;
                return;
            }
            // If first row is empty, fill it instead of adding new
            if (this.items.length === 1 && !this.items[0].item_name && !this.items[0].product_id) {
                this.items[0] = {
                    item_name: p.name, hs_code: p.hs_code || '', uom: p.uom || 'U',
                    quantity: 1, unit_price: parseFloat(p.default_price) || 0,
                    tax_rate: taxRate, is_tax_exempt: isExempt, item_discount: 0, product_id: p.id
                };
                return;
            }
            this.items.push({
                item_name: p.name, hs_code: p.hs_code || '', uom: p.uom || 'U',
                quantity: 1, unit_price: parseFloat(p.default_price) || 0,
                tax_rate: taxRate, is_tax_exempt: isExempt, item_discount: 0, product_id: p.id
            });
        },
        async scanBarcode() {
            const code = (this.barcodeBuffer || '').trim();
            if (!code) return;
            try {
                const res = await fetch('{{ route('fbrpos.api.products.barcode') }}?code=' + encodeURIComponent(code));
                const data = await res.json();
                if (data.found) {
                    this.addProductItem(data.product);
                    this.scanStatus = { ok: true, msg: '✓ ' + data.product.name };
                } else {
                    this.scanStatus = { ok: false, msg: 'Not found: ' + code };
                }
            } catch (e) {
                this.scanStatus = { ok: false, msg: 'Lookup failed' };
            }
            this.barcodeBuffer = '';
            setTimeout(() => { this.scanStatus = null; }, 2500);
            this.$nextTick(() => { this.$refs.barcodeInput && this.$refs.barcodeInput.focus(); });
        },
        removeItem(index) {
            this.items.splice(index, 1);
            if (this.items.length === 0) this.addItem();
        },
        lineGross(item) {
            return (parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0);
        },
        lineNet(item) {
            const gross = this.lineGross(item);
            const disc = Math.min(parseFloat(item.item_discount) || 0, gross);
            return gross - disc;
        },
        lineTotal(item) {
            const net = this.lineNet(item);
            const taxRate = item.is_tax_exempt ? 0 : (parseFloat(item.tax_rate) || 0);
            return net + (net * taxRate / 100);
        },
        calcSubtotal() {
            return this.items.reduce((sum, item) => sum + this.lineNet(item), 0);
        },
        calcDiscount() {
            let sub = this.calcSubtotal();
            if (this.discountType === 'percentage') return sub * (this.discountValue || 0) / 100;
            if (this.discountType === 'fixed') return Math.min(this.discountValue || 0, sub);
            return 0;
        },
        calcTax() {
            return this.items.reduce((sum, item) => {
                const net = this.lineNet(item);
                const taxRate = item.is_tax_exempt ? 0 : (parseFloat(item.tax_rate) || 0);
                return sum + (net * taxRate / 100);
            }, 0);
        },
        calcTotal() {
            var fbrCharge = {{ $fbrReportingEnabled ? '1' : '0' }};
            return this.calcSubtotal() - this.calcDiscount() + this.calcTax() + fbrCharge;
        },
        formatNum(n) {
            return Number(n).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    };
}
</script>
</x-fbr-pos-layout>
