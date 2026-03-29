<x-pos-layout>
<style>
@keyframes cartPop { 0% { transform: scale(1); } 50% { transform: scale(1.15); } 100% { transform: scale(1); } }
@keyframes slideIn { from { opacity: 0; transform: translateX(-10px); } to { opacity: 1; transform: translateX(0); } }
.cart-pop { animation: cartPop 0.2s ease; }
.slide-in { animation: slideIn 0.15s ease; }
.cat-tile { transition: all 0.15s ease; cursor: pointer; }
.cat-tile:hover { transform: scale(1.03); filter: brightness(1.1); }
.cat-tile:active { transform: scale(0.97); }
.prod-tile { transition: all 0.12s ease; cursor: pointer; border: 2px solid transparent; }
.prod-tile:hover { border-color: rgba(139,92,246,0.5); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.prod-tile:active { transform: scale(0.97); }
.prod-tile.ring-active { border-color: #8b5cf6; box-shadow: 0 0 0 2px rgba(139,92,246,0.3); }
.cart-item:hover { background: rgba(139,92,246,0.05); }
.action-btn { transition: all 0.1s ease; }
.action-btn:hover { transform: translateY(-1px); }
.action-btn:active { transform: scale(0.96); }
::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
.dark ::-webkit-scrollbar-thumb { background: #4b5563; }
</style>

<div x-data="restaurantPos()" x-init="init()" class="flex flex-col h-[calc(100vh-64px)] overflow-hidden bg-gray-100 dark:bg-gray-950">

    <div class="flex items-center gap-1 px-2 py-1.5 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 overflow-x-auto flex-shrink-0">
        <button @click="showSearchDropdown = true; $nextTick(() => $refs.searchInput?.focus())" class="action-btn flex flex-col items-center px-2.5 py-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 min-w-[52px]">
            <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <span class="text-[9px] text-gray-500 dark:text-gray-400 mt-0.5">Search</span>
        </button>
        <button @click="showCustomerPicker = !showCustomerPicker" class="action-btn flex flex-col items-center px-2.5 py-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 min-w-[52px]" :class="selectedCustomer ? 'bg-blue-50 dark:bg-blue-900/20' : ''">
            <svg class="w-4 h-4" :class="selectedCustomer ? 'text-blue-600' : 'text-gray-600 dark:text-gray-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            <span class="text-[9px] mt-0.5" :class="selectedCustomer ? 'text-blue-600 font-bold' : 'text-gray-500 dark:text-gray-400'" x-text="selectedCustomer ? selectedCustomer.name.substring(0,8) : 'Customer'"></span>
        </button>
        <button @click="showTablePicker = true" class="action-btn flex flex-col items-center px-2.5 py-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 min-w-[52px]" :class="selectedTable ? 'bg-purple-50 dark:bg-purple-900/20' : ''">
            <svg class="w-4 h-4" :class="selectedTable ? 'text-purple-600' : 'text-gray-600 dark:text-gray-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            <span class="text-[9px] mt-0.5" :class="selectedTable ? 'text-purple-600 font-bold' : 'text-gray-500 dark:text-gray-400'" x-text="selectedTable ? 'T-' + selectedTable.table_number : 'Table'"></span>
        </button>
        <div class="w-px h-8 bg-gray-200 dark:bg-gray-700 mx-1"></div>
        <button @click="newSale()" class="action-btn flex flex-col items-center px-2.5 py-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 min-w-[52px]">
            <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            <span class="text-[9px] text-green-600 dark:text-green-400 font-bold mt-0.5">New Sale</span>
        </button>
        <button @click="showHeldOrders = !showHeldOrders" class="action-btn relative flex flex-col items-center px-2.5 py-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 min-w-[52px]">
            <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-[9px] text-gray-500 dark:text-gray-400 mt-0.5">Held</span>
            <span x-show="heldOrders.length > 0" class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-[8px] rounded-full flex items-center justify-center font-bold" x-text="heldOrders.length"></span>
        </button>
        <div class="w-px h-8 bg-gray-200 dark:bg-gray-700 mx-1"></div>
        <select x-model="orderType" class="action-btn text-xs rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-2 py-1.5 focus:ring-purple-500">
            <option value="dine_in">Dine In</option>
            <option value="takeaway">Takeaway</option>
            <option value="delivery">Delivery</option>
        </select>

        <div class="flex-1"></div>

        <div class="flex items-center gap-1">
            <button @click="holdOrder()" :disabled="cart.length === 0 || submitting" class="action-btn flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-amber-500 hover:bg-amber-600 text-white disabled:opacity-40 shadow-sm min-w-[70px] justify-center">
                <span>F5</span>
                <span class="hidden sm:inline">Hold</span>
            </button>
            <button @click="showPayModal = true" :disabled="cart.length === 0 || submitting" class="action-btn flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-bold bg-green-600 hover:bg-green-700 text-white disabled:opacity-40 shadow-sm min-w-[90px] justify-center">
                <span>F8</span>
                <span>Payment</span>
            </button>
        </div>
    </div>

    <div class="flex flex-1 overflow-hidden">

        <div class="w-full md:w-[280px] lg:w-[320px] bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-700 flex flex-col flex-shrink-0" :class="mobileView === 'cart' ? 'flex' : 'hidden md:flex'">
            <div class="flex items-center gap-1 px-2 py-1.5 border-b border-gray-100 dark:border-gray-800">
                <button @click="mobileView = 'menu'" class="md:hidden p-1 text-purple-600 hover:bg-purple-50 rounded">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <span class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider flex-1">Current Order</span>
                <span class="text-[10px] bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 px-1.5 py-0.5 rounded font-medium" x-text="orderType.replace('_', ' ').toUpperCase()"></span>
                <template x-if="selectedTable">
                    <span class="text-[10px] bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 rounded font-medium" x-text="'T-' + selectedTable.table_number"></span>
                </template>
                <template x-if="selectedCustomer">
                    <span class="text-[10px] bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 px-1.5 py-0.5 rounded font-medium" x-text="selectedCustomer.name.substring(0,10)"></span>
                </template>
            </div>

            <div class="flex-1 overflow-y-auto">
                <template x-if="cart.length === 0">
                    <div class="flex flex-col items-center justify-center h-full text-gray-400 py-12">
                        <svg class="w-10 h-10 opacity-30 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4"/></svg>
                        <p class="text-xs">Empty order</p>
                    </div>
                </template>
                <template x-for="(item, index) in cart" :key="index">
                    <div class="cart-item flex items-start gap-2 px-3 py-2 border-b border-gray-50 dark:border-gray-800 slide-in">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1">
                                <span class="text-purple-600 dark:text-purple-400 font-bold text-xs" x-text="'+ '"></span>
                                <span class="text-sm font-medium text-gray-900 dark:text-white truncate" x-text="item.item_name"></span>
                            </div>
                            <div class="flex items-center gap-2 mt-1">
                                <div class="flex items-center bg-gray-100 dark:bg-gray-800 rounded overflow-hidden">
                                    <button @click="updateQty(index, -1)" class="px-1.5 py-0.5 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 text-xs font-bold">−</button>
                                    <input type="number" :value="item.quantity" @change="setQty(index, $event.target.value)" class="w-8 text-center text-xs border-0 bg-transparent text-gray-900 dark:text-white p-0 focus:ring-0" min="0.01" step="0.01">
                                    <button @click="updateQty(index, 1)" class="px-1.5 py-0.5 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 text-xs font-bold">+</button>
                                </div>
                                <span class="text-[10px] text-gray-400" x-text="'@ ' + Number(item.unit_price).toLocaleString()"></span>
                            </div>
                            <input type="text" x-model="item.special_notes" placeholder="Note..." class="mt-1 w-full text-[10px] border-0 border-b border-dashed border-gray-200 dark:border-gray-700 bg-transparent text-gray-500 dark:text-gray-400 px-0 py-0.5 focus:ring-0 focus:border-purple-400 placeholder-gray-300">
                        </div>
                        <div class="flex flex-col items-end gap-1 flex-shrink-0">
                            <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="Number(item.quantity * item.unit_price).toLocaleString()"></span>
                            <button @click="removeFromCart(index)" class="text-red-400 hover:text-red-600 transition-colors p-0.5">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <div class="border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 px-3 py-2 flex-shrink-0">
                <textarea x-model="kitchenNotes" placeholder="Kitchen notes..." rows="1" class="w-full text-[10px] border border-gray-200 dark:border-gray-700 rounded px-2 py-1 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 resize-none focus:ring-purple-500 mb-1.5"></textarea>
                <div class="space-y-0.5 text-xs">
                    <div class="flex justify-between text-gray-500 dark:text-gray-400">
                        <span>Subtotal</span>
                        <span x-text="Number(subtotal).toLocaleString()"></span>
                    </div>
                    <div x-show="exemptAmount > 0" class="flex justify-between text-green-600 dark:text-green-400">
                        <span>Tax Exempt</span>
                        <span x-text="'-' + Number(exemptAmount).toLocaleString()"></span>
                    </div>
                    <div class="flex justify-between text-gray-500 dark:text-gray-400">
                        <span x-text="'Tax (' + taxRate + '%)'">Tax</span>
                        <span x-text="Number(taxAmount).toLocaleString()"></span>
                    </div>
                    <div class="flex justify-between text-lg font-black text-gray-900 dark:text-white pt-1 border-t border-gray-200 dark:border-gray-600">
                        <span>Total</span>
                        <span x-text="'Rs. ' + Number(totalAmount).toLocaleString()"></span>
                    </div>
                </div>
            </div>

            <div class="flex border-t border-gray-200 dark:border-gray-700 flex-shrink-0">
                <button @click="voidOrder()" class="flex-1 py-2 text-[10px] font-bold text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 border-r border-gray-200 dark:border-gray-700 transition uppercase tracking-wider">Void</button>
                <button @click="holdOrder()" :disabled="cart.length === 0" class="flex-1 py-2 text-[10px] font-bold text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/20 border-r border-gray-200 dark:border-gray-700 transition uppercase tracking-wider disabled:opacity-40">Hold</button>
                <button @click="showHeldOrders = !showHeldOrders" class="flex-1 py-2 text-[10px] font-bold text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition uppercase tracking-wider relative">
                    Recall
                    <span x-show="heldOrders.length > 0" class="absolute top-0.5 right-2 w-3.5 h-3.5 bg-red-500 text-white text-[7px] rounded-full flex items-center justify-center" x-text="heldOrders.length"></span>
                </button>
            </div>
        </div>

        <div class="flex-1 flex flex-col overflow-hidden" :class="mobileView === 'cart' ? 'hidden md:flex' : 'flex'">

            <div class="px-2 py-1.5 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                <div class="flex items-center gap-2">
                    <div class="relative flex-1" @click.away="showSearchDropdown = false">
                        <input type="text" x-ref="searchInput" x-model="searchQuery"
                            @input="onSearchInput()"
                            @focus="onSearchInput()"
                            @keydown.enter.prevent="addHighlightedItem()"
                            @keydown.arrow-down.prevent="moveHighlight(1)"
                            @keydown.arrow-up.prevent="moveHighlight(-1)"
                            @keydown.escape="showSearchDropdown = false; searchQuery = ''"
                            placeholder="Search products by name, code or barcode..."
                            class="w-full pl-9 pr-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-purple-500 focus:bg-white dark:focus:bg-gray-700"
                            autocomplete="off">
                        <svg class="w-4 h-4 absolute left-3 top-2.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>

                        <div x-show="showSearchDropdown && searchSuggestions.length > 0" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-2xl z-50 max-h-72 overflow-y-auto">
                            <template x-for="(item, idx) in searchSuggestions" :key="item.id + '-' + item.type">
                                <button @click="quickAddItem(item)" @mouseenter="highlightIndex = idx"
                                    :class="idx === highlightIndex ? 'bg-purple-50 dark:bg-purple-900/30' : ''"
                                    class="w-full text-left px-3 py-2.5 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors border-b border-gray-50 dark:border-gray-700/50">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <img :src="item.image || '/img/food-placeholder.svg'" class="w-10 h-10 rounded-lg object-cover flex-shrink-0 bg-gray-100 dark:bg-gray-700" onerror="this.src='/img/food-placeholder.svg'">
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold text-gray-900 dark:text-white truncate" x-text="item.name"></div>
                                            <div class="text-[10px] text-gray-400" x-text="item.category || item.type"></div>
                                        </div>
                                    </div>
                                    <span class="text-sm font-bold text-gray-900 dark:text-white flex-shrink-0 ml-3" x-text="Number(item.price).toLocaleString()"></span>
                                </button>
                            </template>
                        </div>
                        <div x-show="showSearchDropdown && searchQuery.length > 0 && searchSuggestions.length === 0" class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl z-50 p-6 text-center text-gray-400 text-sm">
                            No results for "<span x-text="searchQuery"></span>"
                        </div>
                    </div>
                    <button @click="mobileView = 'cart'" class="md:hidden relative p-2 rounded-lg bg-purple-600 text-white shadow-md">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                        <span x-show="cart.length > 0" class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white text-[8px] rounded-full flex items-center justify-center font-bold" x-text="cart.reduce((s, i) => s + i.quantity, 0)"></span>
                    </button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-2 sm:p-3" x-ref="gridContainer"
                @keydown.arrow-right.prevent="moveGridFocus(1)"
                @keydown.arrow-left.prevent="moveGridFocus(-1)"
                @keydown.arrow-down.prevent="moveGridFocus(gridCols)"
                @keydown.arrow-up.prevent="moveGridFocus(-gridCols)"
                @keydown.enter.prevent="addGridFocusedItem()"
                @keydown.tab.prevent="moveGridFocus(1)"
                tabindex="0">

                <div x-show="activeCategory === 'all' && !searchQuery" class="mb-3">
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 sm:gap-3">
                        @php
                        $catColors = ['#3b82f6','#8b5cf6','#ef4444','#f59e0b','#10b981','#ec4899','#6366f1','#14b8a6','#f97316','#06b6d4'];
                        @endphp
                        @foreach($categories as $idx => $cat)
                        <button @click="activeCategory = '{{ $cat }}'"
                            class="cat-tile rounded-xl overflow-hidden relative h-20 sm:h-24 flex items-end p-3"
                            style="background: {{ $catColors[$idx % count($catColors)] }};">
                            <span class="text-white font-bold text-sm sm:text-base drop-shadow-lg relative z-10">{{ $cat }}</span>
                            <div class="absolute inset-0 bg-gradient-to-t from-black/30 to-transparent"></div>
                        </button>
                        @endforeach
                        <button @click="activeCategory = 'services'"
                            class="cat-tile rounded-xl overflow-hidden relative h-20 sm:h-24 flex items-end p-3"
                            style="background: #64748b;">
                            <span class="text-white font-bold text-sm sm:text-base drop-shadow-lg relative z-10">Services</span>
                            <div class="absolute inset-0 bg-gradient-to-t from-black/30 to-transparent"></div>
                        </button>
                    </div>
                </div>

                <div x-show="activeCategory !== 'all' || searchQuery" class="mb-2 flex items-center gap-2">
                    <button @click="activeCategory = 'all'; searchQuery = ''; filterProducts()" x-show="activeCategory !== 'all'"
                        class="flex items-center gap-1 text-xs text-purple-600 dark:text-purple-400 hover:text-purple-700 font-medium bg-purple-50 dark:bg-purple-900/20 px-2.5 py-1 rounded-full transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        All Categories
                    </button>
                    <span x-show="activeCategory !== 'all'" class="text-sm font-bold text-gray-700 dark:text-gray-300" x-text="activeCategory === 'services' ? 'Services' : activeCategory"></span>
                    <span class="text-xs text-gray-400 ml-auto" x-text="filteredItems.length + ' items'"></span>
                </div>

                <div x-show="activeCategory !== 'all' || searchQuery" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-2 sm:gap-3">
                    <template x-for="(item, gIdx) in filteredItems" :key="item.id + '-' + item.type">
                        <button @click="quickAddItem(item)"
                            :id="'grid-item-' + gIdx"
                            :class="gridFocusMode && gIdx === gridFocusIndex ? 'ring-active' : ''"
                            class="prod-tile group bg-white dark:bg-gray-800 rounded-xl overflow-hidden text-center">
                            <div class="aspect-square w-full bg-gray-100 dark:bg-gray-700 relative overflow-hidden">
                                <img :src="item.image || '/img/food-placeholder.svg'" class="w-full h-full object-cover" onerror="this.src='/img/food-placeholder.svg'" loading="lazy">
                                <div x-show="item.hasRecipe" class="absolute top-1 left-1 bg-amber-500/90 text-white text-[8px] px-1 py-0.5 rounded font-bold">RECIPE</div>
                                <div x-show="item.is_tax_exempt" class="absolute top-1 right-1 bg-green-500/90 text-white text-[8px] px-1 py-0.5 rounded font-bold">NO TAX</div>
                            </div>
                            <div class="px-1.5 py-2">
                                <div class="text-xs font-semibold text-gray-800 dark:text-gray-200 line-clamp-2 leading-tight min-h-[28px]" x-text="item.name"></div>
                                <div class="text-sm font-bold text-gray-900 dark:text-white mt-1" x-text="Number(item.price).toLocaleString()"></div>
                            </div>
                        </button>
                    </template>
                </div>

                <div x-show="(activeCategory !== 'all' || searchQuery) && filteredItems.length === 0" class="text-center py-16 text-gray-400">
                    <svg class="w-12 h-12 mx-auto mb-3 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <p class="text-sm font-medium">No items found</p>
                </div>
            </div>
        </div>
    </div>

    <div x-show="showTablePicker" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showTablePicker = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] overflow-y-auto" x-transition.scale.90>
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
                        <button @click="selectTable({{ json_encode(['id' => $tbl->id, 'table_number' => $tbl->table_number, 'seats' => $tbl->seats, 'status' => $tbl->status]) }})" class="py-3 px-2 rounded-xl border-2 text-center text-sm font-medium transition-all {{ $tbl->status === 'available' ? 'border-green-300 bg-green-50 dark:bg-green-900/20 dark:border-green-700 text-green-700 dark:text-green-400 hover:bg-green-100 hover:shadow-md' : 'border-red-300 bg-red-50 dark:bg-red-900/20 dark:border-red-700 text-red-700 dark:text-red-400' }}">
                            {{ $tbl->table_number }}
                            <div class="text-[10px] opacity-75">{{ $tbl->seats }} seats</div>
                        </button>
                        @endforeach
                    </div>
                </div>
                @endforeach
                @if($tables->isEmpty())
                <p class="text-center text-gray-400 py-6 text-sm">No tables configured.</p>
                @endif
            </div>
        </div>
    </div>

    <div x-show="showPayModal" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showPayModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm" x-transition.scale.90>
            <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Payment</h3>
                <div class="mt-3 text-center">
                    <div class="text-3xl font-black text-gray-900 dark:text-white" x-text="'Rs. ' + Number(totalAmount).toLocaleString()"></div>
                    <div class="text-xs text-gray-400 mt-1" x-text="cart.length + ' item(s) | ' + orderType.replace('_', ' ')"></div>
                </div>
            </div>
            <div x-show="stockError" class="mx-5 mt-3 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-xs text-red-700 dark:text-red-400" x-text="stockError"></div>
            <div class="p-5 grid grid-cols-2 gap-3">
                <button @click="processPayment('cash')" :disabled="submitting" class="py-4 rounded-xl bg-green-600 hover:bg-green-700 text-white font-bold text-sm transition disabled:opacity-50 shadow-lg">
                    <svg class="w-6 h-6 mx-auto mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    Cash
                </button>
                <button @click="processPayment('card')" :disabled="submitting" class="py-4 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm transition disabled:opacity-50 shadow-lg">
                    <svg class="w-6 h-6 mx-auto mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    Card
                </button>
            </div>
            <div class="px-5 pb-5">
                <button @click="showPayModal = false" class="w-full py-2 text-sm text-gray-500 hover:text-gray-700 font-medium">Cancel</button>
            </div>
        </div>
    </div>

    <div x-show="showHeldOrders" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showHeldOrders = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[80vh] overflow-y-auto" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between sticky top-0 bg-white dark:bg-gray-900 z-10">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Held Orders <span class="text-sm font-normal text-gray-400" x-text="'(' + heldOrders.length + ')'"></span></h3>
                <button @click="showHeldOrders = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <template x-if="heldOrders.length === 0">
                <div class="p-8 text-center text-gray-400 text-sm">No held orders</div>
            </template>
            <template x-for="order in heldOrders" :key="order.id">
                <div class="p-4 border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="'#' + order.id"></span>
                            <span x-show="order.table" class="text-xs text-blue-600 ml-2" x-text="'Table ' + (order.table?.table_number || '')"></span>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                            :class="order.status === 'held' ? 'bg-amber-100 text-amber-700' : order.status === 'preparing' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700'"
                            x-text="order.status"></span>
                    </div>
                    <div class="text-xs text-gray-500 mb-2" x-text="order.items?.length + ' items | ' + order.order_type?.replace('_', ' ')"></div>
                    <div class="flex gap-2">
                        <button @click="recallOrder(order)" class="flex-1 py-1.5 text-xs font-semibold text-purple-600 border border-purple-300 rounded-lg hover:bg-purple-50 transition">Recall</button>
                        <button @click="payHeldOrder(order.id)" class="flex-1 py-1.5 text-xs font-semibold text-white bg-green-600 rounded-lg hover:bg-green-700 transition">Pay Now</button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <div x-show="showCustomerPicker" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showCustomerPicker = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[80vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Select Customer</h3>
                <button @click="showCustomerPicker = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-3 border-b border-gray-100 dark:border-gray-800">
                <input type="text" x-model="customerSearch" placeholder="Search by name or phone..." class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white text-sm px-3 py-2 focus:ring-purple-500">
            </div>
            <div class="overflow-y-auto max-h-[50vh]">
                <button @click="selectedCustomer = null; showCustomerPicker = false" class="w-full text-left px-4 py-3 text-sm text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-800 border-b border-gray-50 dark:border-gray-800">
                    Walk-in Customer (No selection)
                </button>
                <template x-for="cust in filteredCustomers" :key="cust.id">
                    <button @click="selectedCustomer = cust; showCustomerPicker = false" class="w-full text-left px-4 py-3 hover:bg-purple-50 dark:hover:bg-purple-900/20 border-b border-gray-50 dark:border-gray-800 transition flex items-center justify-between">
                        <div>
                            <div class="text-sm font-semibold text-gray-900 dark:text-white" x-text="cust.name"></div>
                            <div class="text-[11px] text-gray-400" x-text="cust.phone || 'No phone'"></div>
                        </div>
                        <svg x-show="selectedCustomer?.id === cust.id" class="w-5 h-5 text-purple-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </button>
                </template>
                <div x-show="filteredCustomers.length === 0 && customerSearch.length > 0" class="p-6 text-center text-gray-400 text-sm">No customers found</div>
            </div>
            <div class="p-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                <div x-show="!showQuickAdd">
                    <button @click="showQuickAdd = true" class="w-full py-2 text-sm font-semibold text-purple-600 hover:text-purple-800 transition">+ Add New Customer</button>
                </div>
                <div x-show="showQuickAdd" class="space-y-2">
                    <input type="text" x-model="quickCustomerName" placeholder="Customer name *" class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm px-3 py-2">
                    <input type="text" x-model="quickCustomerPhone" placeholder="Phone number" class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm px-3 py-2">
                    <div class="flex gap-2">
                        <button @click="addQuickCustomer()" :disabled="!quickCustomerName.trim()" class="flex-1 py-2 text-sm font-bold text-white bg-purple-600 hover:bg-purple-700 rounded-lg disabled:opacity-40 transition">Save</button>
                        <button @click="showQuickAdd = false; quickCustomerName = ''; quickCustomerPhone = ''" class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700 transition">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div x-show="showReceipt" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showReceipt = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm" x-transition.scale.90>
            <div class="p-5 text-center border-b border-gray-200 dark:border-gray-700">
                <div class="w-14 h-14 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center mx-auto mb-3">
                    <svg class="w-8 h-8 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Payment Successful!</h3>
                <p class="text-sm text-gray-500 mt-1" x-text="'Invoice: ' + lastInvoiceNumber"></p>
                <div class="text-3xl font-black text-green-600 mt-2" x-text="'Rs. ' + Number(lastTotal).toLocaleString()"></div>
                <div class="text-xs text-gray-400 mt-1" x-text="lastPaymentMethod.toUpperCase() + ' Payment'"></div>
            </div>
            <div class="p-4 grid grid-cols-2 gap-3">
                <a :href="'/pos/transactions/' + lastTransactionId + '/receipt'" target="_blank" class="py-3 text-center rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold transition shadow-sm">
                    <svg class="w-5 h-5 mx-auto mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    Print
                </a>
                <button @click="showReceipt = false" class="py-3 text-center rounded-xl bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm font-bold transition">
                    <svg class="w-5 h-5 mx-auto mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    New Sale
                </button>
            </div>
        </div>
    </div>

    <div x-show="toast.show" x-transition.opacity
        class="fixed top-4 right-4 z-[60] max-w-xs px-4 py-2.5 rounded-xl shadow-2xl text-sm font-medium"
        :class="toast.type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white'"
        x-text="toast.message"></div>
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
        gridCols: 5,
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
            document.addEventListener('keydown', (e) => {
                if (e.key === 'F5') { e.preventDefault(); this.holdOrder(); }
                if (e.key === 'F8') { e.preventDefault(); if (this.cart.length) this.showPayModal = true; }
                if (e.ctrlKey && e.key === 's') { e.preventDefault(); this.enterSearchMode(); }
                if (e.key === 'Escape') {
                    if (this.showPayModal) { this.showPayModal = false; return; }
                    if (this.showHeldOrders) { this.showHeldOrders = false; return; }
                    if (this.showTablePicker) { this.showTablePicker = false; return; }
                    if (this.gridFocusMode) { this.enterSearchMode(); return; }
                    if (this.activeCategory !== 'all') { this.activeCategory = 'all'; this.searchQuery = ''; this.filterProducts(); return; }
                }
                if (e.key === 'Tab' && !e.shiftKey && !this.gridFocusMode && document.activeElement === this.$refs.searchInput && !this.showSearchDropdown) {
                    e.preventDefault(); this.enterGridMode();
                }
            });
            this.$nextTick(() => { this.$refs.searchInput?.focus(); });
        },

        calcGridCols() {
            const w = this.$refs.gridContainer?.offsetWidth || 800;
            if (w >= 1024) this.gridCols = 5;
            else if (w >= 768) this.gridCols = 4;
            else if (w >= 640) this.gridCols = 3;
            else this.gridCols = 2;
        },

        enterSearchMode() {
            this.gridFocusMode = false;
            this.$refs.searchInput?.focus();
        },

        enterGridMode() {
            if (this.filteredItems.length === 0) return;
            this.gridFocusMode = true;
            this.gridFocusIndex = 0;
            this.showSearchDropdown = false;
            this.$refs.gridContainer?.focus();
            this.scrollGridItemIntoView(0);
        },

        moveGridFocus(delta) {
            if (!this.gridFocusMode) { this.enterGridMode(); return; }
            const newIdx = this.gridFocusIndex + delta;
            if (newIdx >= 0 && newIdx < this.filteredItems.length) {
                this.gridFocusIndex = newIdx;
                this.scrollGridItemIntoView(newIdx);
            }
        },

        scrollGridItemIntoView(idx) {
            this.$nextTick(() => {
                const el = document.getElementById('grid-item-' + idx);
                if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
        },

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
                    item_id: item.id, item_type: item.type, item_name: item.name,
                    quantity: 1, unit_price: parseFloat(item.price),
                    special_notes: '', is_tax_exempt: item.is_tax_exempt || false,
                });
            }
            this.cartAnimating = true;
            setTimeout(() => this.cartAnimating = false, 300);
        },

        updateQty(index, delta) {
            this.cart[index].quantity = Math.max(0.01, parseFloat(this.cart[index].quantity) + delta);
        },

        setQty(index, val) {
            const v = parseFloat(val);
            if (v > 0) this.cart[index].quantity = v;
        },

        removeFromCart(index) { this.cart.splice(index, 1); },

        clearCart() { this.cart = []; this.kitchenNotes = ''; this.selectedTable = null; this.selectedCustomer = null; this.stockError = ''; },

        newSale() {
            if (this.cart.length > 0) {
                if (!confirm('Current order has ' + this.cart.length + ' item(s). Discard and start new sale?')) return;
            }
            this.clearCart();
            this.showToast('New sale started', 'success');
        },

        voidOrder() {
            if (this.cart.length === 0) return;
            if (!confirm('Void current order? All items will be removed.')) return;
            this.clearCart();
            this.showToast('Order voided', 'success');
        },

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
                        items: this.cart, order_type: this.orderType,
                        table_id: this.selectedTable?.id || null,
                        customer_id: this.selectedCustomer?.id || null,
                        customer_name: this.selectedCustomer?.name || null,
                        customer_phone: this.selectedCustomer?.phone || null,
                        kitchen_notes: this.kitchenNotes,
                    }),
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast(data.message, 'success');
                    this.heldOrders.unshift(data.order);
                    this.clearCart();
                } else { this.showToast(data.message || 'Failed', 'error'); }
            } catch (e) { this.showToast('Network error', 'error'); }
            this.submitting = false;
        },

        async processPayment(method) {
            if (this.cart.length === 0 || this.submitting) return;
            this.submitting = true;
            this.stockError = '';

            try {
                const holdRes = await fetch('{{ route("pos.restaurant.orders.hold") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({
                        items: this.cart, order_type: this.orderType,
                        table_id: this.selectedTable?.id || null,
                        customer_id: this.selectedCustomer?.id || null,
                        customer_name: this.selectedCustomer?.name || null,
                        customer_phone: this.selectedCustomer?.phone || null,
                        kitchen_notes: this.kitchenNotes,
                    }),
                });
                const holdData = await holdRes.json();
                if (!holdData.success) {
                    this.showToast(holdData.message || 'Failed to create order', 'error');
                    this.submitting = false;
                    return;
                }

                const savedTotal = this.totalAmount;
                await this.payHeldOrderDirect(holdData.order.id, method, savedTotal);
                this.clearCart();
            } catch (e) {
                this.showToast('Network error', 'error');
            }

            this.showPayModal = false;
            this.submitting = false;
        },

        async payHeldOrder(orderId) {
            this.showHeldOrders = false;
            this.showPayModal = false;
            this.stockError = '';
            this.submitting = true;
            await this.payHeldOrderDirect(orderId, 'cash', null);
            this.submitting = false;
        },

        async payHeldOrderDirect(orderId, method, savedTotal) {
            try {
                const res = await fetch(`/pos/restaurant/orders/${orderId}/pay`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ payment_method: method }),
                });
                const data = await res.json();
                if (data.success) {
                    this.heldOrders = this.heldOrders.filter(o => o.id !== orderId);
                    this.lastInvoiceNumber = data.invoice_number || '';
                    this.lastTransactionId = data.transaction_id || null;
                    this.lastTotal = savedTotal || data.total_amount || 0;
                    this.lastPaymentMethod = method;
                    this.showReceipt = true;
                } else {
                    if (data.stock_error) { this.stockError = data.message; this.showPayModal = true; }
                    this.showToast(data.message || 'Payment failed', 'error');
                }
            } catch (e) { this.showToast('Payment error', 'error'); }
        },

        recallOrder(order) {
            if (this.cart.length > 0 && !confirm('Current cart has items. Replace with recalled order?')) return;
            this.cart = order.items.map(i => ({
                item_id: i.item_id, item_type: i.item_type, item_name: i.item_name,
                quantity: parseFloat(i.quantity), unit_price: parseFloat(i.unit_price),
                special_notes: i.special_notes || '', is_tax_exempt: i.is_tax_exempt || false,
            }));
            this.kitchenNotes = order.kitchen_notes || '';
            if (order.table) {
                this.selectedTable = { id: order.table.id, table_number: order.table.table_number };
                this.orderType = 'dine_in';
            }
            this.selectedCustomer = order.customer_id ? { id: order.customer_id, name: order.customer_name || 'Customer', phone: order.customer_phone || '' } : null;
            this.heldOrders = this.heldOrders.filter(o => o.id !== order.id);
            this.showHeldOrders = false;
            this.showToast('Order recalled to cart', 'success');
        },

        async addQuickCustomer() {
            if (!this.quickCustomerName.trim()) return;
            try {
                const res = await fetch('{{ route("pos.customers.store") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ name: this.quickCustomerName.trim(), phone: this.quickCustomerPhone.trim() || null, type: 'unregistered' }),
                });
                const data = await res.json();
                if (data.customer || data.success) {
                    const cust = data.customer || { id: Date.now(), name: this.quickCustomerName.trim(), phone: this.quickCustomerPhone.trim() };
                    this.allCustomers.push(cust);
                    this.selectedCustomer = cust;
                    this.showQuickAdd = false;
                    this.quickCustomerName = '';
                    this.quickCustomerPhone = '';
                    this.showCustomerPicker = false;
                    this.showToast('Customer added: ' + cust.name, 'success');
                } else {
                    this.showToast(data.message || 'Failed to add customer', 'error');
                }
            } catch (e) {
                this.showToast('Error adding customer', 'error');
            }
        },

        showToast(msg, type) {
            this.toast = { show: true, message: msg, type };
            setTimeout(() => this.toast.show = false, 2500);
        },
    };
}
</script>
</x-pos-layout>
