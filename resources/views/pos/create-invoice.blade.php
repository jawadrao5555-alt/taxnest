<x-pos-layout>
    <div class="pb-36" x-data="posInvoice()">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

            <template x-if="showDraftRecovery">
                <div class="mb-6 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl p-4">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-amber-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        <div class="flex-1">
                            <h4 class="text-sm font-semibold text-amber-800 dark:text-amber-300">Recovered Draft Invoice</h4>
                            <p class="text-xs text-amber-700 dark:text-amber-400 mt-1">An unfinished invoice was found. Continue editing?</p>
                            <div class="flex gap-2 mt-3">
                                <button type="button" @click="restoreDraft()" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold rounded-lg transition">
                                    Continue Editing
                                </button>
                                <button type="button" @click="discardDraft()" class="px-3 py-1.5 bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 text-xs font-semibold rounded-lg transition">
                                    Discard Draft
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            @if(isset($pendingDrafts) && $pendingDrafts->count() > 0 && !request('draft_id'))
            <div class="mb-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-xl p-4">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-blue-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <div class="flex-1">
                        <h4 class="text-sm font-semibold text-blue-800 dark:text-blue-300">Pending Draft Invoices</h4>
                        <div class="mt-2 space-y-1">
                            @foreach($pendingDrafts as $draft)
                            <div class="flex items-center justify-between text-xs">
                                <a href="{{ route('pos.invoice.create', ['draft_id' => $draft->id]) }}" class="text-blue-600 hover:underline font-medium">
                                    {{ $draft->invoice_number }} - {{ $draft->customer_name ?? 'Walk-in' }} - Rs {{ number_format($draft->total_amount, 2) }}
                                </a>
                                <span class="text-gray-400">{{ $draft->updated_at->diffForHumans() }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
                <div class="flex items-center space-x-3">
                    <a href="{{ route('pos.dashboard') }}" class="inline-flex items-center text-gray-500 hover:text-emerald-600 transition text-sm">
                        <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back
                    </a>
                    <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100">Create POS Invoice</h2>
                </div>
                <div class="mt-2 sm:mt-0 flex items-center space-x-2">
                    <template x-if="draftId">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                            Draft Auto-Saved
                        </span>
                    </template>
                    <template x-if="autoSaveStatus">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs text-gray-400" x-text="autoSaveStatus"></span>
                    </template>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold"
                        :class="praEnabled ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'">
                        <span class="w-2 h-2 rounded-full mr-1.5" :class="praEnabled ? 'bg-emerald-500' : 'bg-gray-400'"></span>
                        PRA Reporting: <span x-text="praEnabled ? 'Active' : 'Inactive'" class="ml-1"></span>
                    </span>
                </div>
            </div>

            <form method="POST" action="{{ route('pos.invoice.store') }}" @submit.prevent="submitForm($event)" class="space-y-6">
                @csrf
                <input type="hidden" name="draft_id" :value="draftId">

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
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Customer Name <span class="text-gray-400">(Optional)</span></label>
                            <input type="text" name="customer_name" x-model="customerName" placeholder="Walk-in Customer" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Phone <span class="text-gray-400">(Optional)</span></label>
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
                        <button type="button" @click="addItem()" class="inline-flex items-center px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg transition btn-premium">
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
                                        @input="open = true; item.name = search; item.item_id = ''; item._isNew = true; recalculate()"
                                        @focus="open = true"
                                        @click.away="open = false"
                                        @keydown.escape="open = false"
                                        @keydown.arrow-down.prevent="$refs['dd'+index] && $refs['dd'+index].querySelector('[role=option]')?.focus()"
                                        placeholder="Type or search product..."
                                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 pr-8 focus:ring-2 focus:ring-emerald-500 transition">
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                                        <template x-if="item.item_id && !item._isNew">
                                            <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        </template>
                                        <template x-if="item._isNew && search.length > 0 && item.type === 'product'">
                                            <span class="text-[9px] font-bold text-purple-500 uppercase">New</span>
                                        </template>
                                    </div>
                                </div>
                                <div x-show="open"
                                    x-transition
                                    :x-ref="'dd'+index"
                                    class="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl max-h-48 overflow-y-auto">
                                    <template x-if="item.type === 'product'">
                                        <div>
                                            <template x-for="p in products.filter(pr => !search || pr.name.toLowerCase().includes(search.toLowerCase()))" :key="p.id">
                                                <button type="button"
                                                    role="option"
                                                    @click="search = p.name; item.name = p.name; item.item_id = p.id; item.unit_price = parseFloat(p.price || p.unit_price || 0); item.is_tax_exempt = !!p.is_tax_exempt; item._isNew = false; open = false; recalculate()"
                                                    class="w-full text-left px-3 py-2 text-sm hover:bg-emerald-50 dark:hover:bg-emerald-900/20 flex justify-between items-center transition">
                                                    <span class="flex items-center gap-1.5">
                                                        <span class="text-gray-900 dark:text-gray-100 font-medium" x-text="p.name"></span>
                                                        <span x-show="p.is_tax_exempt" class="inline-flex px-1 py-0.5 rounded text-[8px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">EXEMPT</span>
                                                    </span>
                                                    <span class="text-xs text-gray-500" x-text="'Rs ' + Number(p.price || p.unit_price || 0).toLocaleString()"></span>
                                                </button>
                                            </template>
                                            <template x-if="search.length > 0 && products.filter(pr => pr.name.toLowerCase().includes(search.toLowerCase())).length === 0">
                                                <div class="px-3 py-2 text-xs text-purple-600 dark:text-purple-400 flex items-center gap-1.5">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                                    "<span x-text="search" class="font-semibold"></span>" will be added as new product
                                                </div>
                                            </template>
                                            <template x-if="search.length > 0 && products.filter(pr => pr.name.toLowerCase() === search.toLowerCase()).length === 0 && products.filter(pr => pr.name.toLowerCase().includes(search.toLowerCase())).length > 0">
                                                <div class="border-t border-gray-100 dark:border-gray-700 px-3 py-2 text-xs text-purple-600 dark:text-purple-400 flex items-center gap-1.5">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                                    Or add "<span x-text="search" class="font-semibold"></span>" as new product
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="item.type === 'service'">
                                        <div>
                                            <template x-for="s in services.filter(sv => !search || sv.name.toLowerCase().includes(search.toLowerCase()))" :key="s.id">
                                                <button type="button"
                                                    role="option"
                                                    @click="search = s.name; item.name = s.name; item.item_id = s.id; item.unit_price = parseFloat(s.price || 0); item.is_tax_exempt = !!s.is_tax_exempt; item._isNew = false; open = false; recalculate()"
                                                    class="w-full text-left px-3 py-2 text-sm hover:bg-emerald-50 dark:hover:bg-emerald-900/20 flex justify-between items-center transition">
                                                    <span class="flex items-center gap-1.5">
                                                        <span class="text-gray-900 dark:text-gray-100 font-medium" x-text="s.name"></span>
                                                        <span x-show="s.is_tax_exempt" class="inline-flex px-1 py-0.5 rounded text-[8px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">EXEMPT</span>
                                                    </span>
                                                    <span class="text-xs text-gray-500" x-text="'Rs ' + Number(s.price || 0).toLocaleString()"></span>
                                                </button>
                                            </template>
                                            <template x-if="services.filter(sv => !search || sv.name.toLowerCase().includes(search.toLowerCase())).length === 0">
                                                <div class="px-3 py-2 text-xs text-gray-400">No matching services found</div>
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
                            <div class="sm:col-span-1 flex flex-col items-start gap-1">
                                <div class="flex items-center gap-1">
                                    <label class="block sm:hidden text-xs text-gray-500 mr-2">Subtotal</label>
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-200" x-text="'Rs ' + (item.quantity * item.unit_price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                                </div>
                                <button type="button" @click="item.is_tax_exempt = !item.is_tax_exempt; recalculate()" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold cursor-pointer transition-all"
                                    :class="item.is_tax_exempt ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 ring-1 ring-amber-300 dark:ring-amber-600' : 'bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500 hover:bg-amber-50 hover:text-amber-600 dark:hover:bg-amber-900/20 dark:hover:text-amber-400'">
                                    <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" x-show="item.is_tax_exempt" d="M5 13l4 4L19 7"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" x-show="!item.is_tax_exempt" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                    <span x-text="item.is_tax_exempt ? 'EXEMPT' : 'Taxable'"></span>
                                </button>
                            </div>
                            <div class="sm:col-span-1 flex items-center justify-end">
                                <button type="button" @click="removeItem(index)" x-show="items.length > 1" class="p-1.5 text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </div>
                    </template>

                    <div x-show="items.length === 0" class="text-center py-8 text-gray-400 dark:text-gray-500">
                        <svg class="w-10 h-10 mx-auto mb-2 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        <p class="text-sm">No items added yet</p>
                    </div>
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
                        <div x-show="exemptAmount > 0" class="flex justify-between text-xs text-amber-600 dark:text-amber-400">
                            <span>Tax Exempt Amount</span>
                            <span class="font-medium" x-text="'Rs ' + exemptAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
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
                        <input type="hidden" :name="'items['+index+'][is_tax_exempt]'" :value="item.is_tax_exempt ? '1' : '0'">
                    </div>
                </template>
                <input type="hidden" name="discount_type" :value="discountType">
                <input type="hidden" name="discount_value" :value="discountValue">
                <input type="hidden" name="payment_method" :value="paymentMethod">
                <input type="hidden" name="terminal_id" :value="terminalId">

                <div class="flex justify-end">
                    <button type="submit" :disabled="items.length === 0 || submitting" class="inline-flex items-center px-6 py-3 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold rounded-xl shadow-sm transition btn-premium">
                        <svg x-show="!submitting" class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <svg x-show="submitting" class="w-5 h-5 mr-2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <span x-text="submitting ? 'Processing...' : 'Create Invoice'"></span>
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
        function posInvoice() {
            return {
                products: @json($products),
                services: @json($services),
                taxRules: @json($taxRules),
                praEnabled: {{ $company->pra_reporting_enabled ? 'true' : 'false' }},

                customerName: '',
                customerPhone: '',
                terminalId: '',

                items: [
                    { type: 'product', item_id: '', name: '', quantity: 1, unit_price: 0, _isNew: false, is_tax_exempt: false }
                ],

                discountType: 'percentage',
                discountValue: 0,
                paymentMethod: 'cash',

                subtotal: 0,
                discountAmount: 0,
                afterDiscount: 0,
                taxRate: 0,
                taxAmount: 0,
                exemptAmount: 0,
                total: 0,

                submitting: false,
                draftId: {!! isset($draftInvoice) && $draftInvoice ? $draftInvoice->id : 'null' !!},
                autoSaveTimer: null,
                autoSaveStatus: '',
                showDraftRecovery: false,
                recoveredDraft: null,

                paymentMethods: [
                    { value: 'cash', label: 'Cash', icon: '💵' },
                    { value: 'debit_card', label: 'Debit Card', icon: '💳' },
                    { value: 'credit_card', label: 'Credit Card', icon: '🏦' },
                    { value: 'qr_payment', label: 'QR / Raast', icon: '📱' }
                ],

                init() {
                    @if(isset($draftInvoice) && $draftInvoice)
                        this.loadServerDraft(@json($draftInvoice));
                    @else
                        this.checkLocalDraft();
                    @endif

                    this.fetchTaxRate(this.paymentMethod);
                    this.recalculate();
                    this.startAutoSave();

                    window.addEventListener('beforeunload', () => {
                        if (!this.submitting) {
                            this.saveToLocalStorage();
                        }
                    });
                },

                loadServerDraft(draft) {
                    this.draftId = draft.id;
                    this.customerName = draft.customer_name || '';
                    this.customerPhone = draft.customer_phone || '';
                    this.terminalId = draft.terminal_id || '';
                    this.discountType = draft.discount_type || 'percentage';
                    this.discountValue = parseFloat(draft.discount_value) || 0;
                    this.paymentMethod = draft.payment_method || 'cash';

                    if (draft.items && draft.items.length > 0) {
                        this.items = draft.items.map(item => {
                            let isExempt = !!item.is_tax_exempt;
                            if (item.item_id && item.item_type === 'product') {
                                const p = this.products.find(pr => pr.id == item.item_id);
                                if (p) isExempt = !!p.is_tax_exempt;
                            } else if (item.item_id && item.item_type === 'service') {
                                const s = this.services.find(sv => sv.id == item.item_id);
                                if (s) isExempt = !!s.is_tax_exempt;
                            }
                            return {
                                type: item.item_type || 'product',
                                item_id: item.item_id || '',
                                name: item.item_name || '',
                                quantity: parseFloat(item.quantity) || 1,
                                unit_price: parseFloat(item.unit_price) || 0,
                                is_tax_exempt: isExempt,
                                _isNew: false
                            };
                        });
                    }
                },

                checkLocalDraft() {
                    try {
                        const saved = localStorage.getItem('pos_draft_invoice');
                        if (saved) {
                            const data = JSON.parse(saved);
                            const hasItems = data && data.items && data.items.length > 0 && data.items.some(i => i.name);
                            const hasCustomer = data && (data.customer_name || data.customer_phone);
                            const hasDraftId = data && data.draft_id;
                            if (hasItems || hasCustomer || hasDraftId) {
                                this.recoveredDraft = data;
                                this.showDraftRecovery = true;
                            }
                        }
                    } catch (e) {}
                },

                restoreDraft() {
                    if (!this.recoveredDraft) return;
                    const d = this.recoveredDraft;
                    this.customerName = d.customer_name || '';
                    this.customerPhone = d.customer_phone || '';
                    this.terminalId = d.terminal_id || '';
                    this.discountType = d.discount_type || 'percentage';
                    this.discountValue = d.discount_value || 0;
                    this.paymentMethod = d.payment_method || 'cash';
                    this.draftId = d.draft_id || null;

                    if (d.items && d.items.length > 0) {
                        this.items = d.items;
                    }

                    this.showDraftRecovery = false;
                    this.recoveredDraft = null;
                    this.fetchTaxRate(this.paymentMethod);
                    this.recalculate();
                },

                discardDraft() {
                    this.showDraftRecovery = false;
                    this.recoveredDraft = null;
                    localStorage.removeItem('pos_draft_invoice');
                },

                saveToLocalStorage() {
                    try {
                        const data = {
                            customer_name: this.customerName,
                            customer_phone: this.customerPhone,
                            terminal_id: this.terminalId,
                            items: this.items,
                            discount_type: this.discountType,
                            discount_value: this.discountValue,
                            payment_method: this.paymentMethod,
                            draft_id: this.draftId,
                            saved_at: new Date().toISOString()
                        };
                        localStorage.setItem('pos_draft_invoice', JSON.stringify(data));
                    } catch (e) {}
                },

                startAutoSave() {
                    this.autoSaveTimer = setInterval(() => {
                        this.autoSaveDraft();
                    }, 10000);
                },

                async autoSaveDraft() {
                    const hasContent = this.items.some(i => i.name && i.name.trim() !== '');
                    if (!hasContent) return;

                    this.saveToLocalStorage();

                    this.recalculate();

                    try {
                        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ||
                                           document.querySelector('input[name="_token"]')?.value;

                        const response = await fetch('/pos/api/draft/save', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                draft_id: this.draftId,
                                terminal_id: this.terminalId,
                                draft_data: {
                                    customer_name: this.customerName,
                                    customer_phone: this.customerPhone,
                                    terminal_id: this.terminalId || null,
                                    items: this.items,
                                    discount_type: this.discountType,
                                    discount_value: this.discountValue,
                                    discount_amount: this.discountAmount,
                                    payment_method: this.paymentMethod,
                                    subtotal: this.subtotal,
                                    tax_rate: this.taxRate,
                                    tax_amount: this.taxAmount,
                                    total_amount: this.total
                                }
                            })
                        });

                        if (response.status === 419) {
                            await this.refreshCsrfToken();
                            this.autoSaveStatus = 'Session refreshed';
                            return;
                        }

                        const result = await response.json();
                        if (result.success && result.draft_id) {
                            this.draftId = result.draft_id;
                            this.autoSaveStatus = 'Saved ' + new Date().toLocaleTimeString();
                        }
                    } catch (e) {
                        this.autoSaveStatus = 'Auto-save: offline';
                    }
                },

                addItem() {
                    this.items.push({ type: 'product', item_id: '', name: '', quantity: 1, unit_price: 0, _isNew: false, is_tax_exempt: false });
                },

                removeItem(index) {
                    this.items.splice(index, 1);
                    this.recalculate();
                },

                onTypeChange(index) {
                    this.items[index].item_id = '';
                    this.items[index].name = '';
                    this.items[index].unit_price = 0;
                    this.items[index].is_tax_exempt = false;
                    this.items[index]._isNew = false;
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
                    let taxableSub = 0;
                    let exemptSub = 0;
                    this.items.forEach(item => {
                        let lineTotal = (parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0);
                        this.subtotal += lineTotal;
                        if (item.is_tax_exempt) {
                            exemptSub += lineTotal;
                        } else {
                            taxableSub += lineTotal;
                        }
                    });
                    this.subtotal = Math.round(this.subtotal * 100) / 100;

                    if (this.discountType === 'percentage') {
                        this.discountAmount = Math.round(this.subtotal * (parseFloat(this.discountValue) || 0) / 100 * 100) / 100;
                    } else {
                        this.discountAmount = Math.min(parseFloat(this.discountValue) || 0, this.subtotal);
                    }

                    this.afterDiscount = Math.round((this.subtotal - this.discountAmount) * 100) / 100;
                    let taxableAfterDiscount = this.subtotal > 0 ? Math.round(taxableSub / this.subtotal * this.afterDiscount * 100) / 100 : 0;
                    this.exemptAmount = Math.round((this.afterDiscount - taxableAfterDiscount) * 100) / 100;
                    this.taxAmount = Math.round(taxableAfterDiscount * this.taxRate / 100 * 100) / 100;
                    this.total = Math.round((this.afterDiscount + this.taxAmount) * 100) / 100;
                },

                async refreshCsrfToken() {
                    try {
                        const resp = await fetch('/pos/csrf-token', {
                            method: 'GET',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin'
                        });
                        if (resp.ok) {
                            const data = await resp.json();
                            if (data.token) {
                                document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', data.token);
                                const hiddenInput = document.querySelector('input[name="_token"]');
                                if (hiddenInput) hiddenInput.value = data.token;
                            }
                        }
                    } catch (e) {}
                },

                async submitForm(event) {
                    if (this.items.length === 0) return;
                    this.submitting = true;

                    if (this.autoSaveTimer) {
                        clearInterval(this.autoSaveTimer);
                    }

                    localStorage.removeItem('pos_draft_invoice');

                    await this.refreshCsrfToken();

                    this.$nextTick(() => {
                        event.target.submit();
                    });
                }
            };
        }
    </script>
</x-pos-layout>
