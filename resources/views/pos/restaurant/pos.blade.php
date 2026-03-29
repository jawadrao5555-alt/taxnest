<x-pos-layout>
<style>
@keyframes cartPop { 0% { transform: scale(1); } 50% { transform: scale(1.15); } 100% { transform: scale(1); } }
@keyframes slideIn { from { opacity: 0; transform: translateX(10px); } to { opacity: 1; transform: translateX(0); } }
.cart-pop { animation: cartPop 0.2s ease; }
.slide-in { animation: slideIn 0.15s ease; }
.prod-card { transition: all 0.15s ease; cursor: pointer; }
.prod-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.12); }
.prod-card:active { transform: scale(0.97); }
.prod-card.ring-active { ring: 2px solid #8b5cf6; box-shadow: 0 0 0 3px rgba(139,92,246,0.25); }
.cat-pill { transition: all 0.12s ease; white-space: nowrap; }
.cat-pill:hover { transform: scale(1.03); }
.cat-pill.active { background: linear-gradient(135deg, #7c3aed, #a855f7); color: white; box-shadow: 0 2px 10px rgba(124,58,237,0.3); }
.cart-item:hover { background: rgba(139,92,246,0.04); }
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
.dark ::-webkit-scrollbar-thumb { background: #4b5563; }
.hide-scrollbar::-webkit-scrollbar { display: none; }
.hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>

<div x-data="restaurantPos()" x-init="init()" class="flex flex-col h-[calc(100vh-64px)] overflow-hidden bg-gray-50 dark:bg-gray-950">

    <div class="flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex-shrink-0 shadow-sm">
        <div class="flex-1 relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" x-ref="searchInput" x-model="searchQuery" @input="onSearchInput()" @keydown.arrow-down.prevent="moveHighlight(1)" @keydown.arrow-up.prevent="moveHighlight(-1)" @keydown.enter.prevent="addHighlightedItem()" @focus="if(searchQuery) showSearchDropdown = true" @click.away="showSearchDropdown = false" placeholder="Search products... (Ctrl+S)" class="w-full pl-9 pr-3 py-2 rounded-xl text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition">
            <div x-show="showSearchDropdown && searchSuggestions.length > 0" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-2xl z-50 max-h-64 overflow-y-auto">
                <template x-for="(s, i) in searchSuggestions" :key="s.id + s.type">
                    <button @click="quickAddItem(s)" class="w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="i === highlightIndex ? 'bg-purple-50 dark:bg-purple-900/20' : ''">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-100 to-purple-50 dark:from-purple-900/30 dark:to-purple-800/20 flex items-center justify-center flex-shrink-0">
                            <span class="text-xs font-bold text-purple-600 dark:text-purple-400" x-text="s.name.charAt(0)"></span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <span class="text-sm font-medium text-gray-900 dark:text-white truncate block" x-text="s.name"></span>
                            <span class="text-[10px] text-gray-400" x-text="s.type === 'service' ? 'Service' : s.category"></span>
                        </div>
                        <span class="text-sm font-bold text-purple-600 dark:text-purple-400" x-text="'Rs. ' + Number(s.price).toLocaleString()"></span>
                    </button>
                </template>
            </div>
        </div>

        <button @click="showTablePicker = true" class="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold border transition" :class="selectedTable ? 'bg-purple-50 dark:bg-purple-900/20 border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            <span x-text="selectedTable ? 'T-' + selectedTable.table_number : 'Table'"></span>
        </button>

        <button @click="showCustomerPicker = !showCustomerPicker" class="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold border transition" :class="selectedCustomer ? 'bg-blue-50 dark:bg-blue-900/20 border-blue-300 dark:border-blue-700 text-blue-700 dark:text-blue-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            <span x-text="selectedCustomer ? selectedCustomer.name.substring(0,10) : 'Customer'"></span>
        </button>

        <select x-model="orderType" class="text-xs rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-2.5 py-2 focus:ring-purple-500">
            <option value="dine_in">Dine In</option>
            <option value="takeaway">Takeaway</option>
            <option value="delivery">Delivery</option>
        </select>

        <div class="w-px h-8 bg-gray-200 dark:bg-gray-700"></div>

        <button @click="newSale()" class="flex items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 hover:bg-green-100 dark:hover:bg-green-900/30 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            <span class="hidden sm:inline">New</span>
        </button>

        <button @click="showHeldOrders = !showHeldOrders" class="relative flex items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 hover:bg-amber-100 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="hidden sm:inline">Held</span>
            <span x-show="heldOrders.length > 0" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] rounded-full flex items-center justify-center font-bold" x-text="heldOrders.length"></span>
        </button>

        <div class="hidden md:flex items-center gap-1.5">
            <button @click="holdOrder()" :disabled="cart.length === 0 || submitting" class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold bg-amber-500 hover:bg-amber-600 text-white disabled:opacity-40 shadow-sm transition">
                <span class="text-[10px] bg-amber-400/30 px-1 rounded">F5</span> Hold
            </button>
            <button @click="showPayModal = true" :disabled="cart.length === 0 || submitting" class="flex items-center gap-1.5 px-5 py-2 rounded-xl text-xs font-bold bg-green-600 hover:bg-green-700 text-white disabled:opacity-40 shadow-lg shadow-green-600/20 transition">
                <span class="text-[10px] bg-green-500/30 px-1 rounded">F8</span> Pay
            </button>
        </div>
    </div>

    <div class="flex flex-1 overflow-hidden">

        <div class="flex-1 flex flex-col overflow-hidden" :class="mobileView === 'menu' ? 'flex' : 'hidden md:flex'">

            <div class="flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-gray-800 overflow-x-auto hide-scrollbar flex-shrink-0">
                <button @click="activeCategory = 'all'; filterProducts()" class="cat-pill px-4 py-1.5 rounded-full text-xs font-semibold border" :class="activeCategory === 'all' ? 'active border-transparent' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800'">All</button>
                @foreach($categories as $cat)
                <button @click="activeCategory = '{{ $cat }}'; filterProducts()" class="cat-pill px-4 py-1.5 rounded-full text-xs font-semibold border" :class="activeCategory === '{{ $cat }}' ? 'active border-transparent' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800'">{{ $cat }}</button>
                @endforeach
                <button @click="activeCategory = 'services'; filterProducts()" class="cat-pill px-4 py-1.5 rounded-full text-xs font-semibold border" :class="activeCategory === 'services' ? 'active border-transparent' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800'">Services</button>
            </div>

            <div x-ref="gridContainer" tabindex="0" @keydown.arrow-right.prevent="moveGridFocus(1)" @keydown.arrow-left.prevent="moveGridFocus(-1)" @keydown.arrow-down.prevent="moveGridFocus(gridCols)" @keydown.arrow-up.prevent="moveGridFocus(-gridCols)" @keydown.enter.prevent="addGridFocusedItem()" class="flex-1 overflow-y-auto p-3 outline-none">
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                    <template x-for="(item, idx) in filteredItems" :key="item.id + '-' + item.type">
                        <div :id="'grid-item-' + idx" @click="addToCart(item); showToast(item.name + ' added', 'success')" class="prod-card bg-white dark:bg-gray-900 rounded-2xl overflow-hidden border border-gray-100 dark:border-gray-800 shadow-sm" :class="gridFocusMode && gridFocusIndex === idx ? 'ring-2 ring-purple-500 shadow-purple-200 dark:shadow-purple-900' : ''">
                            <div class="relative aspect-square bg-gradient-to-br from-gray-100 to-gray-50 dark:from-gray-800 dark:to-gray-900 flex items-center justify-center overflow-hidden">
                                <template x-if="item.image">
                                    <img :src="item.image" :alt="item.name" class="w-full h-full object-cover">
                                </template>
                                <template x-if="!item.image">
                                    <div class="flex flex-col items-center justify-center text-gray-300 dark:text-gray-600">
                                        <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                        <span class="text-[10px] mt-1" x-text="item.name.charAt(0)"></span>
                                    </div>
                                </template>
                                <div class="absolute top-1.5 right-1.5 flex flex-col gap-1">
                                    <template x-if="item.hasRecipe"><span class="px-1.5 py-0.5 bg-blue-500/90 text-white text-[8px] font-bold rounded-md uppercase">Recipe</span></template>
                                    <template x-if="item.is_tax_exempt"><span class="px-1.5 py-0.5 bg-green-500/90 text-white text-[8px] font-bold rounded-md uppercase">No Tax</span></template>
                                </div>
                            </div>
                            <div class="px-2.5 py-2">
                                <p class="text-xs font-semibold text-gray-900 dark:text-white truncate leading-tight" x-text="item.name"></p>
                                <p class="text-sm font-bold text-purple-600 dark:text-purple-400 mt-0.5" x-text="'Rs. ' + Number(item.price).toLocaleString()"></p>
                            </div>
                        </div>
                    </template>
                </div>
                <template x-if="filteredItems.length === 0">
                    <div class="flex flex-col items-center justify-center py-20 text-gray-400">
                        <svg class="w-16 h-16 opacity-20 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        <p class="text-sm font-medium">No products found</p>
                        <p class="text-xs mt-1">Try a different category or search</p>
                    </div>
                </template>
            </div>

            <div class="md:hidden flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800">
                <button @click="mobileView = 'cart'" class="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl bg-purple-600 text-white text-sm font-bold shadow-lg shadow-purple-600/20">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                    <span>Cart</span>
                    <span x-show="cart.length > 0" class="bg-white/20 px-1.5 rounded-full text-xs" x-text="cart.length"></span>
                </button>
            </div>
        </div>

        <div class="w-full md:w-[340px] lg:w-[380px] bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-800 flex flex-col flex-shrink-0 shadow-xl" :class="mobileView === 'cart' ? 'flex' : 'hidden md:flex'">
            <div class="flex items-center gap-2 px-3 py-2.5 border-b border-gray-100 dark:border-gray-800">
                <button @click="mobileView = 'menu'" class="md:hidden p-1.5 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                <span class="text-sm font-bold text-gray-900 dark:text-white flex-1">Current Order</span>
                <span class="text-[10px] bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 px-2 py-0.5 rounded-full font-semibold" x-text="orderType.replace('_', ' ').toUpperCase()"></span>
                <template x-if="selectedTable">
                    <span class="text-[10px] bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded-full font-semibold" x-text="'T-' + selectedTable.table_number"></span>
                </template>
            </div>

            <div class="flex-1 overflow-y-auto">
                <template x-if="cart.length === 0">
                    <div class="flex flex-col items-center justify-center h-full text-gray-400 py-16">
                        <svg class="w-14 h-14 opacity-20 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4"/></svg>
                        <p class="text-sm font-medium">Empty order</p>
                        <p class="text-xs mt-1">Add items from the menu</p>
                    </div>
                </template>
                <template x-for="(item, index) in cart" :key="index">
                    <div class="cart-item px-3 py-2.5 border-b border-gray-50 dark:border-gray-800 slide-in">
                        <div class="flex items-center gap-2">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white truncate" x-text="item.item_name"></p>
                                <p class="text-xs text-gray-400 mt-0.5" x-text="'Rs. ' + Number(item.unit_price).toLocaleString() + ' each'"></p>
                            </div>
                            <div class="flex items-center gap-1.5 bg-gray-100 dark:bg-gray-800 rounded-lg px-1">
                                <button @click="updateQty(index, -1)" class="w-7 h-7 flex items-center justify-center rounded-md hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 transition">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 12H4"/></svg>
                                </button>
                                <input type="number" :value="item.quantity" @change="setQty(index, $event.target.value)" class="w-10 text-center text-sm font-bold bg-transparent text-gray-900 dark:text-white border-0 focus:ring-0 p-0" min="0.01" step="1">
                                <button @click="updateQty(index, 1)" class="w-7 h-7 flex items-center justify-center rounded-md hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 transition">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                                </button>
                            </div>
                            <div class="text-right min-w-[60px]">
                                <p class="text-sm font-bold text-gray-900 dark:text-white" x-text="'Rs. ' + (item.quantity * item.unit_price).toLocaleString()"></p>
                            </div>
                            <button @click="removeFromCart(index)" class="p-1 text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <input type="text" x-model="item.special_notes" placeholder="Special notes..." class="mt-1.5 w-full text-[11px] bg-gray-50 dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-lg px-2 py-1 text-gray-600 dark:text-gray-400 focus:ring-purple-500 placeholder-gray-300">
                    </div>
                </template>
            </div>

            <div class="border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
                <div class="px-3 py-1.5">
                    <textarea x-model="kitchenNotes" rows="1" placeholder="Kitchen notes..." class="w-full text-xs bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-2.5 py-1.5 text-gray-700 dark:text-gray-300 focus:ring-purple-500 resize-none placeholder-gray-300"></textarea>
                </div>
                <div class="px-3 py-2 space-y-1">
                    <div class="flex justify-between text-xs text-gray-500">
                        <span>Subtotal</span>
                        <span x-text="'Rs. ' + Number(subtotal).toLocaleString()"></span>
                    </div>
                    <div x-show="exemptAmount > 0" class="flex justify-between text-xs text-green-600 dark:text-green-400">
                        <span>Tax-Exempt</span>
                        <span x-text="'-Rs. ' + Number(exemptAmount).toLocaleString()"></span>
                    </div>
                    <div class="flex justify-between text-xs text-gray-500">
                        <span x-text="'Tax (' + taxRate + '%)'"></span>
                        <span x-text="'Rs. ' + Number(taxAmount).toLocaleString()"></span>
                    </div>
                    <div class="flex justify-between text-base font-bold text-gray-900 dark:text-white pt-1 border-t border-gray-200 dark:border-gray-700">
                        <span>Total</span>
                        <span x-text="'Rs. ' + Number(totalAmount).toLocaleString()" :class="cartAnimating ? 'cart-pop' : ''"></span>
                    </div>
                </div>
                <div class="px-3 pb-3 space-y-2">
                    <div class="grid grid-cols-3 gap-2">
                        <button @click="voidOrder()" :disabled="cart.length === 0" class="py-2 text-xs font-bold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded-xl border border-red-200 dark:border-red-800 hover:bg-red-100 disabled:opacity-30 transition">Void</button>
                        <button @click="holdOrder()" :disabled="cart.length === 0 || submitting" class="py-2 text-xs font-bold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-800 hover:bg-amber-100 disabled:opacity-30 transition">Hold</button>
                        <button @click="showHeldOrders = !showHeldOrders" class="relative py-2 text-xs font-bold text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 rounded-xl border border-purple-200 dark:border-purple-800 hover:bg-purple-100 transition">
                            Recall
                            <span x-show="heldOrders.length > 0" class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white text-[8px] rounded-full flex items-center justify-center" x-text="heldOrders.length"></span>
                        </button>
                    </div>
                    <button @click="showPayModal = true" :disabled="cart.length === 0 || submitting" class="w-full py-3.5 rounded-xl text-sm font-extrabold text-white bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 disabled:opacity-30 shadow-lg shadow-green-600/25 transition-all transform hover:scale-[1.01]">
                        <span class="flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            PAY Rs. <span x-text="Number(totalAmount).toLocaleString()"></span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div x-show="showPayModal" x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showPayModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" x-transition.scale.90>
            <div class="p-5 text-center border-b border-gray-100 dark:border-gray-800">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Payment</h3>
                <p class="text-3xl font-extrabold text-purple-600 dark:text-purple-400 mt-2" x-text="'Rs. ' + Number(totalAmount).toLocaleString()"></p>
                <p x-show="stockError" class="text-xs text-red-500 mt-2 bg-red-50 dark:bg-red-900/20 p-2 rounded-lg" x-text="stockError"></p>
            </div>
            <div class="p-4 grid grid-cols-2 gap-3">
                <button @click="processPayment('cash')" :disabled="submitting" class="py-4 rounded-xl text-center bg-green-50 dark:bg-green-900/20 border-2 border-green-200 dark:border-green-800 hover:bg-green-100 transition disabled:opacity-50">
                    <svg class="w-8 h-8 mx-auto text-green-600 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span class="text-sm font-bold text-green-700 dark:text-green-400">Cash</span>
                </button>
                <button @click="processPayment('card')" :disabled="submitting" class="py-4 rounded-xl text-center bg-blue-50 dark:bg-blue-900/20 border-2 border-blue-200 dark:border-blue-800 hover:bg-blue-100 transition disabled:opacity-50">
                    <svg class="w-8 h-8 mx-auto text-blue-600 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    <span class="text-sm font-bold text-blue-700 dark:text-blue-400">Card</span>
                </button>
            </div>
            <div class="p-4 pt-0">
                <button @click="showPayModal = false" class="w-full py-2.5 rounded-xl text-sm font-semibold text-gray-500 hover:text-gray-700 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 transition">Cancel</button>
            </div>
        </div>
    </div>

    <div x-show="showTablePicker" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showTablePicker = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[70vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Select Table</h3>
                <button @click="showTablePicker = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-4 max-h-[50vh] overflow-y-auto grid grid-cols-3 gap-2">
                @foreach($tables as $t)
                <button @click="selectTable({ id: {{ $t->id }}, table_number: '{{ $t->table_number }}', seats: {{ $t->seats }} })" class="py-3 px-2 rounded-xl text-center border-2 transition hover:scale-105 {{ $t->status === 'occupied' ? 'border-red-300 bg-red-50 dark:bg-red-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 hover:border-purple-400' }}">
                    <p class="text-sm font-bold {{ $t->status === 'occupied' ? 'text-red-600' : 'text-gray-900 dark:text-white' }}">T-{{ $t->table_number }}</p>
                    <p class="text-[10px] text-gray-400">{{ $t->seats }} seats</p>
                    @if($t->status === 'occupied')<span class="text-[9px] text-red-500 font-medium">Occupied</span>@endif
                </button>
                @endforeach
            </div>
        </div>
    </div>

    <div x-show="showHeldOrders" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showHeldOrders = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Held Orders</h3>
                <button @click="showHeldOrders = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="max-h-[60vh] overflow-y-auto">
                <template x-if="heldOrders.length === 0">
                    <div class="p-8 text-center text-gray-400"><p class="text-sm">No held orders</p></div>
                </template>
                <template x-for="order in heldOrders" :key="order.id">
                    <div class="p-4 border-b border-gray-100 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="order.order_number"></span>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="{'bg-amber-100 text-amber-700': order.status==='held', 'bg-blue-100 text-blue-700': order.status==='preparing', 'bg-green-100 text-green-700': order.status==='ready'}" x-text="order.status"></span>
                        </div>
                        <p class="text-xs text-gray-500 mb-1" x-text="'Rs. ' + Number(order.total_amount).toLocaleString() + ' • ' + order.items.length + ' item(s)'"></p>
                        <template x-if="order.table"><p class="text-[10px] text-purple-600" x-text="'Table: T-' + order.table.table_number"></p></template>
                        <div class="flex gap-2 mt-2">
                            <button @click="recallOrder(order)" class="flex-1 py-2 text-xs font-bold text-purple-600 border border-purple-300 rounded-xl hover:bg-purple-50 transition">Recall</button>
                            <a :href="'/pos/restaurant/orders/' + order.id + '/kitchen-ticket'" target="_blank" class="flex-1 py-2 text-xs font-bold text-center text-orange-600 border border-orange-300 rounded-xl hover:bg-orange-50 transition">Print KOT</a>
                            <button @click="payHeldOrder(order.id)" class="flex-1 py-2 text-xs font-bold text-white bg-green-600 rounded-xl hover:bg-green-700 transition">Pay Now</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <div x-show="showCustomerPicker" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showCustomerPicker = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[80vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Select Customer</h3>
                <button @click="showCustomerPicker = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-3 border-b border-gray-100 dark:border-gray-800">
                <input type="text" x-model="customerSearch" placeholder="Search by name or phone..." class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white text-sm px-3 py-2 focus:ring-purple-500">
            </div>
            <div class="max-h-[45vh] overflow-y-auto">
                <button @click="selectedCustomer = null; showCustomerPicker = false" class="w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition border-b border-gray-100 dark:border-gray-800">
                    <div class="w-9 h-9 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center"><svg class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Walk-in Customer</span>
                </button>
                <template x-for="c in filteredCustomers" :key="c.id">
                    <button @click="selectedCustomer = c; showCustomerPicker = false; showToast('Customer: ' + c.name, 'success')" class="w-full flex items-center gap-3 px-4 py-3 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition border-b border-gray-50 dark:border-gray-800">
                        <div class="w-9 h-9 rounded-full bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center"><span class="text-sm font-bold text-purple-600 dark:text-purple-400" x-text="c.name.charAt(0)"></span></div>
                        <div class="text-left"><p class="text-sm font-medium text-gray-900 dark:text-white" x-text="c.name"></p><p class="text-xs text-gray-400" x-text="c.phone || 'No phone'"></p></div>
                    </button>
                </template>
            </div>
            <div class="p-3 border-t border-gray-200 dark:border-gray-700">
                <div x-show="!showQuickAdd">
                    <button @click="showQuickAdd = true" class="w-full py-2.5 text-sm font-bold text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 rounded-xl hover:bg-purple-100 transition">+ Add New Customer</button>
                </div>
                <div x-show="showQuickAdd" class="space-y-2">
                    <input type="text" x-model="quickCustomerName" placeholder="Customer name *" class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-purple-500">
                    <input type="text" x-model="quickCustomerPhone" placeholder="Phone (optional)" class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-purple-500">
                    <div class="flex gap-2">
                        <button @click="showQuickAdd = false" class="flex-1 py-2 text-xs font-semibold text-gray-500 bg-gray-100 dark:bg-gray-800 rounded-xl">Cancel</button>
                        <button @click="addQuickCustomer()" class="flex-1 py-2 text-xs font-bold text-white bg-purple-600 rounded-xl hover:bg-purple-700">Save</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div x-show="showReceipt" x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" x-transition.scale.90>
            <div class="p-6 text-center">
                <div class="w-16 h-16 mx-auto rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center mb-4">
                    <svg class="w-9 h-9 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <h3 class="text-xl font-extrabold text-gray-900 dark:text-white">Payment Successful</h3>
                <p class="text-sm text-gray-500 mt-1" x-text="lastInvoiceNumber"></p>
                <p class="text-3xl font-extrabold text-green-600 mt-3" x-text="'Rs. ' + Number(lastTotal).toLocaleString()"></p>
                <p class="text-xs text-gray-400 mt-1 capitalize" x-text="lastPaymentMethod + ' payment'"></p>
            </div>
            <div class="p-4 grid grid-cols-2 gap-3">
                <a :href="'/pos/transactions/' + lastTransactionId + '/receipt'" target="_blank" class="py-3 text-center rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold transition shadow-sm">Print</a>
                <button @click="showReceipt = false; newSale()" class="py-3 text-center rounded-xl bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 text-gray-700 dark:text-gray-300 text-sm font-bold transition">New Sale</button>
            </div>
        </div>
    </div>

    <div x-show="toast.show" x-transition.opacity class="fixed top-4 right-4 z-[60] max-w-xs px-4 py-2.5 rounded-xl shadow-2xl text-sm font-medium" :class="toast.type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white'" x-text="toast.message"></div>
</div>

@php
$productsJson = $products->map(function($p) use ($recipeLookup) {
    return [
        'id' => $p->id, 'type' => 'product', 'name' => $p->name,
        'price' => $p->price ?? 0, 'category' => $p->category,
        'is_tax_exempt' => $p->is_tax_exempt ?? false,
        'hasRecipe' => in_array($p->id, $recipeLookup ?? []),
        'image' => $p->image ? asset('storage/products/' . $p->image) : null,
    ];
})->values();
$servicesJson = $services->map(function($s) {
    return [
        'id' => $s->id, 'type' => 'service', 'name' => $s->name,
        'price' => $s->price ?? 0, 'category' => 'Services',
        'is_tax_exempt' => $s->is_tax_exempt ?? false,
        'hasRecipe' => false, 'image' => null,
    ];
})->values();
$selectedTableJson = $selectedTable ? ['id' => $selectedTable->id, 'table_number' => $selectedTable->table_number, 'seats' => $selectedTable->seats] : null;
$customersJson = $customers->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone])->values();
$kitchenSettings = [
    'kds_enabled' => (bool)($company->kds_enabled ?? true),
    'printer_enabled' => (bool)($company->kitchen_printer_enabled ?? false),
    'print_on_hold' => (bool)($company->print_on_hold ?? false),
    'print_on_pay' => (bool)($company->print_on_pay ?? true),
];
@endphp
<script>
function restaurantPos() {
    return {
        allProducts: @json($productsJson),
        allServices: @json($servicesJson),
        allCustomers: @json($customersJson),
        kitchenSettings: @json($kitchenSettings),
        taxRate: {{ $taxRate }},
        filteredItems: [],
        activeCategory: 'all',
        searchQuery: '',
        searchSuggestions: [],
        showSearchDropdown: false,
        showCustomerPicker: false,
        customerSearch: '',
        showQuickAdd: false,
        quickCustomerName: '',
        quickCustomerPhone: '',
        selectedCustomer: null,
        highlightIndex: 0,
        gridFocusMode: false,
        gridFocusIndex: 0,
        gridCols: 4,
        orderType: '{{ $selectedTable ? "dine_in" : "takeaway" }}',
        cart: [],
        kitchenNotes: '',
        selectedTable: @json($selectedTableJson),
        heldOrders: @json($heldOrders),
        showTablePicker: false,
        showPayModal: false,
        showHeldOrders: false,
        showReceipt: false,
        lastInvoiceNumber: '',
        lastTransactionId: null,
        lastTotal: 0,
        lastPaymentMethod: '',
        submitting: false,
        cartAnimating: false,
        stockError: '',
        mobileView: 'menu',
        toast: { show: false, message: '', type: 'success' },

        get filteredCustomers() {
            const q = this.customerSearch.toLowerCase();
            if (!q) return this.allCustomers;
            return this.allCustomers.filter(c => c.name.toLowerCase().includes(q) || (c.phone && c.phone.includes(q)));
        },

        get subtotal() { return this.cart.reduce((s, i) => s + (i.quantity * i.unit_price), 0); },
        get taxableSubtotal() { return this.cart.filter(i => !i.is_tax_exempt).reduce((s, i) => s + (i.quantity * i.unit_price), 0); },
        get taxAmount() { return Math.round(this.taxableSubtotal * this.taxRate / 100); },
        get totalAmount() { return this.subtotal + this.taxAmount; },
        get exemptAmount() { return this.cart.filter(i => i.is_tax_exempt).reduce((s, i) => s + (i.quantity * i.unit_price), 0); },

        init() {
            this.filterProducts();
            this.$watch('activeCategory', () => { this.filterProducts(); this.gridFocusIndex = 0; });
            this.calcGridCols();
            window.addEventListener('resize', () => this.calcGridCols());
            this.restoreCart();
            this.$watch('cart', (val) => { this.saveCart(); }, { deep: true });
            this.$watch('kitchenNotes', () => { this.saveCart(); });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'F5') { e.preventDefault(); this.holdOrder(); }
                if (e.key === 'F8') { e.preventDefault(); if (this.cart.length) this.showPayModal = true; }
                if (e.ctrlKey && e.key === 's') { e.preventDefault(); this.enterSearchMode(); }
                if (e.key === 'Escape') {
                    if (this.showPayModal) { this.showPayModal = false; return; }
                    if (this.showHeldOrders) { this.showHeldOrders = false; return; }
                    if (this.showTablePicker) { this.showTablePicker = false; return; }
                    if (this.showCustomerPicker) { this.showCustomerPicker = false; return; }
                    if (this.gridFocusMode) { this.enterSearchMode(); return; }
                    if (this.activeCategory !== 'all') { this.activeCategory = 'all'; this.searchQuery = ''; this.filterProducts(); return; }
                }
                if (e.key === 'Tab' && !e.shiftKey && !this.gridFocusMode && document.activeElement === this.$refs.searchInput && !this.showSearchDropdown) {
                    e.preventDefault(); this.enterGridMode();
                }
            });
            this.$nextTick(() => { this.$refs.searchInput?.focus(); });
        },

        get storageKey() { return 'rpos_cart_{{ auth("pos")->id() ?? 0 }}_{{ app("currentCompanyId") ?? 0 }}'; },
        get notesKey() { return 'rpos_notes_{{ auth("pos")->id() ?? 0 }}_{{ app("currentCompanyId") ?? 0 }}'; },
        saveCart() {
            try { localStorage.setItem(this.storageKey, JSON.stringify(this.cart)); localStorage.setItem(this.notesKey, this.kitchenNotes); } catch(e) {}
        },
        restoreCart() {
            try {
                const saved = localStorage.getItem(this.storageKey);
                if (saved) { const parsed = JSON.parse(saved); if (Array.isArray(parsed) && parsed.length > 0) { this.cart = parsed; } }
                const notes = localStorage.getItem(this.notesKey);
                if (notes) this.kitchenNotes = notes;
            } catch(e) {}
        },
        clearCartStorage() {
            try { localStorage.removeItem(this.storageKey); localStorage.removeItem(this.notesKey); } catch(e) {}
        },

        calcGridCols() {
            const w = this.$refs.gridContainer?.offsetWidth || 800;
            if (w >= 1200) this.gridCols = 5;
            else if (w >= 900) this.gridCols = 4;
            else if (w >= 640) this.gridCols = 3;
            else this.gridCols = 2;
        },

        enterSearchMode() { this.gridFocusMode = false; this.$refs.searchInput?.focus(); },
        enterGridMode() {
            if (this.filteredItems.length === 0) return;
            this.gridFocusMode = true; this.gridFocusIndex = 0; this.showSearchDropdown = false;
            this.$refs.gridContainer?.focus(); this.scrollGridItemIntoView(0);
        },
        moveGridFocus(delta) {
            if (!this.gridFocusMode) { this.enterGridMode(); return; }
            const newIdx = this.gridFocusIndex + delta;
            if (newIdx >= 0 && newIdx < this.filteredItems.length) { this.gridFocusIndex = newIdx; this.scrollGridItemIntoView(newIdx); }
        },
        scrollGridItemIntoView(idx) { this.$nextTick(() => { document.getElementById('grid-item-' + idx)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }); },
        addGridFocusedItem() {
            if (!this.gridFocusMode || this.filteredItems.length === 0) return;
            const item = this.filteredItems[this.gridFocusIndex];
            if (item) { this.addToCart(item); this.showToast(item.name + ' added', 'success'); }
        },

        onSearchInput() {
            this.filterProducts();
            const q = this.searchQuery.trim().toLowerCase();
            if (q.length > 0) {
                let all = [...this.allProducts, ...this.allServices];
                this.searchSuggestions = all.filter(i => i.name.toLowerCase().includes(q)).slice(0, 12);
                this.highlightIndex = 0; this.showSearchDropdown = true;
            } else { this.searchSuggestions = []; this.showSearchDropdown = false; }
        },
        moveHighlight(dir) {
            if (!this.showSearchDropdown || this.searchSuggestions.length === 0) return;
            this.highlightIndex = Math.max(0, Math.min(this.searchSuggestions.length - 1, this.highlightIndex + dir));
        },
        addHighlightedItem() { if (this.showSearchDropdown && this.searchSuggestions.length > 0) this.quickAddItem(this.searchSuggestions[this.highlightIndex]); },
        quickAddItem(item) {
            this.addToCart(item); this.showToast(item.name + ' added', 'success');
            this.searchQuery = ''; this.searchSuggestions = []; this.showSearchDropdown = false;
            this.filterProducts(); this.$nextTick(() => { this.$refs.searchInput?.focus(); });
        },

        filterProducts() {
            let items = [...this.allProducts, ...this.allServices];
            if (this.activeCategory !== 'all' && this.activeCategory !== 'services') { items = this.allProducts.filter(p => p.category === this.activeCategory); }
            else if (this.activeCategory === 'services') { items = this.allServices; }
            if (this.searchQuery) { const q = this.searchQuery.toLowerCase(); items = items.filter(i => i.name.toLowerCase().includes(q)); }
            this.filteredItems = items;
        },

        addToCart(item) {
            const existing = this.cart.find(c => c.item_id === item.id && c.item_type === item.type);
            if (existing) { existing.quantity++; } else {
                this.cart.push({ item_id: item.id, item_type: item.type, item_name: item.name, quantity: 1, unit_price: parseFloat(item.price), special_notes: '', is_tax_exempt: item.is_tax_exempt || false });
            }
            this.cartAnimating = true; setTimeout(() => this.cartAnimating = false, 300);
        },
        updateQty(index, delta) { this.cart[index].quantity = Math.max(0.01, parseFloat(this.cart[index].quantity) + delta); },
        setQty(index, val) { const v = parseFloat(val); if (v > 0) this.cart[index].quantity = v; },
        removeFromCart(index) { this.cart.splice(index, 1); },
        clearCart() { this.cart = []; this.kitchenNotes = ''; this.selectedTable = null; this.selectedCustomer = null; this.stockError = ''; this.clearCartStorage(); },
        newSale() {
            if (this.cart.length > 0) { if (!confirm('Current order has ' + this.cart.length + ' item(s). Discard and start new sale?')) return; }
            this.clearCart(); this.showToast('New sale started', 'success');
        },
        voidOrder() {
            if (this.cart.length === 0) return;
            if (!confirm('Void current order? All items will be removed.')) return;
            this.clearCart(); this.showToast('Order voided', 'success');
        },
        selectTable(table) { this.selectedTable = table; this.orderType = 'dine_in'; this.showTablePicker = false; },

        async holdOrder() {
            if (this.cart.length === 0 || this.submitting) return;
            this.submitting = true;
            try {
                const res = await fetch('{{ route("pos.restaurant.orders.hold") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ items: this.cart, order_type: this.orderType, table_id: this.selectedTable?.id || null, customer_id: this.selectedCustomer?.id || null, customer_name: this.selectedCustomer?.name || null, customer_phone: this.selectedCustomer?.phone || null, kitchen_notes: this.kitchenNotes }),
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast(data.message, 'success'); this.heldOrders.unshift(data.order); this.clearCart();
                    if (this.kitchenSettings.print_on_hold) { window.open('/pos/restaurant/orders/' + data.order.id + '/kitchen-ticket', '_blank', 'width=350,height=600'); }
                } else { this.showToast(data.message || 'Failed', 'error'); }
            } catch (e) { this.showToast('Network error', 'error'); }
            this.submitting = false;
        },

        async processPayment(method) {
            if (this.cart.length === 0 || this.submitting) return;
            this.submitting = true; this.stockError = '';
            try {
                const holdRes = await fetch('{{ route("pos.restaurant.orders.hold") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ items: this.cart, order_type: this.orderType, table_id: this.selectedTable?.id || null, customer_id: this.selectedCustomer?.id || null, customer_name: this.selectedCustomer?.name || null, customer_phone: this.selectedCustomer?.phone || null, kitchen_notes: this.kitchenNotes }),
                });
                const holdData = await holdRes.json();
                if (!holdData.success) { this.showToast(holdData.message || 'Failed', 'error'); this.submitting = false; return; }
                const savedTotal = this.totalAmount;
                await this.payHeldOrderDirect(holdData.order.id, method, savedTotal);
                this.clearCart();
            } catch (e) { this.showToast('Network error', 'error'); }
            this.showPayModal = false; this.submitting = false;
        },

        async payHeldOrder(orderId) { this.showHeldOrders = false; this.showPayModal = false; this.stockError = ''; this.submitting = true; await this.payHeldOrderDirect(orderId, 'cash', null); this.submitting = false; },

        async payHeldOrderDirect(orderId, method, savedTotal) {
            try {
                const res = await fetch(`/pos/restaurant/orders/${orderId}/pay`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ payment_method: method }) });
                const data = await res.json();
                if (data.success) {
                    this.heldOrders = this.heldOrders.filter(o => o.id !== orderId);
                    this.lastInvoiceNumber = data.invoice_number || ''; this.lastTransactionId = data.transaction_id || null;
                    this.lastTotal = savedTotal || data.total_amount || 0; this.lastPaymentMethod = method; this.showReceipt = true;
                } else { if (data.stock_error) { this.stockError = data.message; this.showPayModal = true; } this.showToast(data.message || 'Payment failed', 'error'); }
            } catch (e) { this.showToast('Payment error', 'error'); }
        },

        recallOrder(order) {
            if (this.cart.length > 0 && !confirm('Current cart has items. Replace with recalled order?')) return;
            this.cart = order.items.map(i => ({ item_id: i.item_id, item_type: i.item_type, item_name: i.item_name, quantity: parseFloat(i.quantity), unit_price: parseFloat(i.unit_price), special_notes: i.special_notes || '', is_tax_exempt: i.is_tax_exempt || false }));
            this.kitchenNotes = order.kitchen_notes || '';
            if (order.table) { this.selectedTable = { id: order.table.id, table_number: order.table.table_number }; this.orderType = 'dine_in'; }
            this.selectedCustomer = order.customer_id ? { id: order.customer_id, name: order.customer_name || 'Customer', phone: order.customer_phone || '' } : null;
            this.heldOrders = this.heldOrders.filter(o => o.id !== order.id); this.showHeldOrders = false; this.showToast('Order recalled', 'success');
        },

        async addQuickCustomer() {
            if (!this.quickCustomerName.trim()) return;
            try {
                const res = await fetch('{{ route("pos.customers.store") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ name: this.quickCustomerName.trim(), phone: this.quickCustomerPhone.trim() || null, type: 'unregistered' }),
                });
                const data = await res.json();
                if (data.customer || data.success) {
                    const cust = data.customer || { id: Date.now(), name: this.quickCustomerName.trim(), phone: this.quickCustomerPhone.trim() };
                    this.allCustomers.push(cust); this.selectedCustomer = cust; this.showQuickAdd = false;
                    this.quickCustomerName = ''; this.quickCustomerPhone = ''; this.showCustomerPicker = false;
                    this.showToast('Customer added: ' + cust.name, 'success');
                } else { this.showToast(data.message || 'Failed', 'error'); }
            } catch (e) { this.showToast('Error adding customer', 'error'); }
        },

        showToast(msg, type) { this.toast = { show: true, message: msg, type }; setTimeout(() => this.toast.show = false, 2500); },
    };
}
</script>
</x-pos-layout>
