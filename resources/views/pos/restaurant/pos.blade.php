<x-pos-layout>
<div x-data="restaurantPos()" x-init="init()" class="flex h-[calc(100vh-64px)] overflow-hidden">
    <div class="flex-1 flex flex-col overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-3">
                    <h1 class="text-lg font-bold text-gray-900 dark:text-white">Restaurant POS</h1>
                    <select x-model="orderType" class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white px-3 py-1.5">
                        <option value="dine_in">Dine In</option>
                        <option value="takeaway">Takeaway</option>
                        <option value="delivery">Delivery</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <div class="relative" @click.away="showSearchDropdown = false">
                        <input type="text" x-ref="searchInput" x-model="searchQuery"
                            @input="onSearchInput()"
                            @focus="onSearchInput()"
                            @keydown.enter.prevent="addHighlightedItem()"
                            @keydown.arrow-down.prevent="moveHighlight(1)"
                            @keydown.arrow-up.prevent="moveHighlight(-1)"
                            @keydown.escape="showSearchDropdown = false; searchQuery = ''"
                            placeholder="Type & Enter to add... (Ctrl+S)"
                            class="pl-8 pr-3 py-1.5 text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white w-64 focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                            autocomplete="off">
                        <svg class="w-4 h-4 absolute left-2.5 top-2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <div x-show="showSearchDropdown && searchSuggestions.length > 0" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl z-50 max-h-64 overflow-y-auto">
                            <template x-for="(item, idx) in searchSuggestions" :key="item.id + '-' + item.type">
                                <button @click="quickAddItem(item)" @mouseenter="highlightIndex = idx"
                                    :class="idx === highlightIndex ? 'bg-purple-50 dark:bg-purple-900/30 border-l-2 border-purple-500' : 'border-l-2 border-transparent'"
                                    class="w-full text-left px-3 py-2 flex items-center justify-between hover:bg-purple-50 dark:hover:bg-purple-900/20 transition-colors">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span x-show="item.hasRecipe" class="text-xs flex-shrink-0">🍳</span>
                                        <div class="min-w-0">
                                            <div class="text-sm font-medium text-gray-900 dark:text-white truncate" x-text="item.name"></div>
                                            <div class="text-[10px] text-gray-400" x-text="item.category || item.type"></div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <span class="text-sm font-bold text-purple-600 dark:text-purple-400" x-text="'Rs. ' + Number(item.price).toLocaleString()"></span>
                                        <kbd class="text-[9px] bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 px-1 rounded" x-show="idx === highlightIndex">Enter</kbd>
                                    </div>
                                </button>
                            </template>
                        </div>
                        <div x-show="showSearchDropdown && searchQuery.length > 0 && searchSuggestions.length === 0" class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl z-50 p-4 text-center text-gray-400 text-sm">
                            No items match "<span x-text="searchQuery"></span>"
                        </div>
                    </div>
                    <button @click="showTablePicker = true" class="px-3 py-1.5 text-sm rounded-lg border border-purple-300 text-purple-700 dark:border-purple-600 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/20">
                        <span x-text="selectedTable ? 'Table ' + selectedTable.table_number : 'Select Table'"></span>
                    </button>
                    <button @click="showHeldOrders = !showHeldOrders" class="relative px-3 py-1.5 text-sm rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">
                        Held Orders
                        <span x-show="heldOrders.length > 0" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center" x-text="heldOrders.length"></span>
                    </button>
                </div>
            </div>
            <div class="flex gap-2 mt-2 overflow-x-auto pb-1">
                <button @click="activeCategory = 'all'" :class="activeCategory === 'all' ? 'bg-purple-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300'" class="px-3 py-1 text-xs rounded-full whitespace-nowrap font-medium transition">All</button>
                @foreach($categories as $cat)
                <button @click="activeCategory = '{{ $cat }}'" :class="activeCategory === '{{ $cat }}' ? 'bg-purple-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300'" class="px-3 py-1 text-xs rounded-full whitespace-nowrap font-medium transition">{{ $cat }}</button>
                @endforeach
                <button @click="activeCategory = 'services'" :class="activeCategory === 'services' ? 'bg-purple-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300'" class="px-3 py-1 text-xs rounded-full whitespace-nowrap font-medium transition">Services</button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-4 bg-gray-50 dark:bg-gray-950">
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                <template x-for="item in filteredItems" :key="item.id + '-' + item.type">
                    <button @click="addToCart(item)" class="group relative bg-white dark:bg-gray-800 rounded-xl p-3 border border-gray-200 dark:border-gray-700 hover:border-purple-400 dark:hover:border-purple-500 hover:shadow-md transition-all text-left">
                        <div class="flex items-start justify-between mb-1">
                            <span x-text="item.name" class="text-sm font-medium text-gray-900 dark:text-white line-clamp-2 leading-tight"></span>
                            <span x-show="item.hasRecipe" class="text-xs flex-shrink-0 ml-1" title="Has Recipe">🍳</span>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mb-1" x-text="item.category || item.type"></div>
                        <div class="text-sm font-bold text-purple-600 dark:text-purple-400" x-text="'Rs. ' + Number(item.price).toLocaleString()"></div>
                        <div x-show="item.is_tax_exempt" class="absolute top-1 right-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 text-[9px] px-1 rounded font-medium">TAX FREE</div>
                    </button>
                </template>
            </div>
            <div x-show="filteredItems.length === 0" class="text-center py-12 text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <p>No items found</p>
            </div>
        </div>
    </div>

    <div class="w-80 lg:w-96 bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-700 flex flex-col overflow-hidden">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h2 class="font-bold text-gray-900 dark:text-white">Current Order</h2>
                <span class="text-xs bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 px-2 py-0.5 rounded-full" x-text="orderType.replace('_', ' ').toUpperCase()"></span>
            </div>
            <template x-if="selectedTable">
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Table: <span class="font-medium text-purple-600" x-text="selectedTable.table_number"></span></div>
            </template>
        </div>

        <div class="flex-1 overflow-y-auto p-3 space-y-2">
            <template x-if="cart.length === 0">
                <div class="text-center py-8 text-gray-400 text-sm">
                    <svg class="w-10 h-10 mx-auto mb-2 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                    Add items to begin
                </div>
            </template>
            <template x-for="(item, index) in cart" :key="index">
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-2.5 border border-gray-100 dark:border-gray-700">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-white truncate" x-text="item.item_name"></div>
                            <div class="text-xs text-gray-500" x-text="'Rs. ' + Number(item.unit_price).toLocaleString() + ' each'"></div>
                        </div>
                        <button @click="removeFromCart(index)" class="text-red-400 hover:text-red-600 p-0.5">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="flex items-center justify-between mt-1.5">
                        <div class="flex items-center gap-1">
                            <button @click="updateQty(index, -1)" class="w-6 h-6 rounded bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 flex items-center justify-center text-sm font-bold hover:bg-gray-300">-</button>
                            <input type="number" :value="item.quantity" @change="setQty(index, $event.target.value)" class="w-12 text-center text-sm border border-gray-200 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-white py-0.5" min="0.01" step="0.01">
                            <button @click="updateQty(index, 1)" class="w-6 h-6 rounded bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 flex items-center justify-center text-sm font-bold hover:bg-gray-300">+</button>
                        </div>
                        <span class="text-sm font-semibold text-gray-900 dark:text-white" x-text="'Rs. ' + Number(item.quantity * item.unit_price).toLocaleString()"></span>
                    </div>
                    <input type="text" x-model="item.special_notes" placeholder="Special notes..." class="mt-1.5 w-full text-xs border border-gray-200 dark:border-gray-600 rounded px-2 py-1 dark:bg-gray-700 dark:text-white placeholder-gray-400">
                </div>
            </template>
        </div>

        <div class="border-t border-gray-200 dark:border-gray-700 p-4 space-y-2 bg-gray-50 dark:bg-gray-800/50">
            <textarea x-model="kitchenNotes" placeholder="Kitchen notes..." rows="2" class="w-full text-xs border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 dark:bg-gray-800 dark:text-white placeholder-gray-400 resize-none"></textarea>
            <div class="flex justify-between text-sm">
                <span class="text-gray-600 dark:text-gray-400">Subtotal</span>
                <span class="font-semibold text-gray-900 dark:text-white" x-text="'Rs. ' + subtotal.toLocaleString()"></span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-gray-600 dark:text-gray-400">Tax</span>
                <span class="text-gray-900 dark:text-white" x-text="'Rs. ' + taxAmount.toLocaleString()"></span>
            </div>
            <div class="flex justify-between text-base font-bold border-t border-gray-200 dark:border-gray-600 pt-2">
                <span class="text-gray-900 dark:text-white">Total</span>
                <span class="text-purple-600 dark:text-purple-400" x-text="'Rs. ' + totalAmount.toLocaleString()"></span>
            </div>
            <div class="grid grid-cols-2 gap-2 pt-2">
                <button @click="holdOrder()" :disabled="cart.length === 0 || submitting" class="py-2.5 px-4 rounded-lg text-sm font-semibold bg-amber-500 hover:bg-amber-600 text-white disabled:opacity-50 transition">
                    Hold (F5)
                </button>
                <button @click="showPayModal = true" :disabled="cart.length === 0 || submitting" class="py-2.5 px-4 rounded-lg text-sm font-semibold bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-700 hover:to-violet-700 text-white disabled:opacity-50 transition">
                    Pay (F8)
                </button>
            </div>
            <button @click="clearCart()" x-show="cart.length > 0" class="w-full py-1.5 text-xs text-red-500 hover:text-red-600 font-medium">Clear Order</button>
        </div>
    </div>

    <div x-show="showTablePicker" x-transition.opacity class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showTablePicker = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] overflow-y-auto">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Select Table</h3>
                <button @click="showTablePicker = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-4">
                <button @click="selectedTable = null; showTablePicker = false" class="w-full mb-3 py-2 px-4 rounded-lg border border-gray-300 dark:border-gray-600 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">No Table (Takeaway/Delivery)</button>
                @php $groupedTables = $tables->groupBy(fn($t) => $t->floor->name); @endphp
                @foreach($groupedTables as $floorName => $floorTables)
                <div class="mb-3">
                    <h4 class="text-xs font-bold uppercase text-gray-500 mb-2">{{ $floorName }}</h4>
                    <div class="grid grid-cols-4 gap-2">
                        @foreach($floorTables as $tbl)
                        <button @click="selectTable({{ json_encode(['id' => $tbl->id, 'table_number' => $tbl->table_number, 'seats' => $tbl->seats, 'status' => $tbl->status]) }})" class="py-3 px-2 rounded-lg border text-center text-sm font-medium transition {{ $tbl->status === 'available' ? 'border-green-300 bg-green-50 dark:bg-green-900/20 dark:border-green-700 text-green-700 dark:text-green-400 hover:bg-green-100' : 'border-red-300 bg-red-50 dark:bg-red-900/20 dark:border-red-700 text-red-700 dark:text-red-400' }}">
                            {{ $tbl->table_number }}
                            <div class="text-[10px] opacity-75">{{ $tbl->seats }} seats</div>
                        </button>
                        @endforeach
                    </div>
                </div>
                @endforeach
                @if($tables->isEmpty())
                <p class="text-center text-gray-400 py-6 text-sm">No tables configured. Go to Table Setup to add tables.</p>
                @endif
            </div>
        </div>
    </div>

    <div x-show="showPayModal" x-transition.opacity class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showPayModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm">
            <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Payment</h3>
                <p class="text-3xl font-black text-purple-600 dark:text-purple-400 mt-2" x-text="'Rs. ' + totalAmount.toLocaleString()"></p>
            </div>
            <div class="p-5 space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <button @click="processPayment('cash')" :disabled="submitting" class="py-4 rounded-xl border-2 border-green-300 hover:border-green-500 hover:bg-green-50 dark:hover:bg-green-900/20 transition text-center">
                        <div class="text-2xl mb-1">💵</div>
                        <div class="text-sm font-semibold text-gray-900 dark:text-white">Cash</div>
                    </button>
                    <button @click="processPayment('debit_card')" :disabled="submitting" class="py-4 rounded-xl border-2 border-blue-300 hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition text-center">
                        <div class="text-2xl mb-1">💳</div>
                        <div class="text-sm font-semibold text-gray-900 dark:text-white">Debit Card</div>
                    </button>
                    <button @click="processPayment('credit_card')" :disabled="submitting" class="py-4 rounded-xl border-2 border-purple-300 hover:border-purple-500 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition text-center">
                        <div class="text-2xl mb-1">💎</div>
                        <div class="text-sm font-semibold text-gray-900 dark:text-white">Credit Card</div>
                    </button>
                    <button @click="processPayment('qr_payment')" :disabled="submitting" class="py-4 rounded-xl border-2 border-orange-300 hover:border-orange-500 hover:bg-orange-50 dark:hover:bg-orange-900/20 transition text-center">
                        <div class="text-2xl mb-1">📱</div>
                        <div class="text-sm font-semibold text-gray-900 dark:text-white">QR / Mobile</div>
                    </button>
                </div>
                <button @click="showPayModal = false" class="w-full py-2 text-sm text-gray-500 hover:text-gray-700">Cancel</button>
            </div>
        </div>
    </div>

    <div x-show="showHeldOrders" x-transition.opacity class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showHeldOrders = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] overflow-y-auto">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Held Orders</h3>
                <button @click="showHeldOrders = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-4 space-y-3">
                <template x-for="order in heldOrders" :key="order.id">
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="order.order_number"></span>
                                <span x-show="order.table" class="ml-2 text-xs text-purple-600" x-text="'Table ' + (order.table?.table_number || '')"></span>
                            </div>
                            <span class="text-sm font-bold text-purple-600" x-text="'Rs. ' + Number(order.total_amount).toLocaleString()"></span>
                        </div>
                        <div class="text-xs text-gray-500 mb-2">
                            <template x-for="item in order.items" :key="item.id">
                                <span class="inline-block mr-2" x-text="item.quantity + 'x ' + item.item_name"></span>
                            </template>
                        </div>
                        <div class="flex gap-2">
                            <button @click="recallOrder(order)" class="flex-1 py-1.5 text-xs rounded-lg bg-purple-600 text-white hover:bg-purple-700 font-medium">Recall</button>
                            <button @click="payHeldOrder(order.id)" class="flex-1 py-1.5 text-xs rounded-lg bg-green-600 text-white hover:bg-green-700 font-medium">Pay Now</button>
                        </div>
                    </div>
                </template>
                <template x-if="heldOrders.length === 0">
                    <p class="text-center text-gray-400 py-6 text-sm">No held orders</p>
                </template>
            </div>
        </div>
    </div>

    <div x-show="toast.show" x-transition class="fixed bottom-4 right-4 z-[60] max-w-sm">
        <div :class="toast.type === 'success' ? 'bg-green-600' : 'bg-red-600'" class="text-white px-4 py-3 rounded-lg shadow-lg text-sm" x-text="toast.message"></div>
    </div>
</div>

@php
$productsJson = $products->map(function($p) use ($recipeLookup) {
    return ['id' => $p->id, 'type' => 'product', 'name' => $p->name, 'price' => $p->price ?? 0, 'category' => $p->category, 'is_tax_exempt' => $p->is_tax_exempt ?? false, 'hasRecipe' => in_array($p->id, $recipeLookup ?? [])];
})->values();
$servicesJson = $services->map(function($s) {
    return ['id' => $s->id, 'type' => 'service', 'name' => $s->name, 'price' => $s->price ?? 0, 'category' => 'Services', 'is_tax_exempt' => $s->is_tax_exempt ?? false, 'hasRecipe' => false];
})->values();
$selectedTableJson = $selectedTable ? ['id' => $selectedTable->id, 'table_number' => $selectedTable->table_number, 'seats' => $selectedTable->seats] : null;
@endphp
<script>
function restaurantPos() {
    return {
        allProducts: @json($productsJson),
        allServices: @json($servicesJson),
        filteredItems: [],
        activeCategory: 'all',
        searchQuery: '',
        searchSuggestions: [],
        showSearchDropdown: false,
        highlightIndex: 0,
        orderType: '{{ $selectedTable ? "dine_in" : "takeaway" }}',
        cart: [],
        kitchenNotes: '',
        selectedTable: @json($selectedTableJson),
        heldOrders: @json($heldOrders),
        showTablePicker: false,
        showPayModal: false,
        showHeldOrders: false,
        submitting: false,
        toast: { show: false, message: '', type: 'success' },

        get subtotal() { return this.cart.reduce((s, i) => s + (i.quantity * i.unit_price), 0); },
        get taxAmount() { return Math.round(this.subtotal * 0.16); },
        get totalAmount() { return this.subtotal + this.taxAmount; },

        init() {
            this.filterProducts();
            this.$watch('activeCategory', () => this.filterProducts());
            document.addEventListener('keydown', (e) => {
                if (e.key === 'F5') { e.preventDefault(); this.holdOrder(); }
                if (e.key === 'F8') { e.preventDefault(); if (this.cart.length) this.showPayModal = true; }
                if (e.ctrlKey && e.key === 's') { e.preventDefault(); this.$refs.searchInput?.focus(); }
            });
            this.$nextTick(() => { this.$refs.searchInput?.focus(); });
        },

        onSearchInput() {
            this.filterProducts();
            const q = this.searchQuery.trim().toLowerCase();
            if (q.length > 0) {
                let all = [...this.allProducts, ...this.allServices];
                if (this.activeCategory !== 'all' && this.activeCategory !== 'services') {
                    all = this.allProducts.filter(p => p.category === this.activeCategory);
                } else if (this.activeCategory === 'services') {
                    all = this.allServices;
                }
                this.searchSuggestions = all.filter(i => i.name.toLowerCase().includes(q)).slice(0, 10);
                this.highlightIndex = 0;
                this.showSearchDropdown = true;
            } else {
                this.searchSuggestions = [];
                this.showSearchDropdown = false;
            }
        },

        moveHighlight(dir) {
            if (!this.showSearchDropdown || this.searchSuggestions.length === 0) return;
            this.highlightIndex = Math.max(0, Math.min(this.searchSuggestions.length - 1, this.highlightIndex + dir));
        },

        addHighlightedItem() {
            if (this.showSearchDropdown && this.searchSuggestions.length > 0) {
                this.quickAddItem(this.searchSuggestions[this.highlightIndex]);
            }
        },

        quickAddItem(item) {
            this.addToCart(item);
            this.showToast(item.name + ' added', 'success');
            this.searchQuery = '';
            this.searchSuggestions = [];
            this.showSearchDropdown = false;
            this.filterProducts();
            this.$nextTick(() => { this.$refs.searchInput?.focus(); });
        },

        filterProducts() {
            let items = [...this.allProducts, ...this.allServices];
            if (this.activeCategory !== 'all' && this.activeCategory !== 'services') {
                items = this.allProducts.filter(p => p.category === this.activeCategory);
            } else if (this.activeCategory === 'services') {
                items = this.allServices;
            }
            if (this.searchQuery) {
                const q = this.searchQuery.toLowerCase();
                items = items.filter(i => i.name.toLowerCase().includes(q));
            }
            this.filteredItems = items;
        },

        addToCart(item) {
            const existing = this.cart.find(c => c.item_id === item.id && c.item_type === item.type);
            if (existing) {
                existing.quantity++;
            } else {
                this.cart.push({
                    item_id: item.id,
                    item_type: item.type,
                    item_name: item.name,
                    quantity: 1,
                    unit_price: parseFloat(item.price),
                    special_notes: '',
                    is_tax_exempt: item.is_tax_exempt || false,
                });
            }
        },

        updateQty(index, delta) {
            this.cart[index].quantity = Math.max(0.01, parseFloat(this.cart[index].quantity) + delta);
        },

        setQty(index, val) {
            const v = parseFloat(val);
            if (v > 0) this.cart[index].quantity = v;
        },

        removeFromCart(index) { this.cart.splice(index, 1); },

        clearCart() { this.cart = []; this.kitchenNotes = ''; this.selectedTable = null; },

        selectTable(table) {
            this.selectedTable = table;
            this.orderType = 'dine_in';
            this.showTablePicker = false;
        },

        async holdOrder() {
            if (this.cart.length === 0 || this.submitting) return;
            this.submitting = true;
            try {
                const res = await fetch('{{ route("pos.restaurant.orders.hold") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({
                        items: this.cart,
                        order_type: this.orderType,
                        table_id: this.selectedTable?.id || null,
                        kitchen_notes: this.kitchenNotes,
                    }),
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast(data.message, 'success');
                    this.heldOrders.unshift(data.order);
                    this.clearCart();
                } else {
                    this.showToast(data.message || 'Failed', 'error');
                }
            } catch (e) { this.showToast('Network error', 'error'); }
            this.submitting = false;
        },

        async processPayment(method) {
            if (this.cart.length === 0 || this.submitting) return;
            this.submitting = true;
            await this.holdOrder();
            if (this.heldOrders.length > 0) {
                const lastOrder = this.heldOrders[0];
                await this.payHeldOrderDirect(lastOrder.id, method);
            }
            this.showPayModal = false;
            this.submitting = false;
        },

        async payHeldOrder(orderId) {
            this.showHeldOrders = false;
            this.showPayModal = false;
            await this.payHeldOrderDirect(orderId, 'cash');
        },

        async payHeldOrderDirect(orderId, method) {
            try {
                const res = await fetch(`/pos/restaurant/orders/${orderId}/pay`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ payment_method: method }),
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast(data.message, 'success');
                    this.heldOrders = this.heldOrders.filter(o => o.id !== orderId);
                } else {
                    this.showToast(data.message || 'Payment failed', 'error');
                }
            } catch (e) { this.showToast('Payment error', 'error'); }
        },

        recallOrder(order) {
            this.cart = order.items.map(i => ({
                item_id: i.item_id,
                item_type: i.item_type,
                item_name: i.item_name,
                quantity: parseFloat(i.quantity),
                unit_price: parseFloat(i.unit_price),
                special_notes: i.special_notes || '',
                is_tax_exempt: i.is_tax_exempt || false,
            }));
            this.kitchenNotes = order.kitchen_notes || '';
            if (order.table) {
                this.selectedTable = { id: order.table.id, table_number: order.table.table_number };
                this.orderType = 'dine_in';
            }
            this.showHeldOrders = false;
        },

        showToast(msg, type) {
            this.toast = { show: true, message: msg, type };
            setTimeout(() => this.toast.show = false, 3000);
        },
    };
}
</script>
</x-pos-layout>
