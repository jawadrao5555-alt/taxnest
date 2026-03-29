<x-pos-layout>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
@keyframes cartPop { 0% { transform: scale(1); } 50% { transform: scale(1.12); } 100% { transform: scale(1); } }
@keyframes slideIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
@keyframes pulse-ring { 0% { transform: scale(0.8); opacity: 1; } 100% { transform: scale(1.8); opacity: 0; } }
.cart-pop { animation: cartPop 0.2s ease; }
.slide-in { animation: slideIn 0.2s ease; }
.fade-in { animation: fadeIn 0.15s ease; }
.skeleton { background: linear-gradient(90deg, #e5e7eb 25%, #f3f4f6 50%, #e5e7eb 75%); background-size: 200% 100%; animation: shimmer 1.5s ease-in-out infinite; }
.dark .skeleton { background: linear-gradient(90deg, #1f2937 25%, #374151 50%, #1f2937 75%); background-size: 200% 100%; }
.prod-card { transition: all 0.15s cubic-bezier(0.4, 0, 0.2, 1); cursor: pointer; position: relative; }
.prod-card:hover { transform: translateY(-4px); box-shadow: 0 20px 40px -12px rgba(0,0,0,0.15); }
.prod-card:active { transform: translateY(-1px) scale(0.98); }
.prod-card .quick-add { opacity: 0; transform: scale(0.7); transition: all 0.15s ease; }
.prod-card:hover .quick-add { opacity: 1; transform: scale(1); }
.prod-card.stock-out { opacity: 0.5; pointer-events: none; }
.prod-card.stock-out.allow-add { opacity: 0.7; pointer-events: auto; }
.cat-pill { transition: all 0.15s ease; white-space: nowrap; }
.cat-pill:hover { transform: scale(1.05); }
.cat-pill.active { background: linear-gradient(135deg, #7c3aed, #a855f7); color: white; box-shadow: 0 4px 15px rgba(124,58,237,0.35); }
.cart-item { transition: all 0.15s ease; }
.cart-item:hover { background: rgba(139,92,246,0.04); }
.stock-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.stock-available { background: #22c55e; }
.stock-low { background: #f59e0b; box-shadow: 0 0 0 2px rgba(245,158,11,0.3); }
.stock-out { background: #ef4444; }
.priority-badge { position: relative; }
.priority-badge::after { content: ''; position: absolute; top: -1px; right: -1px; width: 8px; height: 8px; background: #ef4444; border-radius: 50%; }
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
.dark ::-webkit-scrollbar-thumb { background: #4b5563; }
.hide-scrollbar::-webkit-scrollbar { display: none; }
.hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
.freq-badge { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; font-size: 9px; padding: 1px 6px; border-radius: 999px; font-weight: 700; }
</style>

<div x-data="restaurantPos()" x-init="init()" class="flex flex-col h-[calc(100vh-64px)] overflow-hidden bg-gray-50 dark:bg-gray-950">

    <div class="flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex-shrink-0 shadow-sm">
        <div class="flex-1 relative max-w-md">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" x-ref="searchInput" x-model="searchQuery" @input="onSearchInput()" @keydown.arrow-down.prevent="moveHighlight(1)" @keydown.arrow-up.prevent="moveHighlight(-1)" @keydown.enter.prevent="addHighlightedItem()" @focus="if(searchQuery) showSearchDropdown = true" @click.away="showSearchDropdown = false" placeholder="Search products... (Ctrl+S)" class="w-full pl-9 pr-3 py-2 rounded-xl text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition">
            <div x-show="showSearchDropdown && searchSuggestions.length > 0" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-2xl z-50 max-h-64 overflow-y-auto">
                <template x-for="(s, i) in searchSuggestions" :key="s.id + s.type">
                    <button @click="quickAddItem(s)" class="w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="i === highlightIndex ? 'bg-purple-50 dark:bg-purple-900/20' : ''">
                        <template x-if="s.image">
                            <img :src="s.image" class="w-8 h-8 rounded-lg object-cover flex-shrink-0">
                        </template>
                        <template x-if="!s.image">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-100 to-purple-50 dark:from-purple-900/30 dark:to-purple-800/20 flex items-center justify-center flex-shrink-0">
                                <span class="text-xs font-bold text-purple-600 dark:text-purple-400" x-text="s.name.charAt(0)"></span>
                            </div>
                        </template>
                        <div class="flex-1 min-w-0">
                            <span class="text-sm font-medium text-gray-900 dark:text-white truncate block" x-text="s.name"></span>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[10px] text-gray-400" x-text="s.type === 'service' ? 'Service' : s.category"></span>
                                <template x-if="s.stockStatus"><span class="stock-dot" :class="'stock-' + s.stockStatus"></span></template>
                            </div>
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
            <template x-if="customerStats && customerStats.is_frequent"><span class="freq-badge ml-0.5">VIP</span></template>
        </button>

        <select x-model="orderType" class="text-xs rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-2.5 py-2 focus:ring-purple-500">
            <option value="dine_in">Dine In</option>
            <option value="takeaway">Takeaway</option>
            <option value="delivery">Delivery</option>
        </select>

        <div class="w-px h-8 bg-gray-200 dark:bg-gray-700 hidden sm:block"></div>

        <button @click="priorityOrder = !priorityOrder" class="hidden sm:flex items-center gap-1 px-2.5 py-2 rounded-xl text-xs font-semibold border transition" :class="priorityOrder ? 'bg-red-50 dark:bg-red-900/20 border-red-300 text-red-600' : 'border-gray-200 dark:border-gray-700 text-gray-500 hover:bg-gray-50'">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <span>Rush</span>
        </button>

        <button @click="newSale()" class="flex items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 hover:bg-green-100 transition">
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
                <button @click="activeCategory = 'all'; filterProducts()" class="cat-pill px-4 py-1.5 rounded-full text-xs font-semibold border" :class="activeCategory === 'all' ? 'active border-transparent' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800'">
                    All <span class="ml-1 text-[10px] opacity-70" x-text="'(' + (allProducts.length + allServices.length) + ')'"></span>
                </button>
                @foreach($categories as $cat)
                <button @click="activeCategory = '{{ $cat }}'; filterProducts()" class="cat-pill px-4 py-1.5 rounded-full text-xs font-semibold border" :class="activeCategory === '{{ $cat }}' ? 'active border-transparent' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800'">{{ $cat }}</button>
                @endforeach
                <button @click="activeCategory = 'services'; filterProducts()" class="cat-pill px-4 py-1.5 rounded-full text-xs font-semibold border" :class="activeCategory === 'services' ? 'active border-transparent' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800'">Services</button>
            </div>

            <div x-ref="gridContainer" tabindex="0" @keydown.arrow-right.prevent="moveGridFocus(1)" @keydown.arrow-left.prevent="moveGridFocus(-1)" @keydown.arrow-down.prevent="moveGridFocus(gridCols)" @keydown.arrow-up.prevent="moveGridFocus(-gridCols)" @keydown.enter.prevent="addGridFocusedItem()" class="flex-1 overflow-y-auto p-3 outline-none">

                <template x-if="loading">
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                        <template x-for="i in 12"><div class="rounded-2xl overflow-hidden"><div class="skeleton aspect-square"></div><div class="p-2.5 space-y-2"><div class="skeleton h-3 rounded w-3/4"></div><div class="skeleton h-4 rounded w-1/2"></div></div></div></template>
                    </div>
                </template>

                <template x-if="!loading">
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                        <template x-for="(item, idx) in displayItems" :key="item.id + '-' + item.type">
                            <div :id="'grid-item-' + idx" class="prod-card bg-white dark:bg-gray-900 rounded-2xl overflow-hidden border border-gray-100 dark:border-gray-800 shadow-sm fade-in" :class="[gridFocusMode && gridFocusIndex === idx ? 'ring-2 ring-purple-500 shadow-purple-200 dark:shadow-purple-900' : '', item.stockStatus === 'out' && blockOutOfStock ? 'stock-out' : (item.stockStatus === 'out' && !blockOutOfStock ? 'stock-out allow-add' : '')]" @click="handleProductClick(item)">
                                <div class="relative aspect-[4/3] bg-gradient-to-br from-gray-100 to-gray-50 dark:from-gray-800 dark:to-gray-900 flex items-center justify-center overflow-hidden">
                                    <template x-if="item.image">
                                        <img :src="item.image" :alt="item.name" class="w-full h-full object-cover" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    </template>
                                    <div x-show="!item.image" class="flex flex-col items-center justify-center text-gray-300 dark:text-gray-600 w-full h-full">
                                        <svg class="w-10 h-10 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    </div>
                                    <div class="absolute top-1.5 left-1.5 flex flex-col gap-1">
                                        <template x-if="item.stockStatus === 'available'"><span class="stock-dot stock-available"></span></template>
                                        <template x-if="item.stockStatus === 'low'"><span class="stock-dot stock-low" title="Low stock"></span></template>
                                        <template x-if="item.stockStatus === 'out'"><span class="px-1.5 py-0.5 bg-red-500/90 text-white text-[8px] font-bold rounded-md">OUT</span></template>
                                    </div>
                                    <div class="absolute top-1.5 right-1.5 flex flex-col gap-1">
                                        <template x-if="item.hasRecipe"><span class="px-1.5 py-0.5 bg-blue-500/90 text-white text-[8px] font-bold rounded-md">RECIPE</span></template>
                                        <template x-if="item.is_tax_exempt"><span class="px-1.5 py-0.5 bg-green-500/90 text-white text-[8px] font-bold rounded-md">NO TAX</span></template>
                                    </div>
                                    <button @click.stop="handleProductClick(item)" class="quick-add absolute bottom-2 right-2 w-9 h-9 rounded-full bg-purple-600 hover:bg-purple-700 text-white shadow-lg shadow-purple-600/30 flex items-center justify-center transition-all">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                                    </button>
                                </div>
                                <div class="px-3 py-2.5">
                                    <p class="text-xs font-semibold text-gray-900 dark:text-white truncate leading-tight" x-text="item.name"></p>
                                    <div class="flex items-center justify-between mt-1">
                                        <p class="text-sm font-extrabold text-purple-600 dark:text-purple-400" x-text="'Rs. ' + Number(item.price).toLocaleString()"></p>
                                        <template x-if="getCartQty(item) > 0">
                                            <span class="text-[10px] bg-purple-600 text-white w-5 h-5 rounded-full flex items-center justify-center font-bold" x-text="getCartQty(item)"></span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="!loading && displayItems.length === 0">
                    <div class="flex flex-col items-center justify-center py-24 text-gray-400 fade-in">
                        <div class="w-24 h-24 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-4">
                            <svg class="w-12 h-12 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                        <p class="text-base font-semibold text-gray-600 dark:text-gray-300">No products found</p>
                        <p class="text-sm mt-1">Try a different category or search term</p>
                        <button @click="activeCategory = 'all'; searchQuery = ''; filterProducts()" class="mt-4 px-4 py-2 text-sm font-semibold text-purple-600 bg-purple-50 dark:bg-purple-900/20 rounded-xl hover:bg-purple-100 transition">Show All Products</button>
                    </div>
                </template>

                <template x-if="!loading && filteredItems.length > displayCount">
                    <div class="flex justify-center py-4">
                        <button @click="loadMore()" class="px-6 py-2.5 text-sm font-semibold text-purple-600 bg-purple-50 dark:bg-purple-900/20 rounded-xl hover:bg-purple-100 transition border border-purple-200 dark:border-purple-800">
                            Load More (<span x-text="filteredItems.length - displayCount"></span> remaining)
                        </button>
                    </div>
                </template>
            </div>

            <div class="md:hidden flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800">
                <button @click="mobileView = 'cart'" class="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl bg-purple-600 text-white text-sm font-bold shadow-lg shadow-purple-600/20">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                    Cart
                    <span x-show="cart.length > 0" class="bg-white/20 px-1.5 rounded-full text-xs" x-text="cart.length"></span>
                    <span x-show="cart.length > 0" class="text-xs opacity-80" x-text="'Rs. ' + Number(totalAmount).toLocaleString()"></span>
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
                <template x-if="priorityOrder"><span class="text-[10px] bg-red-100 text-red-600 px-2 py-0.5 rounded-full font-bold">RUSH</span></template>
                <span class="text-[10px] bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 px-2 py-0.5 rounded-full font-semibold" x-text="orderType.replace('_', ' ').toUpperCase()"></span>
                <template x-if="selectedTable">
                    <span class="text-[10px] bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded-full font-semibold" x-text="'T-' + selectedTable.table_number"></span>
                </template>
            </div>

            <template x-if="selectedCustomer && customerStats">
                <div class="px-3 py-2 bg-blue-50 dark:bg-blue-900/10 border-b border-blue-100 dark:border-blue-900/20 flex items-center gap-2">
                    <div class="w-7 h-7 rounded-full bg-blue-200 dark:bg-blue-800 flex items-center justify-center"><span class="text-xs font-bold text-blue-700 dark:text-blue-300" x-text="selectedCustomer.name.charAt(0)"></span></div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-blue-800 dark:text-blue-200 truncate" x-text="selectedCustomer.name"></p>
                        <p class="text-[10px] text-blue-600 dark:text-blue-400" x-text="customerStats.total_orders + ' orders • Rs. ' + Number(customerStats.total_spent).toLocaleString() + ' total'"></p>
                    </div>
                    <template x-if="customerStats.is_frequent"><span class="freq-badge">VIP</span></template>
                </div>
            </template>

            <div class="flex-1 overflow-y-auto">
                <template x-if="cart.length === 0">
                    <div class="flex flex-col items-center justify-center h-full text-gray-400 py-16">
                        <div class="w-20 h-20 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-4">
                            <svg class="w-10 h-10 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4"/></svg>
                        </div>
                        <p class="text-sm font-medium">Empty order</p>
                        <p class="text-xs mt-1 text-gray-300">Tap products to add them</p>
                    </div>
                </template>
                <template x-for="(item, index) in cart" :key="index">
                    <div class="cart-item px-3 py-2.5 border-b border-gray-50 dark:border-gray-800 slide-in">
                        <div class="flex items-center gap-2">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white truncate" x-text="item.item_name"></p>
                                <p class="text-xs text-gray-400 mt-0.5" x-text="'Rs. ' + Number(item.unit_price).toLocaleString() + ' each'"></p>
                            </div>
                            <div class="flex items-center gap-1 bg-gray-100 dark:bg-gray-800 rounded-lg px-0.5">
                                <button @click="updateQty(index, -1)" class="w-7 h-7 flex items-center justify-center rounded-md hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 transition">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M20 12H4"/></svg>
                                </button>
                                <input type="number" :value="item.quantity" @change="setQty(index, $event.target.value)" class="w-10 text-center text-sm font-bold bg-transparent text-gray-900 dark:text-white border-0 focus:ring-0 p-0" min="0.01" step="1">
                                <button @click="updateQty(index, 1)" class="w-7 h-7 flex items-center justify-center rounded-md hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 transition">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4"/></svg>
                                </button>
                            </div>
                            <div class="text-right min-w-[56px]">
                                <p class="text-sm font-bold text-gray-900 dark:text-white" x-text="'Rs. ' + (item.quantity * item.unit_price).toLocaleString()"></p>
                            </div>
                            <button @click="removeFromCart(index)" class="p-1 text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                        <input type="text" x-model="item.special_notes" placeholder="Special notes..." class="mt-1.5 w-full text-[11px] bg-gray-50 dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-lg px-2 py-1 text-gray-600 dark:text-gray-400 focus:ring-purple-500 placeholder-gray-300">
                    </div>
                </template>
            </div>

            <div class="border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/80 backdrop-blur-sm">
                <div class="px-3 py-1.5">
                    <textarea x-model="kitchenNotes" rows="1" placeholder="Kitchen notes..." class="w-full text-xs bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-2.5 py-1.5 text-gray-700 dark:text-gray-300 focus:ring-purple-500 resize-none placeholder-gray-300"></textarea>
                </div>
                <div class="px-3 py-2 space-y-1">
                    <div class="flex justify-between text-xs text-gray-500"><span>Subtotal</span><span x-text="'Rs. ' + Number(subtotal).toLocaleString()"></span></div>
                    <div x-show="exemptAmount > 0" class="flex justify-between text-xs text-green-600 dark:text-green-400"><span>Tax-Exempt</span><span x-text="'-Rs. ' + Number(exemptAmount).toLocaleString()"></span></div>
                    <div class="flex justify-between text-xs text-gray-500"><span x-text="'Tax (' + taxRate + '%)'"></span><span x-text="'Rs. ' + Number(taxAmount).toLocaleString()"></span></div>
                    <div class="flex justify-between text-lg font-extrabold text-gray-900 dark:text-white pt-1.5 border-t border-gray-200 dark:border-gray-700">
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
                    <button @click="showPayModal = true" :disabled="cart.length === 0 || submitting" class="w-full py-3.5 rounded-xl text-sm font-extrabold text-white bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 disabled:opacity-30 shadow-lg shadow-green-600/25 transition-all transform hover:scale-[1.01] active:scale-[0.99]">
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
                <p x-show="submitting" class="text-xs text-purple-500 mt-2">Processing payment...</p>
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
                <button @click="showPayModal = false" :disabled="submitting" class="w-full py-2.5 rounded-xl text-sm font-semibold text-gray-500 hover:text-gray-700 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 transition disabled:opacity-50">Cancel</button>
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
                            <div class="flex items-center gap-1.5">
                                <template x-if="order.priority"><span class="text-[9px] bg-red-100 text-red-600 px-1.5 py-0.5 rounded-full font-bold">RUSH</span></template>
                                <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="{'bg-amber-100 text-amber-700': order.status==='held', 'bg-blue-100 text-blue-700': order.status==='preparing', 'bg-green-100 text-green-700': order.status==='ready'}" x-text="order.status"></span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mb-1" x-text="'Rs. ' + Number(order.total_amount).toLocaleString() + ' • ' + order.items.length + ' item(s)'"></p>
                        <template x-if="order.table"><p class="text-[10px] text-purple-600" x-text="'Table: T-' + order.table.table_number"></p></template>
                        <div class="flex gap-2 mt-2">
                            <button @click="recallOrder(order)" class="flex-1 py-2 text-xs font-bold text-purple-600 border border-purple-300 rounded-xl hover:bg-purple-50 transition">Recall</button>
                            <a :href="'/pos/restaurant/orders/' + order.id + '/kitchen-ticket'" target="_blank" class="flex-1 py-2 text-xs font-bold text-center text-orange-600 border border-orange-300 rounded-xl hover:bg-orange-50 transition">KOT</a>
                            <button @click="payHeldOrder(order.id)" class="flex-1 py-2 text-xs font-bold text-white bg-green-600 rounded-xl hover:bg-green-700 transition">Pay</button>
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
                <input type="text" x-model="customerSearch" @input="onCustomerPhoneSearch()" placeholder="Search by name or phone..." class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white text-sm px-3 py-2 focus:ring-purple-500">
                <template x-if="customerLookupResult && customerLookupResult.found">
                    <div class="mt-2 p-2.5 bg-green-50 dark:bg-green-900/20 rounded-xl border border-green-200 dark:border-green-800">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-green-200 dark:bg-green-800 flex items-center justify-center"><span class="text-xs font-bold text-green-700" x-text="customerLookupResult.customer.name.charAt(0)"></span></div>
                            <div class="flex-1">
                                <p class="text-xs font-bold text-green-800 dark:text-green-200" x-text="customerLookupResult.customer.name"></p>
                                <p class="text-[10px] text-green-600" x-text="customerLookupResult.stats.total_orders + ' orders • Last: ' + (customerLookupResult.stats.last_order_date || 'N/A')"></p>
                            </div>
                            <template x-if="customerLookupResult.stats.is_frequent"><span class="freq-badge">VIP</span></template>
                            <button @click="selectLookedUpCustomer()" class="px-3 py-1 text-xs font-bold text-white bg-green-600 rounded-lg">Select</button>
                        </div>
                    </div>
                </template>
            </div>
            <div class="max-h-[40vh] overflow-y-auto">
                <button @click="selectedCustomer = null; customerStats = null; showCustomerPicker = false" class="w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition border-b border-gray-100 dark:border-gray-800">
                    <div class="w-9 h-9 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center"><svg class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Walk-in Customer</span>
                </button>
                <template x-for="c in filteredCustomers" :key="c.id">
                    <button @click="selectCustomerWithStats(c)" class="w-full flex items-center gap-3 px-4 py-3 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition border-b border-gray-50 dark:border-gray-800">
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
                <button @click="showReceipt = false; clearCart();" class="py-3 text-center rounded-xl bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 text-gray-700 dark:text-gray-300 text-sm font-bold transition">New Sale</button>
            </div>
        </div>
    </div>

    <div x-show="toast.show" x-transition.opacity class="fixed top-4 right-4 z-[60] max-w-xs px-4 py-2.5 rounded-xl shadow-2xl text-sm font-medium" :class="toast.type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white'" x-text="toast.message"></div>
</div>

@php
$productsJson = $products->map(function($p) use ($recipeLookup, $stockStatus) {
    return [
        'id' => $p->id, 'type' => 'product', 'name' => $p->name,
        'price' => $p->price ?? 0, 'category' => $p->category,
        'is_tax_exempt' => $p->is_tax_exempt ?? false,
        'hasRecipe' => in_array($p->id, $recipeLookup ?? []),
        'image' => $p->image ? asset('storage/products/' . $p->image) : null,
        'stockStatus' => $stockStatus[$p->id] ?? null,
    ];
})->values();
$servicesJson = $services->map(function($s) {
    return [
        'id' => $s->id, 'type' => 'service', 'name' => $s->name,
        'price' => $s->price ?? 0, 'category' => 'Services',
        'is_tax_exempt' => $s->is_tax_exempt ?? false,
        'hasRecipe' => false, 'image' => null, 'stockStatus' => null,
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
        blockOutOfStock: {{ $blockOutOfStock ? 'true' : 'false' }},
        taxRate: {{ $taxRate }},
        filteredItems: [],
        displayItems: [],
        displayCount: 60,
        loading: true,
        activeCategory: 'all',
        searchQuery: '',
        searchSuggestions: [],
        showSearchDropdown: false,
        showCustomerPicker: false,
        customerSearch: '',
        customerLookupResult: null,
        customerLookupTimer: null,
        customerStats: null,
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
        priorityOrder: false,
        toast: { show: false, message: '', type: 'success' },
        lastHoldTime: 0,
        lastPayTime: 0,

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
            setTimeout(() => { this.loading = false; }, 300);
            this.$watch('activeCategory', () => { this.filterProducts(); this.gridFocusIndex = 0; });
            this.calcGridCols();
            window.addEventListener('resize', () => this.calcGridCols());
            this.restoreCart();
            this.$watch('cart', () => { this.saveCart(); }, { deep: true });
            this.$watch('kitchenNotes', () => { this.saveCart(); });
            this.cacheProductData();
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

        cacheProductData() {
            try {
                const key = 'rpos_products_cache_{{ app("currentCompanyId") ?? 0 }}';
                const cached = localStorage.getItem(key);
                if (cached) {
                    const data = JSON.parse(cached);
                    if (Date.now() - data.ts < 300000 && data.products.length > 0) {
                        return;
                    }
                }
                localStorage.setItem(key, JSON.stringify({ ts: Date.now(), products: this.allProducts, services: this.allServices }));
            } catch(e) {}
        },

        get storageKey() { return 'rpos_cart_{{ auth("pos")->id() ?? 0 }}_{{ app("currentCompanyId") ?? 0 }}'; },
        get notesKey() { return 'rpos_notes_{{ auth("pos")->id() ?? 0 }}_{{ app("currentCompanyId") ?? 0 }}'; },
        saveCart() {
            try { localStorage.setItem(this.storageKey, JSON.stringify(this.cart)); localStorage.setItem(this.notesKey, this.kitchenNotes); } catch(e) {}
        },
        restoreCart() {
            try {
                const saved = localStorage.getItem(this.storageKey);
                if (saved) { const parsed = JSON.parse(saved); if (Array.isArray(parsed) && parsed.length > 0) this.cart = parsed; }
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
            if (this.displayItems.length === 0) return;
            this.gridFocusMode = true; this.gridFocusIndex = 0; this.showSearchDropdown = false;
            this.$refs.gridContainer?.focus(); this.scrollGridItemIntoView(0);
        },
        moveGridFocus(delta) {
            if (!this.gridFocusMode) { this.enterGridMode(); return; }
            const newIdx = this.gridFocusIndex + delta;
            if (newIdx >= 0 && newIdx < this.displayItems.length) { this.gridFocusIndex = newIdx; this.scrollGridItemIntoView(newIdx); }
        },
        scrollGridItemIntoView(idx) { this.$nextTick(() => { document.getElementById('grid-item-' + idx)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }); },
        addGridFocusedItem() {
            if (!this.gridFocusMode || this.displayItems.length === 0) return;
            const item = this.displayItems[this.gridFocusIndex];
            if (item) this.handleProductClick(item);
        },

        handleProductClick(item) {
            if (item.stockStatus === 'out' && this.blockOutOfStock) {
                this.showToast(item.name + ' is out of stock', 'error');
                return;
            }
            this.addToCart(item);
            this.showToast(item.name + ' added', 'success');
        },

        getCartQty(item) {
            const found = this.cart.find(c => c.item_id === item.id && c.item_type === item.type);
            return found ? found.quantity : 0;
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
            this.handleProductClick(item);
            this.searchQuery = ''; this.searchSuggestions = []; this.showSearchDropdown = false;
            this.filterProducts(); this.$nextTick(() => { this.$refs.searchInput?.focus(); });
        },

        filterProducts() {
            let items = [...this.allProducts, ...this.allServices];
            if (this.activeCategory !== 'all' && this.activeCategory !== 'services') { items = this.allProducts.filter(p => p.category === this.activeCategory); }
            else if (this.activeCategory === 'services') { items = this.allServices; }
            if (this.searchQuery) { const q = this.searchQuery.toLowerCase(); items = items.filter(i => i.name.toLowerCase().includes(q)); }
            this.filteredItems = items;
            this.displayCount = 60;
            this.updateDisplayItems();
        },

        updateDisplayItems() {
            this.displayItems = this.filteredItems.slice(0, this.displayCount);
        },

        loadMore() {
            this.displayCount += 40;
            this.updateDisplayItems();
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
        clearCart() { this.cart = []; this.kitchenNotes = ''; this.selectedTable = null; this.selectedCustomer = null; this.customerStats = null; this.stockError = ''; this.priorityOrder = false; this.clearCartStorage(); },
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

        onCustomerPhoneSearch() {
            if (this.customerLookupTimer) clearTimeout(this.customerLookupTimer);
            const phone = this.customerSearch.trim();
            if (phone.length >= 4 && /^\d+$/.test(phone)) {
                this.customerLookupTimer = setTimeout(() => this.lookupCustomerByPhone(phone), 400);
            } else {
                this.customerLookupResult = null;
            }
        },

        async lookupCustomerByPhone(phone) {
            try {
                const res = await fetch('/pos/restaurant/api/customer-lookup?phone=' + encodeURIComponent(phone));
                this.customerLookupResult = await res.json();
            } catch(e) { this.customerLookupResult = null; }
        },

        selectLookedUpCustomer() {
            if (!this.customerLookupResult || !this.customerLookupResult.found) return;
            const c = this.customerLookupResult.customer;
            this.selectedCustomer = c;
            this.customerStats = this.customerLookupResult.stats;
            this.showCustomerPicker = false;
            this.customerLookupResult = null;
            this.showToast('Customer: ' + c.name + (this.customerStats.is_frequent ? ' (VIP)' : ''), 'success');
        },

        async selectCustomerWithStats(c) {
            this.selectedCustomer = c;
            this.showCustomerPicker = false;
            this.showToast('Customer: ' + c.name, 'success');
            if (c.phone) {
                try {
                    const res = await fetch('/pos/restaurant/api/customer-lookup?phone=' + encodeURIComponent(c.phone));
                    const data = await res.json();
                    if (data.found) this.customerStats = data.stats;
                } catch(e) {}
            }
        },

        async holdOrder() {
            if (this.cart.length === 0 || this.submitting) return;
            const now = Date.now();
            if (now - this.lastHoldTime < 2000) return;
            this.lastHoldTime = now;
            this.submitting = true;
            try {
                const res = await fetch('{{ route("pos.restaurant.orders.hold") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ items: this.cart, order_type: this.orderType, table_id: this.selectedTable?.id || null, customer_id: this.selectedCustomer?.id || null, customer_name: this.selectedCustomer?.name || null, customer_phone: this.selectedCustomer?.phone || null, kitchen_notes: this.kitchenNotes, priority: this.priorityOrder }),
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
            const now = Date.now();
            if (now - this.lastPayTime < 3000) return;
            this.lastPayTime = now;
            this.submitting = true; this.stockError = '';
            try {
                const holdRes = await fetch('{{ route("pos.restaurant.orders.hold") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ items: this.cart, order_type: this.orderType, table_id: this.selectedTable?.id || null, customer_id: this.selectedCustomer?.id || null, customer_name: this.selectedCustomer?.name || null, customer_phone: this.selectedCustomer?.phone || null, kitchen_notes: this.kitchenNotes, priority: this.priorityOrder }),
                });
                const holdData = await holdRes.json();
                if (!holdData.success) { this.showToast(holdData.message || 'Failed', 'error'); this.submitting = false; return; }
                const savedTotal = this.totalAmount;
                await this.payHeldOrderDirect(holdData.order.id, method, savedTotal);
                this.clearCart();
            } catch (e) { this.showToast('Network error', 'error'); }
            this.showPayModal = false; this.submitting = false;
        },

        async payHeldOrder(orderId) {
            if (this.submitting) return;
            this.showHeldOrders = false; this.showPayModal = false; this.stockError = ''; this.submitting = true;
            await this.payHeldOrderDirect(orderId, 'cash', null); this.submitting = false;
        },

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
