<x-pos-layout>
<style>
*, *::before, *::after { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
@keyframes cartPop { 0% { transform: scale(1); } 50% { transform: scale(1.12); } 100% { transform: scale(1); } }
@keyframes slideIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
@keyframes slideOut { from { opacity: 1; transform: translateX(0); max-height: 120px; } to { opacity: 0; transform: translateX(60px); max-height: 0; padding-top:0; padding-bottom:0; margin:0; } }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes fadeInUp { from { opacity: 0; transform: translateY(8px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
@keyframes shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
@keyframes pulse-ring { 0% { transform: scale(0.8); opacity: 1; } 100% { transform: scale(1.8); opacity: 0; } }
@keyframes scaleIn { 0% { transform: scale(0); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
@keyframes qtyPop { 0% { transform: scale(1); } 40% { transform: scale(1.2); } 100% { transform: scale(1); } }
@keyframes cartItemAdd { 0% { opacity: 0; transform: translateX(-20px) scale(0.95); } 100% { opacity: 1; transform: translateX(0) scale(1); } }
@keyframes ripple { to { transform: scale(4); opacity: 0; } }
@keyframes toastSlide { from { transform: translateX(120%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@keyframes toastSlideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(120%); opacity: 0; } }
@keyframes floatBadge { 0% { transform: translateY(0) scale(1); } 50% { transform: translateY(-3px) scale(1.05); } 100% { transform: translateY(0) scale(1); } }
@keyframes cardEnter { from { opacity: 0; transform: translateY(16px) scale(0.92); } to { opacity: 1; transform: translateY(0) scale(1); } }
@keyframes pulseGlow { 0%, 100% { box-shadow: 0 0 0 0 rgba(124,58,237,0.4); } 50% { box-shadow: 0 0 0 6px rgba(124,58,237,0); } }
.cart-pop { animation: cartPop 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); }
.qty-pop { animation: qtyPop 0.2s cubic-bezier(0.34, 1.56, 0.64, 1); }
.slide-in { animation: slideIn 0.25s cubic-bezier(0.16, 1, 0.3, 1); }
.fade-in { animation: fadeInUp 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.cart-item-enter { animation: cartItemAdd 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.cart-item-exit { animation: slideOut 0.25s ease forwards; overflow: hidden; }
.skeleton { background: linear-gradient(90deg, #e5e7eb 25%, #f3f4f6 50%, #e5e7eb 75%); background-size: 200% 100%; animation: shimmer 1.5s ease-in-out infinite; }
.dark .skeleton { background: linear-gradient(90deg, #1f2937 25%, #374151 50%, #1f2937 75%); background-size: 200% 100%; }
.prod-card { transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); cursor: pointer; position: relative; animation: cardEnter 0.35s cubic-bezier(0.16, 1, 0.3, 1) both; }
.prod-card:nth-child(2) { animation-delay: 0.02s; } .prod-card:nth-child(3) { animation-delay: 0.04s; } .prod-card:nth-child(4) { animation-delay: 0.06s; } .prod-card:nth-child(5) { animation-delay: 0.08s; } .prod-card:nth-child(6) { animation-delay: 0.1s; }
.prod-card:hover { transform: translateY(-6px) scale(1.02); box-shadow: 0 20px 40px -12px rgba(0,0,0,0.18), 0 0 0 1px rgba(124,58,237,0.1); }
.prod-card:active { transform: translateY(-2px) scale(0.97); transition-duration: 0.1s; }
.prod-card .quick-add { opacity: 0; transform: scale(0.5) rotate(-90deg); transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); }
.prod-card:hover .quick-add { opacity: 1; transform: scale(1) rotate(0deg); }
.prod-card.stock-out { opacity: 0.5; pointer-events: none; filter: grayscale(0.5); }
.prod-card.stock-out.allow-add { opacity: 0.7; pointer-events: auto; filter: grayscale(0.3); }
.prod-card .cart-qty-badge { animation: floatBadge 2s ease-in-out infinite; }
.letter-A,.letter-B { background: linear-gradient(135deg, #f472b6, #ec4899, #db2777) !important; }
.letter-C,.letter-D { background: linear-gradient(135deg, #a78bfa, #8b5cf6, #7c3aed) !important; }
.letter-E,.letter-F { background: linear-gradient(135deg, #60a5fa, #3b82f6, #2563eb) !important; }
.letter-G,.letter-H { background: linear-gradient(135deg, #34d399, #10b981, #059669) !important; }
.letter-I,.letter-J { background: linear-gradient(135deg, #fbbf24, #f59e0b, #d97706) !important; }
.letter-K,.letter-L { background: linear-gradient(135deg, #f87171, #ef4444, #dc2626) !important; }
.letter-M,.letter-N { background: linear-gradient(135deg, #c084fc, #a855f7, #9333ea) !important; }
.letter-O,.letter-P { background: linear-gradient(135deg, #38bdf8, #0ea5e9, #0284c7) !important; }
.letter-Q,.letter-R { background: linear-gradient(135deg, #fb923c, #f97316, #ea580c) !important; }
.letter-S,.letter-T { background: linear-gradient(135deg, #2dd4bf, #14b8a6, #0d9488) !important; }
.letter-U,.letter-V { background: linear-gradient(135deg, #e879f9, #d946ef, #c026d3) !important; }
.letter-W,.letter-X { background: linear-gradient(135deg, #818cf8, #6366f1, #4f46e5) !important; }
.letter-Y,.letter-Z { background: linear-gradient(135deg, #a3e635, #84cc16, #65a30d) !important; }
.cat-pill { transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); white-space: nowrap; position: relative; overflow: hidden; }
.cat-pill:hover { transform: translateY(-2px) scale(1.05); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.cat-pill.active { background: linear-gradient(135deg, #7c3aed, #a855f7); color: white; box-shadow: 0 4px 15px rgba(124,58,237,0.35); transform: scale(1.05); }
.cart-item { transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
.cart-item:hover { background: rgba(139,92,246,0.06); }
.stock-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.stock-available { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,0.4); }
.stock-low { background: #f59e0b; box-shadow: 0 0 6px rgba(245,158,11,0.5); animation: pulseGlow 2s ease-in-out infinite; }
.stock-low { --tw-shadow-color: rgba(245,158,11,0.4); }
.stock-out { background: #ef4444; box-shadow: 0 0 4px rgba(239,68,68,0.3); }
.btn-ripple { position: relative; overflow: hidden; }
.btn-ripple::after { content: ''; position: absolute; width: 100%; padding-top: 100%; border-radius: 50%; background: rgba(255,255,255,0.2); top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0); opacity: 1; transition: none; }
.btn-ripple:active::after { animation: ripple 0.5s ease-out; }
.toast-enter { animation: toastSlide 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.toast-exit { animation: toastSlideOut 0.3s ease forwards; }
.price-badge { background: linear-gradient(135deg, rgba(124,58,237,0.08), rgba(124,58,237,0.15)); border: 1px solid rgba(124,58,237,0.15); border-radius: 8px; padding: 2px 8px; }
.dark .price-badge { background: linear-gradient(135deg, rgba(167,139,250,0.1), rgba(167,139,250,0.2)); border-color: rgba(167,139,250,0.2); }
@media (max-width: 767px) {
    .mobile-sticky-pay { position: sticky; bottom: 0; z-index: 20; background: inherit; padding-bottom: env(safe-area-inset-bottom, 0); }
    .mobile-collapse-header { cursor: pointer; user-select: none; }
    .mobile-collapse-header::after { content: '▾'; float: right; transition: transform 0.2s; font-size: 10px; color: #9ca3af; }
    .mobile-collapse-header.collapsed::after { transform: rotate(-90deg); }
    .prod-card { min-height: 0; }
    .prod-card:hover { transform: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    .prod-card:active { transform: scale(0.96); }
    .cart-item { padding: 10px 12px !important; }
    .cart-item .qty-btn-mobile { min-width: 44px; min-height: 44px; }
}
.priority-badge { position: relative; }
.priority-badge::after { content: ''; position: absolute; top: -1px; right: -1px; width: 8px; height: 8px; background: #ef4444; border-radius: 50%; }
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
.dark ::-webkit-scrollbar-thumb { background: #4b5563; }
.hide-scrollbar::-webkit-scrollbar { display: none; }
.hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
.freq-badge { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; font-size: 9px; padding: 1px 6px; border-radius: 999px; font-weight: 700; }
.swipe-hint { position: absolute; right: 0; top: 0; bottom: 0; width: 60px; background: linear-gradient(90deg, transparent, rgba(239,68,68,0.1)); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; pointer-events: none; }
.cart-item:hover .swipe-hint { opacity: 1; }
@keyframes confettiFall { 0% { transform: translateY(-20px) rotate(0deg) scale(0); opacity: 1; } 50% { opacity: 1; } 100% { transform: translateY(200px) rotate(720deg) scale(1); opacity: 0; } }
@keyframes successPulse { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,0.5); } 70% { box-shadow: 0 0 0 20px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
@keyframes checkDraw { 0% { stroke-dashoffset: 24; } 100% { stroke-dashoffset: 0; } }
@keyframes receiptSlideUp { from { opacity: 0; transform: translateY(30px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
.search-glow:focus { box-shadow: 0 0 0 3px rgba(124,58,237,0.15), 0 0 20px rgba(124,58,237,0.1) !important; border-color: #7c3aed !important; }
.dark .search-glow:focus { box-shadow: 0 0 0 3px rgba(167,139,250,0.2), 0 0 20px rgba(167,139,250,0.08) !important; }
@keyframes heldBadgePulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.25); } }
.held-badge-pulse { animation: heldBadgePulse 1.5s ease-in-out infinite; }
.cat-pill.active::after { content: ''; position: absolute; bottom: 0; left: 15%; right: 15%; height: 3px; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.8), transparent); border-radius: 2px; }
.total-animate { transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.confetti-piece { position: absolute; width: 8px; height: 8px; border-radius: 2px; animation: confettiFall 1.5s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards; pointer-events: none; }
.receipt-modal-enter { animation: receiptSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.success-icon-animate { animation: successPulse 1.5s ease-out 0.3s; }
</style>
<script>
window.history.pushState(null, null, window.location.href);
window.addEventListener('popstate', function() {
    window.history.pushState(null, null, window.location.href);
});
</script>

<div x-data="restaurantPos()" x-init="init()" class="flex flex-col h-[calc(100vh-64px)] overflow-hidden bg-gray-50 dark:bg-gray-950">

    <div class="flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex-shrink-0 shadow-sm">

        <div class="relative flex-shrink-0" style="min-width:180px;max-width:220px;">
            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
            <input type="search" x-ref="customerPhoneInput" x-model="customerPhoneQuery" @input="onCustomerPhoneInput()" @keydown.enter.prevent="onCustomerPhoneEnter()" @keydown.escape.prevent="customerPhoneDropdown = false" @keydown.tab.prevent="$refs.searchInput?.focus()" @click.away="customerPhoneDropdown = false" inputmode="tel" placeholder="Customer mobile..." class="w-full pl-9 pr-7 py-2.5 rounded-xl text-sm border-2 transition shadow-sm font-medium" :class="selectedCustomer ? 'border-blue-400 dark:border-blue-600 bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-200' : 'border-blue-200 dark:border-blue-800 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-400'" autocomplete="one-time-code" name="pos_customer_phone_nofill" data-lpignore="true" data-form-type="other">
            <kbd x-show="!customerPhoneQuery && !selectedCustomer" class="absolute right-2 top-1/2 -translate-y-1/2 text-[8px] text-gray-400 bg-gray-100 dark:bg-gray-700 px-1 py-0.5 rounded font-mono">Ctrl+C</kbd>
            <button x-show="customerPhoneQuery || selectedCustomer" @click="clearCustomerInput()" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-red-500 transition">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div x-show="customerPhoneDropdown && customerPhoneResults.length > 0" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-2xl z-50 max-h-52 overflow-y-auto" style="min-width:280px;">
                <template x-for="(cr, ci) in customerPhoneResults" :key="cr.id">
                    <button @click="selectCustomerFromPhone(cr)" class="w-full flex items-center gap-2 px-3 py-2.5 text-left hover:bg-blue-50 dark:hover:bg-blue-900/20 transition border-b border-gray-50 dark:border-gray-800" :class="ci === 0 ? 'bg-blue-50/50 dark:bg-blue-900/10' : ''">
                        <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0"><span class="text-xs font-bold text-blue-600" x-text="cr.name.charAt(0)"></span></div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-gray-900 dark:text-white truncate" x-text="cr.name"></p>
                            <p class="text-[10px] text-gray-400" x-text="cr.phone + (cr.stats ? ' • ' + cr.stats.total_orders + ' orders • Rs.' + Number(cr.stats.total_spent).toLocaleString() : '')"></p>
                            <template x-if="cr.address"><p class="text-[9px] text-gray-400 truncate" x-text="cr.address"></p></template>
                        </div>
                        <template x-if="cr.stats && cr.stats.is_frequent"><span class="freq-badge">VIP</span></template>
                    </button>
                </template>
            </div>
        </div>

        <div class="w-px h-6 bg-gray-200 dark:bg-gray-700 hidden sm:block flex-shrink-0"></div>

        <div class="flex-1 relative">
            <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" x-ref="searchInput" x-model="searchQuery" @input="onSearchInput()" @keydown.arrow-down.prevent="moveHighlight(1)" @keydown.arrow-up.prevent="moveHighlight(-1)" @keydown.enter.prevent="addHighlightedItem()" @focus="if(searchQuery) showSearchDropdown = true" @click.away="showSearchDropdown = false" placeholder="Search products... (type to filter, Enter to add)" class="search-glow w-full pl-10 pr-10 py-2.5 rounded-xl text-sm border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-purple-400 transition shadow-sm" autocomplete="one-time-code" name="pos_product_search_nofill" data-lpignore="true" data-form-type="other" role="combobox">
            <kbd x-show="!searchQuery" class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] text-gray-400 bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 rounded border border-gray-200 dark:border-gray-600 font-mono">Ctrl+S</kbd>
            <button x-show="searchQuery" @click="searchQuery = ''; showSearchDropdown = false; filterProducts(); $refs.searchInput.focus()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div x-show="showSearchDropdown && searchSuggestions.length > 0" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-2xl z-50 max-h-64 overflow-y-auto" x-ref="searchDropdown">
                <template x-for="(s, i) in searchSuggestions" :key="s.id + s.type">
                    <button @click="quickAddItem(s)" @mouseenter="highlightIndex = i"
                        :data-hl="i === highlightIndex ? 'true' : 'false'"
                        class="w-full flex items-center gap-3 px-3 py-2.5 text-left"
                        :style="i === highlightIndex ? 'background:#7c3aed !important; border-radius:10px; margin:2px 4px; width:calc(100% - 8px); box-shadow:0 4px 12px rgba(124,58,237,0.4);' : 'margin:2px 4px; width:calc(100% - 8px);'">
                        <template x-if="s.image">
                            <img :src="s.image" class="w-8 h-8 rounded-lg object-cover flex-shrink-0" :style="i === highlightIndex ? 'outline:2px solid white; outline-offset:1px;' : ''">
                        </template>
                        <template x-if="!s.image">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                                :style="i === highlightIndex ? 'background:white; color:#7c3aed;' : 'background:linear-gradient(135deg,#f3e8ff,#ede9fe); color:#7c3aed;'">
                                <span class="text-xs font-bold" x-text="s.name.charAt(0)"></span>
                            </div>
                        </template>
                        <div class="flex-1 min-w-0">
                            <span class="text-sm font-semibold truncate block" :style="i === highlightIndex ? 'color:white;' : 'color:#1f2937;'" x-text="s.name"></span>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[10px]" :style="i === highlightIndex ? 'color:rgba(255,255,255,0.7);' : 'color:#9ca3af;'" x-text="s.type === 'service' ? 'Service' : s.category"></span>
                                <template x-if="s.stockStatus"><span class="stock-dot" :class="'stock-' + s.stockStatus"></span></template>
                            </div>
                        </div>
                        <span class="text-sm font-extrabold" :style="i === highlightIndex ? 'color:white;' : 'color:#9333ea;'" x-text="'Rs. ' + Number(s.price).toLocaleString()"></span>
                    </button>
                </template>
            </div>
        </div>

        <button @click="showTablePicker = true" class="flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-xs font-semibold border transition flex-shrink-0" :class="selectedTable ? 'bg-purple-50 dark:bg-purple-900/20 border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            <span x-text="selectedTable ? 'T-' + selectedTable.table_number : 'Table'"></span>
        </button>

        <div class="flex items-center rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden flex-shrink-0" title="Press F2 to cycle">
            <button @click="orderType = 'dine_in'" class="px-2 py-1.5 text-[10px] font-bold transition-all" :class="orderType === 'dine_in' ? 'bg-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:bg-gray-100'">Dine In</button>
            <button @click="orderType = 'takeaway'" class="px-2 py-1.5 text-[10px] font-bold transition-all border-x border-gray-200 dark:border-gray-700" :class="orderType === 'takeaway' ? 'bg-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:bg-gray-100'">Takeaway</button>
            <button @click="orderType = 'delivery'" class="px-2 py-1.5 text-[10px] font-bold transition-all" :class="orderType === 'delivery' ? 'bg-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:bg-gray-100'">Delivery</button>
            <span class="px-1.5 py-1.5 text-[8px] font-mono text-gray-400 bg-gray-50 dark:bg-gray-800 border-l border-gray-200 dark:border-gray-700">F2</span>
        </div>

        <div class="w-px h-6 bg-gray-200 dark:bg-gray-700 hidden sm:block flex-shrink-0"></div>

        <button @click="priorityOrder = !priorityOrder" class="hidden sm:flex items-center gap-1 px-2.5 py-2 rounded-xl text-xs font-semibold border transition" :class="priorityOrder ? 'bg-red-50 dark:bg-red-900/20 border-red-300 text-red-600' : 'border-gray-200 dark:border-gray-700 text-gray-500 hover:bg-gray-50'">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <span>Rush</span>
        </button>

        <button @click="newSale()" class="flex items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 hover:bg-green-100 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            <span class="hidden sm:inline">New</span>
        </button>

        <button @click="activeHeldIndex = 0; showHeldOrders = !showHeldOrders" class="relative flex items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 hover:bg-amber-100 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-[10px] bg-amber-400/30 px-1 rounded">F3</span>
            <span class="hidden sm:inline">Held</span>
            <span x-show="heldOrders.length > 0" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] rounded-full flex items-center justify-center font-bold" x-text="heldOrders.length"></span>
        </button>

        <div class="hidden md:flex items-center gap-1.5">
            <button @click="holdOrder()" :disabled="cart.length === 0 || submitting" class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold bg-amber-500 hover:bg-amber-600 text-white disabled:opacity-40 shadow-sm transition">
                <svg x-show="submitting" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                <span x-show="!submitting" class="text-[10px] bg-amber-400/30 px-1 rounded">F5</span> <span x-text="submitting ? 'Holding...' : 'Hold'"></span>
            </button>
            <button @click="showPayModal = true" :disabled="cart.length === 0 || submitting" class="flex items-center gap-1.5 px-5 py-2 rounded-xl text-xs font-bold bg-green-600 hover:bg-green-700 text-white disabled:opacity-40 shadow-lg shadow-green-600/20 transition">
                <span x-show="!submitting" class="text-[10px] bg-green-500/30 px-1 rounded">F8</span> Pay
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
                                    <div x-show="!item.image" class="flex flex-col items-center justify-center w-full h-full" :class="'letter-' + (item.name ? item.name.charAt(0).toUpperCase() : 'A')">
                                        <span class="text-4xl font-black text-white/90 select-none drop-shadow-sm" x-text="item.name ? item.name.charAt(0).toUpperCase() : '?'"></span>
                                        <span class="text-[9px] font-semibold text-white/60 mt-0.5 tracking-wider uppercase truncate max-w-[80%]" x-text="item.category || item.type"></span>
                                    </div>
                                    <div class="absolute top-1.5 left-1.5 flex flex-col gap-1">
                                        <template x-if="item.stockStatus === 'available'"><span class="stock-dot stock-available"></span></template>
                                        <template x-if="item.stockStatus === 'low'"><span class="stock-dot stock-low" title="Low stock"></span></template>
                                        <template x-if="item.stockStatus === 'out'"><span class="px-1.5 py-0.5 bg-red-500/90 text-white text-[8px] font-bold rounded-md">OUT</span></template>
                                    </div>
                                    <div class="absolute top-1.5 right-1.5 flex flex-col gap-1">
                                        <template x-if="item.hasRecipe"><span class="px-1.5 py-0.5 bg-orange-500/90 text-white text-[8px] font-bold rounded-md flex items-center gap-0.5"><span class="text-[9px]">&#x1F373;</span> Recipe</span></template>
                                        <template x-if="item.is_tax_exempt"><span class="px-1.5 py-0.5 bg-green-500/90 text-white text-[8px] font-bold rounded-md">NO TAX</span></template>
                                    </div>
                                    <button @click.stop="handleProductClick(item)" class="quick-add absolute bottom-2 right-2 w-9 h-9 rounded-full bg-purple-600 hover:bg-purple-700 text-white shadow-lg shadow-purple-600/30 flex items-center justify-center transition-all">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                                    </button>
                                </div>
                                <div class="px-3 py-2.5">
                                    <p class="text-xs font-bold text-gray-900 dark:text-white truncate leading-tight" x-text="item.name"></p>
                                    <div class="flex items-center justify-between mt-1.5">
                                        <span class="price-badge text-sm font-extrabold text-purple-600 dark:text-purple-400" x-text="'Rs. ' + Number(item.price).toLocaleString()"></span>
                                        <template x-if="getCartQty(item) > 0">
                                            <span class="cart-qty-badge text-[10px] bg-gradient-to-br from-purple-500 to-purple-700 text-white w-6 h-6 rounded-full flex items-center justify-center font-bold shadow-lg shadow-purple-500/30" x-text="getCartQty(item)"></span>
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

            <button x-show="cart.length > 0 && !cartMode" @click="enterCartMode(); mobileView = 'cart';"
                style="position:fixed; bottom:24px; right:400px; z-index:60; background:linear-gradient(135deg,#7c3aed,#6d28d9); color:white; border:none; border-radius:16px; padding:10px 20px; font-size:13px; font-weight:700; cursor:pointer; box-shadow:0 8px 24px rgba(124,58,237,0.4), 0 2px 8px rgba(0,0,0,0.15); display:flex; align-items:center; gap:8px; transition:all 0.2s;"
                x-transition
                title="Jump to Cart & Edit (F6)"
                @mouseenter="this.style.transform='scale(1.05)'; this.style.boxShadow='0 12px 32px rgba(124,58,237,0.5)'"
                @mouseleave="this.style.transform='scale(1)'; this.style.boxShadow='0 8px 24px rgba(124,58,237,0.4)'">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                <span>Edit Cart</span>
                <span style="background:rgba(255,255,255,0.25); padding:2px 8px; border-radius:8px; font-size:11px; font-weight:800;" x-text="cart.length"></span>
                <span style="font-size:10px; opacity:0.7; margin-left:2px;" x-text="'Rs.' + Number(totalAmount).toLocaleString()"></span>
                <span style="background:rgba(255,255,255,0.15); padding:2px 6px; border-radius:6px; font-size:9px; font-weight:700; letter-spacing:0.5px; border:1px solid rgba(255,255,255,0.25);">F6</span>
            </button>
        </div>

        <div class="w-full md:w-[340px] lg:w-[380px] bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-800 flex flex-col flex-shrink-0 shadow-xl" :class="mobileView === 'cart' ? 'flex' : 'hidden md:flex'">
            <div class="flex items-center gap-2 px-3 py-2.5 border-b border-gray-100 dark:border-gray-800">
                <button @click="mobileView = 'menu'" class="md:hidden p-1.5 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                <span class="text-sm font-bold text-gray-900 dark:text-white flex-1">Current Order</span>
                <button x-show="cart.length > 0" @click="enterCartMode()" class="flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold transition-all"
                    :style="cartMode ? 'background:#7c3aed; color:white; box-shadow:0 2px 8px rgba(124,58,237,0.3);' : 'background:#f3e8ff; color:#7c3aed;'"
                    :title="cartMode ? 'Cart Edit Mode ON — ↑↓ navigate, +/- qty, Del remove, Esc exit' : 'Enter Cart Edit Mode'">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    <span x-text="cartMode ? 'Editing' : 'Edit'"></span>
                </button>
                <template x-if="priorityOrder"><span class="text-[10px] bg-red-100 text-red-600 px-2 py-0.5 rounded-full font-bold">RUSH</span></template>
                <span class="text-[10px] bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 px-2 py-0.5 rounded-full font-semibold" x-text="orderType.replace('_', ' ').toUpperCase()"></span>
                <template x-if="selectedTable">
                    <span class="text-[10px] bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded-full font-semibold" x-text="'T-' + selectedTable.table_number"></span>
                </template>
            </div>

            <template x-if="selectedCustomer">
                <div class="px-3 py-2 bg-blue-50 dark:bg-blue-900/10 border-b border-blue-100 dark:border-blue-900/20 flex items-center gap-2">
                    <div class="w-7 h-7 rounded-full bg-blue-200 dark:bg-blue-800 flex items-center justify-center flex-shrink-0"><span class="text-xs font-bold text-blue-700 dark:text-blue-300" x-text="selectedCustomer.name.charAt(0)"></span></div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-blue-800 dark:text-blue-200 truncate" x-text="selectedCustomer.name"></p>
                        <p class="text-[10px] text-blue-600 dark:text-blue-400" x-text="(selectedCustomer.phone || 'No phone') + (selectedCustomer.address ? ' • ' + selectedCustomer.address : '')"></p>
                        <template x-if="customerStats">
                            <p class="text-[10px] text-blue-500 dark:text-blue-500" x-text="customerStats.total_orders + ' orders • Rs. ' + Number(customerStats.total_spent).toLocaleString() + ' spent'"></p>
                        </template>
                    </div>
                    <template x-if="customerStats && customerStats.is_frequent"><span class="freq-badge">VIP</span></template>
                </div>
            </template>

            <div class="flex-1 overflow-y-auto" x-ref="cartList">
                <template x-if="cart.length === 0">
                    <div class="flex flex-col items-center justify-center h-full text-gray-400 py-16">
                        <div class="w-20 h-20 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-4">
                            <svg class="w-10 h-10 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4"/></svg>
                        </div>
                        <p class="text-sm font-medium">Empty order</p>
                        <p class="text-xs mt-1 text-gray-300">Add products to begin</p>
                    </div>
                </template>
                <template x-if="cartMode && cart.length > 0">
                    <div style="background:linear-gradient(90deg,#7c3aed,#6d28d9); padding:6px 12px; display:flex; align-items:center; gap:8px;">
                        <svg class="w-3.5 h-3.5" style="color:white; flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <span style="color:rgba(255,255,255,0.9); font-size:10px; font-weight:600;">↑↓ Navigate &nbsp; +/− Qty &nbsp; 0-9 Set Qty &nbsp; Del Remove &nbsp; Esc Exit</span>
                    </div>
                </template>
                <template x-for="(item, index) in cart" :key="index">
                    <div class="cart-item cart-item-enter px-3 py-2.5 border-b border-gray-100 dark:border-gray-800 cursor-pointer relative"
                        :style="activeCartIndex === index ? 'background:#f3e8ff; outline:2px solid #7c3aed; outline-offset:-2px; border-radius:8px; margin:2px;' : ''"
                        @click="activeCartIndex = index; cartMode = true;" :data-cart-index="index">
                        <div class="flex items-center gap-2.5">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-gray-900 dark:text-white truncate" x-text="item.item_name"></p>
                                <p class="text-[11px] text-gray-400 mt-0.5" x-text="'Rs. ' + Number(item.unit_price).toLocaleString() + '/unit'"></p>
                            </div>
                            <div class="flex items-center gap-0.5 bg-gray-100 dark:bg-gray-800 rounded-xl p-0.5">
                                <button @click.stop="updateQty(index, -1)" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 transition active:scale-90 shadow-sm hover:shadow">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" d="M20 12H4"/></svg>
                                </button>
                                <input type="number" :value="item.quantity" @change="setQty(index, $event.target.value)" @click.stop class="w-14 h-10 text-center text-lg font-extrabold bg-white dark:bg-gray-900 text-gray-900 dark:text-white border-0 rounded-lg focus:ring-2 focus:ring-purple-500 shadow-inner" :class="activeCartIndex === index ? 'qty-pop' : ''" min="0.01" step="1">
                                <button @click.stop="updateQty(index, 1)" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 transition active:scale-90 shadow-sm hover:shadow">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" d="M12 4v16m8-8H4"/></svg>
                                </button>
                            </div>
                            <div class="text-right min-w-[60px]">
                                <p class="text-sm font-extrabold text-gray-900 dark:text-white" x-text="'Rs.' + getItemTotal(item).toLocaleString()"></p>
                            </div>
                            <button @click.stop="removeFromCart(index)" class="p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition active:scale-90">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                        <div class="flex items-center gap-1.5 mt-1.5">
                            <input type="text" x-model="item.special_notes" @click.stop placeholder="Notes..." class="flex-1 text-[11px] bg-gray-50 dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-lg px-2 py-1 text-gray-600 dark:text-gray-400 focus:ring-purple-500 placeholder-gray-300">
                            <button @click.stop="item.showItemDiscount = !item.showItemDiscount" class="text-[9px] font-bold px-1.5 py-1 rounded-md transition whitespace-nowrap" :class="(item.item_discount_value || 0) > 0 ? 'bg-orange-100 text-orange-600' : 'bg-gray-100 dark:bg-gray-700 text-gray-400 hover:text-orange-500'" x-text="(item.item_discount_value || 0) > 0 ? ((item.item_discount_type || 'percentage') === 'percentage' ? '-' + item.item_discount_value + '%' : '-Rs.' + item.item_discount_value) : 'Disc'"></button>
                        </div>
                        <div x-show="item.showItemDiscount" x-transition class="mt-1 flex items-center gap-1">
                            <button @click.stop="item.item_discount_type = 'percentage'" class="text-[9px] font-bold px-1.5 py-0.5 rounded transition" :class="(item.item_discount_type || 'percentage') === 'percentage' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-400'">%</button>
                            <button @click.stop="item.item_discount_type = 'amount'" class="text-[9px] font-bold px-1.5 py-0.5 rounded transition" :class="item.item_discount_type === 'amount' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-400'">Rs</button>
                            <input type="number" x-model.number="item.item_discount_value" @click.stop min="0" step="any" placeholder="0" class="w-14 text-[10px] bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded px-1.5 py-0.5 text-gray-900 dark:text-white focus:ring-purple-500">
                            <button @click.stop="item.item_discount_value = 0; item.showItemDiscount = false" class="text-[9px] text-red-400 hover:text-red-600 px-1">X</button>
                        </div>
                    </div>
                </template>
            </div>

            <div class="border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/80 backdrop-blur-sm">
                <div class="px-3 py-1.5">
                    <textarea x-model="kitchenNotes" rows="1" placeholder="Kitchen notes..." class="w-full text-xs bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-2.5 py-1.5 text-gray-700 dark:text-gray-300 focus:ring-purple-500 resize-none placeholder-gray-300"></textarea>
                </div>
                <div class="px-3 py-1.5">
                    <div class="flex items-center gap-1.5">
                        <button @click="showDiscount = !showDiscount" class="text-[10px] font-semibold px-2 py-0.5 rounded-lg transition" :class="discountAmount > 0 ? 'bg-orange-100 dark:bg-orange-900/20 text-orange-600' : 'bg-gray-100 dark:bg-gray-800 text-gray-500 hover:bg-gray-200'">
                            <span x-text="discountAmount > 0 ? 'Discount: -Rs. ' + Number(discountAmount).toLocaleString() : '+ Discount'"></span>
                        </button>
                        <span class="text-[8px] text-gray-400" x-text="'Limit: ' + effectiveDiscountLimit + '%'"></span>
                        <button x-show="!managerOverrideActive && hasManagerPin && posRole !== 'pos_admin'" @click="requestManagerOverride()" class="text-[8px] font-bold text-blue-600 hover:text-blue-800 px-1">Override</button>
                        <span x-show="managerOverrideActive" class="text-[8px] font-bold text-green-600 px-1">Unlocked</span>
                    </div>
                    <div x-show="showDiscount" x-transition class="mt-1.5 p-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl space-y-1.5">
                        <div class="flex gap-1">
                            <button @click="discountType = 'percentage'" class="flex-1 text-[10px] font-bold py-1 rounded-lg transition" :class="discountType === 'percentage' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-500'">%</button>
                            <button @click="discountType = 'amount'" class="flex-1 text-[10px] font-bold py-1 rounded-lg transition" :class="discountType === 'amount' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-500'">Rs.</button>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <input type="number" x-model.number="discountValue" @input="if(!checkDiscountLimit(discountValue, discountType)) { discountValue = discountType === 'percentage' ? effectiveDiscountLimit : r2(effectiveSubtotal * effectiveDiscountLimit / 100); showToast('Discount capped at ' + effectiveDiscountLimit + '%', 'error'); } recalcDiscount()" min="0" :max="discountType === 'percentage' ? effectiveDiscountLimit : r2(effectiveSubtotal * effectiveDiscountLimit / 100)" step="any" :placeholder="discountType === 'percentage' ? 'Max ' + effectiveDiscountLimit + '%' : 'e.g. 500'" class="flex-1 text-xs bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg px-2 py-1.5 text-gray-900 dark:text-white focus:ring-purple-500">
                            <button @click="discountValue = 0; recalcDiscount(); showDiscount = false" class="text-[10px] text-red-500 hover:text-red-700 px-1.5">Clear</button>
                        </div>
                        <div class="flex gap-1 flex-wrap">
                            <template x-for="q in [5, 10, 15, 20].filter(v => v <= effectiveDiscountLimit)" :key="q">
                                <button @click="discountType = 'percentage'; discountValue = q; recalcDiscount()" class="text-[9px] font-semibold px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-md hover:bg-purple-100 hover:text-purple-700 transition" x-text="q + '%'"></button>
                            </template>
                        </div>
                    </div>
                </div>
                <div class="px-3 py-2 space-y-1">
                    <div class="flex justify-between text-xs text-gray-500"><span>Subtotal</span><span x-text="'Rs. ' + Number(subtotal).toLocaleString()"></span></div>
                    <div x-show="itemDiscountsTotal > 0" class="flex justify-between text-xs text-orange-500">
                        <span>Item Discounts</span>
                        <span x-text="'-Rs. ' + Number(itemDiscountsTotal).toLocaleString()"></span>
                    </div>
                    <div x-show="discountAmount > 0" class="flex justify-between text-xs text-orange-600 dark:text-orange-400">
                        <span x-text="discountType === 'percentage' ? 'Order Discount (' + discountValue + '%)' : 'Order Discount'"></span>
                        <span x-text="'-Rs. ' + Number(discountAmount).toLocaleString()"></span>
                    </div>
                    <div x-show="exemptAmount > 0" class="flex justify-between text-xs text-green-600 dark:text-green-400"><span>Tax-Exempt</span><span x-text="'-Rs. ' + Number(exemptAmount).toLocaleString()"></span></div>
                    <div class="flex justify-between text-xs text-gray-500"><span x-text="'Tax (' + taxRate + '%)'"></span><span x-text="'Rs. ' + Number(taxAmount).toLocaleString()"></span></div>
                    <div class="flex justify-between text-lg font-extrabold text-gray-900 dark:text-white pt-1.5 border-t border-gray-200 dark:border-gray-700">
                        <span>Total</span>
                        <span class="total-animate" x-text="'Rs. ' + Number(totalAmount).toLocaleString()" :class="cartAnimating ? 'cart-pop' : ''" :style="totalAmount > 0 ? 'color: #059669' : ''"></span>
                    </div>
                    <div x-show="posRole === 'pos_admin' && getCartCost() > 0" class="flex justify-between text-[10px] text-gray-400 pt-0.5">
                        <span>Est. Cost</span><span x-text="'Rs. ' + r2(getCartCost()).toLocaleString()"></span>
                    </div>
                    <div x-show="posRole === 'pos_admin' && getCartCost() > 0" class="flex justify-between text-[10px] font-semibold" :class="(totalAmount - getCartCost()) >= 0 ? 'text-green-600' : 'text-red-500'">
                        <span>Est. Profit</span><span x-text="'Rs. ' + r2(totalAmount - getCartCost()).toLocaleString()"></span>
                    </div>
                </div>
                <div class="px-3 pb-3 space-y-2 mobile-sticky-pay">
                    <div class="grid grid-cols-3 gap-2">
                        <button @click="if(cart.length && confirm('Clear entire cart?')) { clearCart(); }" :disabled="cart.length === 0" class="py-2 text-xs font-bold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded-xl border border-red-200 dark:border-red-800 hover:bg-red-100 disabled:opacity-30 transition flex items-center justify-center gap-0.5">Clear <kbd class="text-[8px] bg-red-200/50 dark:bg-red-800/30 px-1 rounded font-mono">F4</kbd></button>
                        <button @click="holdOrder()" :disabled="cart.length === 0 || submitting" class="py-2 text-xs font-bold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-800 hover:bg-amber-100 disabled:opacity-30 transition flex items-center justify-center gap-1">
                            <svg x-show="submitting" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span x-text="submitting ? 'Holding...' : 'Hold'"></span>
                            <kbd x-show="!submitting" class="text-[8px] bg-amber-200/50 dark:bg-amber-800/30 px-1 rounded ml-0.5 font-mono">F5</kbd>
                        </button>
                        <button @click="showHeldOrders = !showHeldOrders" class="relative py-2 text-xs font-bold text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 rounded-xl border border-purple-200 dark:border-purple-800 hover:bg-purple-100 transition flex items-center justify-center gap-0.5">
                            Recall <kbd class="text-[8px] bg-purple-200/50 dark:bg-purple-800/30 px-1 rounded font-mono">F3</kbd>
                            <span x-show="heldOrders.length > 0" class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center held-badge-pulse shadow-sm shadow-red-500/50" x-text="heldOrders.length"></span>
                        </button>
                    </div>
                    <button @click="showPayModal = true" :disabled="cart.length === 0 || submitting" class="btn-ripple w-full py-3.5 rounded-xl text-sm font-extrabold text-white bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 disabled:opacity-30 shadow-lg shadow-green-600/25 transition-all transform hover:scale-[1.01] active:scale-[0.98]">
                        <span class="flex items-center justify-center gap-2">
                            <svg x-show="submitting" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <svg x-show="!submitting" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            PAY Rs. <span x-text="Number(totalAmount).toLocaleString()"></span>
                            <kbd x-show="!submitting" class="text-[9px] bg-green-500/30 px-1.5 rounded font-mono">F8</kbd>
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
                <button @click="processPayment('cash')" :disabled="submitting" class="py-4 rounded-xl text-center bg-green-50 dark:bg-green-900/20 border-2 border-green-200 dark:border-green-800 hover:bg-green-100 hover:border-green-400 transition disabled:opacity-50 group">
                    <svg x-show="submitting" class="w-8 h-8 mx-auto text-green-600 mb-1 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <svg x-show="!submitting" class="w-8 h-8 mx-auto text-green-600 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span class="text-sm font-bold text-green-700 dark:text-green-400" x-text="submitting ? 'Processing...' : 'Cash'"></span>
                    <span class="block text-[10px] font-semibold text-green-600/60 mt-0.5" x-text="'Tax: ' + (taxRules['cash'] || 16) + '%'"></span>
                    <kbd x-show="!submitting" class="block mt-0.5 text-[9px] text-green-500/60 font-mono">Press 1</kbd>
                </button>
                <button @click="processPayment('card')" :disabled="submitting" class="py-4 rounded-xl text-center bg-blue-50 dark:bg-blue-900/20 border-2 border-blue-200 dark:border-blue-800 hover:bg-blue-100 hover:border-blue-400 transition disabled:opacity-50 group">
                    <svg x-show="submitting" class="w-8 h-8 mx-auto text-blue-600 mb-1 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <svg x-show="!submitting" class="w-8 h-8 mx-auto text-blue-600 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    <span class="text-sm font-bold text-blue-700 dark:text-blue-400" x-text="submitting ? 'Processing...' : 'Card'"></span>
                    <span class="block text-[10px] font-semibold text-blue-600/60 mt-0.5" x-text="'Tax: ' + (taxRules['debit_card'] || taxRules['card'] || 5) + '%'"></span>
                    <kbd x-show="!submitting" class="block mt-0.5 text-[9px] text-blue-500/60 font-mono">Press 2</kbd>
                </button>
            </div>
            <div class="p-4 pt-0">
                <button @click="showPayModal = false" :disabled="submitting" class="w-full py-2.5 rounded-xl text-sm font-semibold text-gray-500 hover:text-gray-700 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 transition disabled:opacity-50">Cancel <span class="text-[9px] text-gray-400 font-mono ml-1">ESC</span></button>
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
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">Held Orders</h3>
                    <p class="text-[10px] text-gray-400 mt-0.5">Arrow keys to navigate • Enter=Recall • P=Pay • D=Delete • ESC=Close</p>
                </div>
                <button @click="showHeldOrders = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="max-h-[60vh] overflow-y-auto">
                <template x-if="heldOrders.length === 0">
                    <div class="p-8 text-center text-gray-400"><p class="text-sm">No held orders</p></div>
                </template>
                <template x-for="(order, oi) in heldOrders" :key="order.id">
                    <div class="p-4 border-b border-gray-100 dark:border-gray-800 transition-all" :class="activeHeldIndex === oi ? 'bg-purple-50 dark:bg-purple-900/15 ring-2 ring-purple-400 ring-inset' : ''">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-mono text-gray-400 w-5" x-text="oi + 1"></span>
                                <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="order.order_number"></span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <template x-if="order.customer_name"><span class="text-[9px] bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded-full font-medium" x-text="order.customer_name"></span></template>
                                <template x-if="order.priority"><span class="text-[9px] bg-red-100 text-red-600 px-1.5 py-0.5 rounded-full font-bold">RUSH</span></template>
                                <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="{'bg-amber-100 text-amber-700': order.status==='held', 'bg-blue-100 text-blue-700': order.status==='preparing', 'bg-green-100 text-green-700': order.status==='ready'}" x-text="order.status"></span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mb-1 ml-7" x-text="'Rs. ' + Number(order.total_amount).toLocaleString() + ' • ' + order.items.length + ' item(s)'"></p>
                        <template x-if="order.table"><p class="text-[10px] text-purple-600 ml-7" x-text="'Table: T-' + order.table.table_number"></p></template>
                        <div class="flex gap-2 mt-2 ml-7">
                            <button @click="recallOrder(order)" class="flex-1 py-2 text-xs font-bold text-purple-600 border border-purple-300 rounded-xl hover:bg-purple-50 transition">Recall</button>
                            <a :href="'/pos/restaurant/orders/' + order.id + '/kitchen-ticket'" target="_blank" class="py-2 px-3 text-xs font-bold text-center text-orange-600 border border-orange-300 rounded-xl hover:bg-orange-50 transition">KOT</a>
                            <button @click="payHeldOrder(order.id)" class="flex-1 py-2 text-xs font-bold text-white bg-green-600 rounded-xl hover:bg-green-700 transition">Pay</button>
                            <button @click="deleteHeldOrder(order.id)" class="py-2 px-3 text-xs font-bold text-red-500 border border-red-300 rounded-xl hover:bg-red-50 transition">Delete</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <div x-show="showNewCustomerModal" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showNewCustomerModal = false" @keydown.escape.window="if(showNewCustomerModal) showNewCustomerModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-base font-bold text-gray-900 dark:text-white">New Customer</h3>
                <button @click="showNewCustomerModal = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-4 space-y-3">
                <div>
                    <label class="text-[10px] font-medium text-gray-500 block mb-1">Mobile Number</label>
                    <input type="text" :value="newCustomerPhone" disabled class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-800 text-sm px-3 py-2 text-gray-600 dark:text-gray-400">
                </div>
                <div>
                    <label class="text-[10px] font-medium text-gray-500 block mb-1">Customer Name <span class="text-red-500">*</span></label>
                    <input type="text" x-ref="newCustomerNameInput" x-model="newCustomerName" @keydown.enter.prevent="saveNewCustomer()" placeholder="Enter customer name" class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-400">
                </div>
                <div>
                    <label class="text-[10px] font-medium text-gray-500 block mb-1">Address <span class="text-gray-400">(optional)</span></label>
                    <input type="text" x-model="newCustomerAddress" @keydown.enter.prevent="saveNewCustomer()" placeholder="Delivery address" class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-400">
                </div>
                <div class="flex gap-2 pt-1">
                    <button @click="showNewCustomerModal = false" class="flex-1 py-2.5 text-xs font-semibold text-gray-500 bg-gray-100 dark:bg-gray-800 rounded-lg hover:bg-gray-200 transition">Cancel</button>
                    <button @click="saveNewCustomer()" class="flex-1 py-2.5 text-xs font-bold text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition">Save & Select</button>
                </div>
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
                            <div class="w-8 h-8 rounded-full bg-green-200 dark:bg-green-800 flex items-center justify-center flex-shrink-0"><span class="text-xs font-bold text-green-700" x-text="customerLookupResult.customer.name.charAt(0)"></span></div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-green-800 dark:text-green-200" x-text="customerLookupResult.customer.name"></p>
                                <p class="text-[10px] text-green-600" x-text="customerLookupResult.stats.total_orders + ' orders • Rs. ' + Number(customerLookupResult.stats.total_spent).toLocaleString() + ' spent'"></p>
                                <template x-if="customerLookupResult.customer.address">
                                    <p class="text-[10px] text-green-500 truncate" x-text="'📍 ' + customerLookupResult.customer.address"></p>
                                </template>
                            </div>
                            <template x-if="customerLookupResult.stats.is_frequent"><span class="freq-badge">VIP</span></template>
                            <button @click="selectLookedUpCustomer()" class="px-3 py-1 text-xs font-bold text-white bg-green-600 rounded-lg flex-shrink-0">Select</button>
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
                    <div class="w-full flex items-center gap-3 px-4 py-3 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition border-b border-gray-50 dark:border-gray-800">
                        <button @click="selectCustomerWithStats(c)" class="flex items-center gap-3 flex-1 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center flex-shrink-0"><span class="text-sm font-bold text-purple-600 dark:text-purple-400" x-text="c.name.charAt(0)"></span></div>
                            <div class="text-left min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate" x-text="c.name"></p>
                                <p class="text-xs text-gray-400" x-text="c.phone || 'No phone'"></p>
                                <template x-if="c.address"><p class="text-[10px] text-gray-400 truncate" x-text="'📍 ' + c.address"></p></template>
                            </div>
                        </button>
                        <button @click="loadCustomerHistory(c.id)" class="flex-shrink-0 text-[9px] font-bold text-purple-600 hover:text-purple-800 bg-purple-50 dark:bg-purple-900/30 px-2 py-1 rounded-lg transition" title="View history">
                            <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </button>
                    </div>
                </template>
            </div>
            <div class="p-3 border-t border-gray-200 dark:border-gray-700">
                <div x-show="!showQuickAdd">
                    <button @click="showQuickAdd = true" class="w-full py-2.5 text-sm font-bold text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 rounded-xl hover:bg-purple-100 transition">+ Add New Customer</button>
                </div>
                <div x-show="showQuickAdd" class="space-y-2">
                    <input type="text" x-model="quickCustomerName" placeholder="Customer name *" class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-purple-500">
                    <input type="text" x-model="quickCustomerPhone" placeholder="Phone *" class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-purple-500">
                    <input type="text" x-model="quickCustomerAddress" placeholder="Address (for delivery)" class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-purple-500">
                    <div class="flex gap-2">
                        <button @click="showQuickAdd = false" class="flex-1 py-2 text-xs font-semibold text-gray-500 bg-gray-100 dark:bg-gray-800 rounded-xl">Cancel</button>
                        <button @click="addQuickCustomer()" class="flex-1 py-2 text-xs font-bold text-white bg-purple-600 rounded-xl hover:bg-purple-700">Save</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div x-show="showReceipt" x-transition.opacity @keydown.escape.window="if(showReceipt) { showReceipt = false; }" @click.self="showReceipt = false" class="fixed inset-0 bg-gradient-to-br from-green-900/80 via-black/70 to-emerald-900/80 backdrop-blur-md z-50 flex items-center justify-center p-4">
        <div class="receipt-modal-enter bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col" style="max-height:92vh;" x-transition.scale.90>
            <div class="relative p-5 text-center bg-gradient-to-b from-green-50 to-white dark:from-green-900/20 dark:to-gray-900 flex-shrink-0" id="confettiContainer">
                <div class="w-16 h-16 mx-auto rounded-full bg-gradient-to-br from-green-400 to-emerald-600 flex items-center justify-center mb-3 shadow-lg shadow-green-600/30 success-icon-animate" style="animation: scaleIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1)">
                    <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-dasharray="24" stroke-dashoffset="0" style="animation: checkDraw 0.5s ease 0.3s both;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                </div>
                <h3 class="text-xl font-extrabold text-gray-900 dark:text-white tracking-tight">Payment Complete!</h3>
                <div class="flex items-center justify-center gap-3 mt-2">
                    <span class="text-xs font-mono text-gray-400 dark:text-gray-500" x-text="lastInvoiceNumber"></span>
                    <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full" :class="lastPaymentMethod === 'cash' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'">
                        <span class="w-1.5 h-1.5 rounded-full" :class="lastPaymentMethod === 'cash' ? 'bg-green-500' : 'bg-blue-500'"></span>
                        <span x-text="lastPaymentMethod"></span>
                    </span>
                </div>
                <div class="mt-2 py-2 px-4 bg-green-50 dark:bg-green-900/20 rounded-xl border border-green-200 dark:border-green-800/50 inline-block">
                    <p class="text-2xl font-extrabold text-green-600 dark:text-green-400" x-text="'Rs. ' + Number(lastTotal).toLocaleString()" style="font-variant-numeric: tabular-nums;"></p>
                </div>
            </div>
            <div class="flex-1 overflow-hidden bg-gray-50 dark:bg-gray-800/50 min-h-0" style="max-height: 45vh;">
                <iframe x-ref="receiptIframe" class="w-full h-full border-0" :src="lastTransactionId ? '/pos/restaurant/receipt/' + lastTransactionId : ''" style="min-height:300px;"></iframe>
            </div>
            <div class="p-3 space-y-2 bg-white dark:bg-gray-900 border-t border-gray-100 dark:border-gray-800 flex-shrink-0">
                <div class="grid grid-cols-3 gap-2">
                    <button @click="printReceipt()" class="py-3 text-center rounded-xl bg-gradient-to-br from-purple-600 to-violet-700 hover:from-purple-700 hover:to-violet-800 text-white text-sm font-bold transition shadow-md shadow-purple-600/20 flex items-center justify-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        Print <kbd class="text-[8px] bg-purple-500/40 px-1 rounded font-mono">P</kbd>
                    </button>
                    <button @click="startNewAfterPayment()" class="py-3 text-center rounded-xl bg-gradient-to-br from-green-600 to-emerald-700 hover:from-green-700 hover:to-emerald-800 text-white text-sm font-bold transition shadow-md shadow-green-600/20 flex items-center justify-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                        New <kbd class="text-[8px] bg-green-500/40 px-1 rounded font-mono">Enter</kbd>
                    </button>
                    <button @click="showReceipt = false" class="py-3 text-center rounded-xl bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300 text-sm font-semibold transition flex items-center justify-center gap-1.5">
                        Close <kbd class="text-[8px] bg-gray-300 dark:bg-gray-600 px-1 rounded font-mono">Esc</kbd>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Manager PIN Modal --}}
    <div x-show="showManagerPinModal" x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xs overflow-hidden" @click.outside="showManagerPinModal = false">
            <div class="p-5 text-center">
                <div class="w-12 h-12 mx-auto rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center mb-3">
                    <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <h3 class="text-lg font-extrabold text-gray-900 dark:text-white">Manager Override</h3>
                <p class="text-xs text-gray-500 mt-1">Enter manager PIN to unlock full discount</p>
            </div>
            <div class="px-5 pb-5 space-y-3">
                <input type="password" x-model="managerPin" @keydown.enter="submitManagerPin()" maxlength="6" placeholder="Enter PIN" class="w-full text-center text-2xl tracking-[0.5em] bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-gray-900 dark:text-white focus:ring-blue-500 focus:border-blue-500" autofocus>
                <p x-show="managerPinError" class="text-xs text-red-500 text-center" x-text="managerPinError"></p>
                <div class="flex gap-2">
                    <button @click="showManagerPinModal = false" class="flex-1 py-2.5 text-sm font-semibold text-gray-600 bg-gray-100 dark:bg-gray-800 dark:text-gray-400 rounded-xl hover:bg-gray-200 transition">Cancel</button>
                    <button @click="submitManagerPin()" class="flex-1 py-2.5 text-sm font-bold text-white bg-blue-600 rounded-xl hover:bg-blue-700 transition">Verify</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Customer History Modal --}}
    <div x-show="showCustomerHistory" x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[80vh] overflow-hidden flex flex-col" @click.outside="showCustomerHistory = false">
            <div class="p-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-extrabold text-gray-900 dark:text-white">Customer History</h3>
                    <p class="text-xs text-gray-500" x-text="customerHistory?.customer_name || ''"></p>
                </div>
                <button @click="showCustomerHistory = false" class="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-4">
                <template x-if="loadingCustomerHistory">
                    <div class="text-center py-8"><div class="w-6 h-6 border-2 border-purple-600 border-t-transparent rounded-full animate-spin mx-auto"></div><p class="text-xs text-gray-400 mt-2">Loading...</p></div>
                </template>
                <template x-if="customerHistory && !loadingCustomerHistory">
                    <div>
                        <div class="flex items-center gap-3 p-3 bg-purple-50 dark:bg-purple-900/20 rounded-xl mb-4">
                            <div class="w-10 h-10 rounded-full bg-purple-200 dark:bg-purple-800 flex items-center justify-center"><span class="text-sm font-bold text-purple-700 dark:text-purple-300" x-text="(customerHistory.customer_name || 'C').charAt(0)"></span></div>
                            <div>
                                <p class="text-sm font-bold text-gray-900 dark:text-white" x-text="customerHistory.customer_name"></p>
                                <p class="text-[10px] text-gray-500"><span x-text="customerHistory.total_orders"></span> orders &bull; Rs. <span x-text="Number(customerHistory.total_spent || 0).toLocaleString()"></span> spent</p>
                            </div>
                            <span x-show="customerHistory.total_orders >= 5" class="ml-auto text-[9px] font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">VIP</span>
                        </div>

                        <template x-if="customerHistory.favorites && customerHistory.favorites.length > 0">
                            <div class="mb-4">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Favorites</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="fav in customerHistory.favorites" :key="fav.name">
                                        <span class="text-[10px] px-2 py-1 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-lg font-medium" x-text="fav.name + ' (' + fav.count + 'x)'"></span>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-if="customerHistory.recent_orders && customerHistory.recent_orders.length > 0">
                            <div>
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Recent Orders</p>
                                <div class="space-y-2">
                                    <template x-for="ord in customerHistory.recent_orders" :key="ord.id">
                                        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-xl">
                                            <div class="flex items-center justify-between mb-1.5">
                                                <span class="text-xs font-bold text-gray-900 dark:text-white" x-text="ord.order_number"></span>
                                                <span class="text-[10px] text-gray-400" x-text="ord.date"></span>
                                            </div>
                                            <div class="text-[10px] text-gray-500 mb-2" x-text="ord.items.map(i => i.qty + 'x ' + i.name).join(', ')"></div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs font-bold text-purple-600" x-text="'Rs. ' + Number(ord.total).toLocaleString()"></span>
                                                <button @click="reorderItems(ord)" class="text-[10px] font-bold text-white bg-purple-600 hover:bg-purple-700 px-2.5 py-1 rounded-lg transition">Reorder</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Low Stock Alert Popup --}}
    <div x-show="showLowStockPopup && lowStockAlerts.length > 0" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" @click.outside="showLowStockPopup = false">
            <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border-b border-amber-200 dark:border-amber-800 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-amber-900 dark:text-amber-200">Low Stock Warning</h3>
                    <p class="text-[10px] text-amber-700 dark:text-amber-400" x-text="lowStockAlerts.length + ' ingredient(s) running low'"></p>
                </div>
            </div>
            <div class="max-h-[40vh] overflow-y-auto p-3 space-y-1.5">
                <template x-for="alert in lowStockAlerts" :key="alert.name">
                    <div class="flex items-center justify-between px-3 py-2 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div>
                            <p class="text-xs font-semibold text-gray-900 dark:text-white" x-text="alert.name"></p>
                            <p class="text-[10px] text-gray-400" x-text="'Min: ' + alert.min_stock_level + ' ' + alert.unit"></p>
                        </div>
                        <span class="text-xs font-bold" :class="parseFloat(alert.current_stock) <= 0 ? 'text-red-600' : 'text-amber-600'" x-text="alert.current_stock + ' ' + alert.unit"></span>
                    </div>
                </template>
            </div>
            <div class="p-3 border-t border-gray-100 dark:border-gray-800">
                <button @click="showLowStockPopup = false" class="w-full py-2.5 text-sm font-bold text-amber-700 bg-amber-50 dark:bg-amber-900/20 dark:text-amber-400 rounded-xl hover:bg-amber-100 transition">Dismiss</button>
            </div>
        </div>
    </div>

    <div x-show="toast.show" class="fixed top-4 right-4 z-[60] max-w-sm" :class="toast.show ? 'toast-enter' : 'toast-exit'">
        <div class="flex items-center gap-3 px-4 py-3 rounded-2xl shadow-2xl backdrop-blur-xl border" :class="toast.type === 'success' ? 'bg-green-600/95 text-white border-green-500/30' : 'bg-red-600/95 text-white border-red-500/30'" style="box-shadow: 0 20px 60px -15px rgba(0,0,0,0.3);">
            <div class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center" :class="toast.type === 'success' ? 'bg-white/20' : 'bg-white/20'">
                <svg x-show="toast.type === 'success'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                <svg x-show="toast.type !== 'success'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </div>
            <span class="text-sm font-semibold" x-text="toast.message"></span>
        </div>
    </div>
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
        taxRules: @json($taxRules->mapWithKeys(fn($r) => [$r->payment_method => (float) $r->tax_rate])),
        posRole: '{{ $posRole }}',
        discountLimit: {{ $discountLimit }},
        hasManagerPin: {{ $hasManagerPin ? 'true' : 'false' }},
        managerOverrideActive: false,
        showManagerPinModal: false,
        managerPin: '',
        managerPinError: '',
        ingredientCosts: @json($ingredientCosts ?? []),
        lowStockAlerts: @json($lowStockAlerts ?? []),
        showLowStockPopup: {{ ($lowStockAlerts ?? collect())->count() > 0 ? 'true' : 'false' }},
        customerHistory: null,
        showCustomerHistory: false,
        loadingCustomerHistory: false,
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
        quickCustomerAddress: '',
        selectedCustomer: null,
        customerPhoneQuery: '',
        customerPhoneResults: [],
        customerPhoneDropdown: false,
        customerPhoneTimer: null,
        showNewCustomerModal: false,
        newCustomerPhone: '',
        newCustomerName: '',
        newCustomerAddress: '',
        highlightIndex: 0,
        activeCartIndex: -1,
        cartMode: false,
        qtyInputBuffer: '',
        qtyInputTimer: null,
        activeHeldIndex: 0,
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
        recalledOrderId: null,
        toast: { show: false, message: '', type: 'success' },
        lastHoldTime: 0,
        lastPayTime: 0,
        showDiscount: false,
        discountType: 'percentage',
        discountValue: 0,
        discountAmount: 0,

        get filteredCustomers() {
            const q = this.customerSearch.toLowerCase();
            if (!q) return this.allCustomers;
            return this.allCustomers.filter(c => c.name.toLowerCase().includes(q) || (c.phone && c.phone.includes(q)));
        },

        r2(v) { return Math.round((v + Number.EPSILON) * 100) / 100; },
        getItemDiscount(item) {
            const lineTotal = this.r2(item.quantity * item.unit_price);
            const dv = parseFloat(item.item_discount_value) || 0;
            if (dv <= 0) return 0;
            if ((item.item_discount_type || 'percentage') === 'percentage') return this.r2(lineTotal * Math.min(100, dv) / 100);
            return this.r2(Math.min(lineTotal, dv));
        },
        getItemTotal(item) { return Math.max(0, this.r2(item.quantity * item.unit_price - this.getItemDiscount(item))); },
        get itemDiscountsTotal() { return this.r2(this.cart.reduce((s, i) => s + this.getItemDiscount(i), 0)); },
        get subtotal() { return this.r2(this.cart.reduce((s, i) => s + (i.quantity * i.unit_price), 0)); },
        get effectiveSubtotal() { return Math.max(0, this.r2(this.subtotal - this.itemDiscountsTotal)); },
        get taxableSubtotal() {
            const taxable = this.cart.filter(i => !i.is_tax_exempt).reduce((s, i) => s + this.getItemTotal(i), 0);
            const discountRatio = this.effectiveSubtotal > 0 ? (this.effectiveSubtotal - this.discountAmount) / this.effectiveSubtotal : 1;
            return Math.max(0, this.r2(taxable * Math.max(0, discountRatio)));
        },
        get taxAmount() { return this.r2(this.taxableSubtotal * this.taxRate / 100); },
        get totalAmount() { return Math.max(0, this.r2(this.effectiveSubtotal - this.discountAmount + this.taxAmount)); },
        get exemptAmount() { return this.cart.filter(i => i.is_tax_exempt).reduce((s, i) => s + this.getItemTotal(i), 0); },
        recalcDiscount() {
            if (!this.discountValue || this.discountValue <= 0) { this.discountAmount = 0; return; }
            if (this.discountType === 'percentage') {
                const pct = Math.min(100, Math.max(0, this.discountValue));
                this.discountAmount = this.r2(this.effectiveSubtotal * pct / 100);
            } else {
                this.discountAmount = this.r2(Math.min(this.effectiveSubtotal, Math.max(0, this.discountValue)));
            }
        },

        init() {
            this.filterProducts();
            setTimeout(() => { this.loading = false; }, 300);
            this.$watch('activeCategory', () => { this.filterProducts(); this.gridFocusIndex = 0; });
            this.calcGridCols();
            window.addEventListener('resize', () => this.calcGridCols());
            this.restoreCart();
            this.$watch('cart', () => { this.saveCart(); this.recalcDiscount(); }, { deep: true });
            this.$watch('kitchenNotes', () => { this.saveCart(); });
            this.cacheProductData();
            document.addEventListener('keydown', (e) => {
                const tag = document.activeElement?.tagName;
                const isInput = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';

                if (this.showReceipt) {
                    if (e.key === 'Escape') { e.preventDefault(); this.showReceipt = false; return; }
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.startNewAfterPayment(); return; }
                    if (e.key === 'p' || e.key === 'P') { e.preventDefault(); this.printReceipt(); return; }
                    return;
                }
                if (this.showPayModal) {
                    if (e.key === '1') { e.preventDefault(); this.processPayment('cash'); return; }
                    if (e.key === '2') { e.preventDefault(); this.processPayment('card'); return; }
                    if (e.key === 'Escape') { e.preventDefault(); this.showPayModal = false; return; }
                    return;
                }
                if (this.showHeldOrders && this.heldOrders.length > 0) {
                    if (e.key === 'ArrowDown') { e.preventDefault(); this.activeHeldIndex = Math.min(this.activeHeldIndex + 1, this.heldOrders.length - 1); return; }
                    if (e.key === 'ArrowUp') { e.preventDefault(); this.activeHeldIndex = Math.max(this.activeHeldIndex - 1, 0); return; }
                    if (e.key === 'Enter') { e.preventDefault(); this.recallOrder(this.heldOrders[this.activeHeldIndex]); return; }
                    if (e.key === 'p' || e.key === 'P') { e.preventDefault(); this.payHeldOrder(this.heldOrders[this.activeHeldIndex].id); return; }
                    if (e.key === 'd' || e.key === 'D') { e.preventDefault(); this.deleteHeldOrder(this.heldOrders[this.activeHeldIndex].id); return; }
                    if (e.key === 'Escape') { e.preventDefault(); this.showHeldOrders = false; return; }
                    return;
                }
                if (this.showManagerPinModal) {
                    if (e.key === 'Escape') { e.preventDefault(); this.showManagerPinModal = false; return; }
                    return;
                }

                if (e.key === 'F2') { e.preventDefault(); const types = ['dine_in', 'takeaway', 'delivery']; const idx = types.indexOf(this.orderType); this.orderType = types[(idx + 1) % types.length]; return; }
                if (e.key === 'F3') { e.preventDefault(); this.activeHeldIndex = 0; this.showHeldOrders = true; return; }
                if (e.key === 'F4') { e.preventDefault(); if (this.cart.length && confirm('Clear entire cart?')) { this.clearCart(); } return; }
                if (e.key === 'F5') { e.preventDefault(); this.holdOrder(); return; }
                if (e.key === 'F6') { e.preventDefault(); if (this.cart.length > 0) { this.enterCartMode(); this.mobileView = 'cart'; } return; }
                if (e.key === 'F8') { e.preventDefault(); if (this.cart.length) this.showPayModal = true; return; }
                if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); this.enterSearchMode(); return; }
                if ((e.ctrlKey || e.metaKey) && e.key === 'e') { e.preventDefault(); if (this.cart.length > 0) { this.enterCartMode(); this.mobileView = 'cart'; } return; }
                if ((e.ctrlKey || e.metaKey) && e.key === 'c') { if (!window.getSelection().toString()) { e.preventDefault(); this.$refs.customerPhoneInput?.focus(); this.$refs.customerPhoneInput?.select(); return; } }
                if (e.key === 'Escape') {
                    if (this.showNewCustomerModal) { this.showNewCustomerModal = false; return; }
                    if (this.showLowStockPopup) { this.showLowStockPopup = false; return; }
                    if (this.showHeldOrders) { this.showHeldOrders = false; return; }
                    if (this.showTablePicker) { this.showTablePicker = false; return; }
                    if (this.showCustomerPicker) { this.showCustomerPicker = false; return; }
                    if (this.showCustomerHistory) { this.showCustomerHistory = false; return; }
                    if (this.customerPhoneDropdown) { this.customerPhoneDropdown = false; return; }
                    if (this.gridFocusMode) { this.enterSearchMode(); return; }
                    if (this.searchQuery) { this.searchQuery = ''; this.showSearchDropdown = false; this.filterProducts(); return; }
                    if (this.activeCategory !== 'all') { this.activeCategory = 'all'; this.filterProducts(); return; }
                }

                if (this.cartMode && this.cart.length > 0) {
                    const ci = this.activeCartIndex;
                    if (e.key === 'ArrowDown') { e.preventDefault(); this.moveCartSelection(1); return; }
                    if (e.key === 'ArrowUp') { e.preventDefault(); this.moveCartSelection(-1); return; }
                    if ((e.key === '+' || e.key === '=') && ci >= 0) { e.preventDefault(); this.updateQty(ci, 1); this.animateQty(ci); return; }
                    if (e.key === '-' && ci >= 0) { e.preventDefault(); this.updateQty(ci, -1); this.animateQty(ci); return; }
                    if (e.key === 'Delete' && ci >= 0) { e.preventDefault(); this.removeFromCart(ci); if (this.cart.length === 0) { this.cartMode = false; this.activeCartIndex = -1; } else { this.activeCartIndex = Math.min(ci, this.cart.length - 1); } return; }
                    if (e.key === 'Escape') { e.preventDefault(); this.cartMode = false; this.activeCartIndex = -1; return; }
                    if (/^[0-9]$/.test(e.key) && ci >= 0 && !e.ctrlKey && !e.metaKey) { e.preventDefault(); this.handleQtyDigit(e.key, ci); return; }
                    if (/^[a-zA-Z]$/.test(e.key) && !e.ctrlKey && !e.metaKey) {
                        e.preventDefault(); this.cartMode = false; this.activeCartIndex = -1;
                        this.searchQuery += e.key; this.$refs.searchInput?.focus();
                        this.$nextTick(() => this.onSearchInput()); return;
                    }
                    return;
                }

                if (!isInput && !this.gridFocusMode && this.cart.length > 0) {
                    if (e.key === 'ArrowDown') { e.preventDefault(); this.enterCartMode(); return; }
                    if (e.key === '+' || e.key === '=') { e.preventDefault(); this.updateQty(this.cart.length - 1, 1); this.animateQty(this.cart.length - 1); return; }
                    if (e.key === '-') { e.preventDefault(); this.updateQty(this.cart.length - 1, -1); this.animateQty(this.cart.length - 1); return; }
                    if (e.key === 'Delete') { e.preventDefault(); this.removeFromCart(this.cart.length - 1); return; }
                }

                if (e.key === 'Tab' && !e.shiftKey && !this.gridFocusMode && document.activeElement === this.$refs.searchInput && !this.showSearchDropdown) {
                    e.preventDefault(); this.enterGridMode();
                }

                if (!isInput && !this.gridFocusMode && e.key.length === 1 && /[a-zA-Z]/.test(e.key) && !e.ctrlKey && !e.metaKey && !e.altKey) {
                    e.preventDefault();
                    this.searchQuery += e.key;
                    this.$refs.searchInput?.focus();
                    this.$nextTick(() => this.onSearchInput());
                }
            });
            this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
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
            this.showToast('Added: ' + item.name, 'success');
        },

        getCartQty(item) {
            const found = this.cart.find(c => c.item_id === item.id && c.item_type === item.type);
            return found ? found.quantity : 0;
        },

        onSearchInput() {
            this.filterProducts();
            const q = this.searchQuery.trim().toLowerCase();
            if (q.length > 0) {
                let all = [...this.allProducts, ...this.allServices].filter(i => parseFloat(i.price) > 0 && i.name && i.name.trim().length > 0);
                this.searchSuggestions = all.filter(i => i.name.toLowerCase().includes(q)).slice(0, 12);
                this.highlightIndex = 0; this.showSearchDropdown = true;
            } else { this.searchSuggestions = []; this.showSearchDropdown = false; }
        },
        moveHighlight(dir) {
            if (!this.showSearchDropdown || this.searchSuggestions.length === 0) return;
            this.highlightIndex = Math.max(0, Math.min(this.searchSuggestions.length - 1, this.highlightIndex + dir));
            this.$nextTick(() => {
                const dd = this.$refs.searchDropdown;
                if (dd) {
                    const active = dd.querySelector('[data-hl="true"]');
                    if (active) active.scrollIntoView({ block: 'nearest' });
                }
            });
        },
        addHighlightedItem() { if (this.showSearchDropdown && this.searchSuggestions.length > 0) this.quickAddItem(this.searchSuggestions[this.highlightIndex]); },
        quickAddItem(item) {
            this.handleProductClick(item);
            this.searchQuery = ''; this.searchSuggestions = []; this.showSearchDropdown = false;
            this.filterProducts(); this.$nextTick(() => { this.$refs.searchInput?.focus(); });
        },

        filterProducts() {
            let items = [...this.allProducts, ...this.allServices];
            items = items.filter(i => parseFloat(i.price) > 0 && i.name && i.name.trim().length > 0);
            if (this.activeCategory !== 'all' && this.activeCategory !== 'services') { items = this.allProducts.filter(p => p.category === this.activeCategory && parseFloat(p.price) > 0 && p.name && p.name.trim().length > 0); }
            else if (this.activeCategory === 'services') { items = this.allServices.filter(s => parseFloat(s.price) > 0 && s.name && s.name.trim().length > 0); }
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
            if (existing) {
                existing.quantity++;
                this.activeCartIndex = this.cart.indexOf(existing);
                this.animateQty(this.activeCartIndex);
            } else {
                this.cart.push({ item_id: item.id, item_type: item.type, item_name: item.name, quantity: 1, unit_price: parseFloat(item.price), special_notes: '', is_tax_exempt: item.is_tax_exempt || false, item_discount_type: 'percentage', item_discount_value: 0, showItemDiscount: false });
                this.activeCartIndex = this.cart.length - 1;
            }
            this.cartAnimating = true; setTimeout(() => this.cartAnimating = false, 300);
            this.scrollToCartItem(this.activeCartIndex);
        },
        updateQty(index, delta) { this.cart[index].quantity = Math.max(0.01, parseFloat(this.cart[index].quantity) + delta); },
        setQty(index, val) { const v = parseFloat(val); if (v > 0) this.cart[index].quantity = v; },
        removeFromCart(index) {
            const el = this.$refs.cartList?.querySelector(`[data-cart-index="${index}"]`);
            if (el) {
                el.classList.add('cart-item-exit');
                setTimeout(() => {
                    this.cart.splice(index, 1);
                    if (this.activeCartIndex >= this.cart.length) this.activeCartIndex = this.cart.length - 1;
                }, 250);
            } else {
                this.cart.splice(index, 1);
                if (this.activeCartIndex >= this.cart.length) this.activeCartIndex = this.cart.length - 1;
            }
        },

        enterCartMode() {
            if (this.cart.length === 0) return;
            this.cartMode = true;
            this.gridFocusMode = false;
            this.activeCartIndex = this.cart.length - 1;
            document.activeElement?.blur();
            this.scrollToCartItem(this.activeCartIndex);
        },

        moveCartSelection(dir) {
            if (this.cart.length === 0) return;
            let next = this.activeCartIndex + dir;
            if (next < 0) next = 0;
            if (next >= this.cart.length) next = this.cart.length - 1;
            this.activeCartIndex = next;
            this.scrollToCartItem(next);
        },

        scrollToCartItem(index) {
            this.$nextTick(() => {
                const el = this.$refs.cartList?.querySelector(`[data-cart-index="${index}"]`);
                if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
        },

        handleQtyDigit(digit, index) {
            if (this.qtyInputTimer) clearTimeout(this.qtyInputTimer);
            this.qtyInputBuffer += digit;
            const val = parseInt(this.qtyInputBuffer);
            if (val > 0) { this.cart[index].quantity = val; this.animateQty(index); }
            this.qtyInputTimer = setTimeout(() => { this.qtyInputBuffer = ''; }, 800);
        },

        animateQty(index) {
            this.$nextTick(() => {
                const el = this.$refs.cartList?.querySelector(`[data-cart-index="${index}"] input[type="number"]`);
                if (el) { el.classList.remove('qty-pop'); void el.offsetWidth; el.classList.add('qty-pop'); }
            });
        },

        clearCart() { this.cart = []; this.kitchenNotes = ''; this.selectedTable = null; this.selectedCustomer = null; this.customerStats = null; this.customerPhoneQuery = ''; this.customerPhoneResults = []; this.customerPhoneDropdown = false; this.stockError = ''; this.priorityOrder = false; this.recalledOrderId = null; this.discountType = 'percentage'; this.discountValue = 0; this.discountAmount = 0; this.showDiscount = false; this.managerOverrideActive = false; this.activeCartIndex = -1; this.cartMode = false; this.qtyInputBuffer = ''; this.clearCartStorage(); },
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
            this.customerPhoneQuery = c.phone || c.name;
            this.showCustomerPicker = false;
            this.customerLookupResult = null;
            this.showToast('Customer: ' + c.name + (this.customerStats.is_frequent ? ' (VIP)' : ''), 'success');
        },

        async selectCustomerWithStats(c) {
            this.selectedCustomer = c;
            this.customerStats = null;
            this.customerPhoneQuery = c.phone || c.name;
            this.showCustomerPicker = false;
            this.showToast('Customer: ' + c.name, 'success');
            if (c.phone) {
                try {
                    const res = await fetch('/pos/restaurant/api/customer-lookup?phone=' + encodeURIComponent(c.phone));
                    const data = await res.json();
                    if (data.found) {
                        this.customerStats = data.stats;
                        if (data.customer.address && !this.selectedCustomer.address) {
                            this.selectedCustomer.address = data.customer.address;
                        }
                    }
                } catch(e) {}
            }
        },

        onCustomerPhoneInput() {
            if (this.customerPhoneTimer) clearTimeout(this.customerPhoneTimer);
            const q = this.customerPhoneQuery.trim();
            if (this.selectedCustomer) {
                this.selectedCustomer = null;
                this.customerStats = null;
            }
            if (q.length >= 3) {
                this.customerPhoneTimer = setTimeout(() => this.searchCustomerByPhone(q), 300);
            } else {
                this.customerPhoneResults = [];
                this.customerPhoneDropdown = false;
            }
        },

        async searchCustomerByPhone(q) {
            try {
                const res = await fetch('/pos/restaurant/api/customer-search?q=' + encodeURIComponent(q));
                const data = await res.json();
                this.customerPhoneResults = data.customers || [];
                this.customerPhoneDropdown = this.customerPhoneResults.length > 0;
            } catch(e) { this.customerPhoneResults = []; this.customerPhoneDropdown = false; }
        },

        onCustomerPhoneEnter() {
            const q = this.customerPhoneQuery.trim();
            if (!q) return;
            if (this.customerPhoneDropdown && this.customerPhoneResults.length > 0) {
                this.selectCustomerFromPhone(this.customerPhoneResults[0]);
            } else if (q.length >= 4 && /^\d+$/.test(q)) {
                this.newCustomerPhone = q;
                this.newCustomerName = '';
                this.newCustomerAddress = '';
                this.showNewCustomerModal = true;
                this.$nextTick(() => this.$refs.newCustomerNameInput?.focus());
            } else {
                this.showToast('Enter a valid mobile number', 'error');
            }
        },

        selectCustomerFromPhone(cr) {
            this.selectedCustomer = { id: cr.id, name: cr.name, phone: cr.phone, address: cr.address };
            this.customerStats = cr.stats || null;
            this.customerPhoneQuery = cr.phone || cr.name;
            this.customerPhoneDropdown = false;
            this.customerPhoneResults = [];
            this.showToast('Customer: ' + cr.name + (cr.stats && cr.stats.is_frequent ? ' (VIP)' : ''), 'success');
            this.$nextTick(() => { this.$refs.searchInput?.focus(); });
        },

        async saveNewCustomer() {
            const name = this.newCustomerName.trim();
            if (!name) { this.showToast('Customer name is required', 'error'); return; }
            try {
                const res = await fetch('{{ route("pos.restaurant.customer-store") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ name: name, phone: this.newCustomerPhone, address: this.newCustomerAddress.trim() || null })
                });
                const data = await res.json();
                if (data.success) {
                    this.selectedCustomer = { id: data.customer.id, name: data.customer.name, phone: data.customer.phone, address: data.customer.address };
                    this.customerStats = { total_orders: 0, total_spent: 0, is_frequent: false };
                    this.customerPhoneQuery = data.customer.phone || data.customer.name;
                    this.showNewCustomerModal = false;
                    this.customerPhoneDropdown = false;
                    if (this.allCustomers) this.allCustomers.push(data.customer);
                    this.showToast('Customer created: ' + data.customer.name, 'success');
                    this.$nextTick(() => { this.$refs.searchInput?.focus(); });
                } else { this.showToast(data.message || 'Failed', 'error'); }
            } catch(e) { this.showToast('Network error', 'error'); }
        },

        clearCustomerInput() {
            this.customerPhoneQuery = '';
            this.customerPhoneResults = [];
            this.customerPhoneDropdown = false;
            this.selectedCustomer = null;
            this.customerStats = null;
            this.$refs.customerPhoneInput?.focus();
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
                    body: JSON.stringify({ items: this.cart, order_type: this.orderType, table_id: this.selectedTable?.id || null, customer_id: this.selectedCustomer?.id || null, customer_name: this.selectedCustomer?.name || null, customer_phone: this.selectedCustomer?.phone || null, kitchen_notes: this.kitchenNotes, priority: this.priorityOrder, recalled_order_id: this.recalledOrderId, discount_type: this.discountAmount > 0 ? this.discountType : null, discount_value: this.discountAmount > 0 ? this.discountValue : 0, discount_amount: this.discountAmount }),
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast(data.message, 'success'); this.heldOrders.unshift(data.order); this.clearCart();
                    this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
                    if (this.kitchenSettings.print_on_hold) { window.open('/pos/restaurant/orders/' + data.order.id + '/kitchen-ticket', '_blank', 'width=350,height=600'); }
                } else { this.showToast(data.message || 'Failed', 'error'); }
            } catch (e) { this.showToast('Network error', 'error'); }
            this.submitting = false;
        },

        async processPayment(method) {
            if (this.submitting) return;

            if (this.payingHeldOrderId) {
                this.submitting = true; this.stockError = '';
                await this.payHeldOrderDirect(this.payingHeldOrderId, method, null);
                this.payingHeldOrderId = null;
                this.showPayModal = false; this.submitting = false;
                return;
            }

            if (this.cart.length === 0) return;
            const now = Date.now();
            if (now - this.lastPayTime < 3000) return;
            this.lastPayTime = now;
            this.submitting = true; this.stockError = '';
            try {
                const holdRes = await fetch('{{ route("pos.restaurant.orders.hold") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ items: this.cart, order_type: this.orderType, table_id: this.selectedTable?.id || null, customer_id: this.selectedCustomer?.id || null, customer_name: this.selectedCustomer?.name || null, customer_phone: this.selectedCustomer?.phone || null, kitchen_notes: this.kitchenNotes, priority: this.priorityOrder, recalled_order_id: this.recalledOrderId, discount_type: this.discountAmount > 0 ? this.discountType : null, discount_value: this.discountAmount > 0 ? this.discountValue : 0, discount_amount: this.discountAmount }),
                });
                const holdData = await holdRes.json();
                if (!holdData.success) { this.showToast(holdData.message || 'Failed', 'error'); this.submitting = false; return; }
                const savedTotal = this.totalAmount;
                await this.payHeldOrderDirect(holdData.order.id, method, savedTotal);
                this.clearCart();
            } catch (e) { this.showToast('Network error', 'error'); }
            this.showPayModal = false; this.submitting = false;
        },

        payingHeldOrderId: null,

        async payHeldOrder(orderId) {
            if (this.submitting) return;
            this.payingHeldOrderId = orderId;
            this.showHeldOrders = false;
            this.stockError = '';
            this.showPayModal = true;
        },

        startNewAfterPayment() {
            this.showReceipt = false;
            this.clearCart();
            this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); this.$refs.customerPhoneInput?.select(); });
        },

        printReceipt() {
            if (!this.lastTransactionId) return;
            const url = '/pos/restaurant/receipt/' + this.lastTransactionId + '?auto_print=1';
            let printFrame = document.getElementById('print-receipt-frame');
            if (!printFrame) {
                printFrame = document.createElement('iframe');
                printFrame.id = 'print-receipt-frame';
                printFrame.style.cssText = 'position:fixed;width:0;height:0;border:none;left:-9999px;top:-9999px;';
                document.body.appendChild(printFrame);
            }
            printFrame.onload = () => {
                setTimeout(() => {
                    try { printFrame.contentWindow.print(); } catch(e) { window.open(url, '_blank', 'width=400,height=700'); }
                }, 500);
            };
            printFrame.src = url;
        },

        async deleteHeldOrder(orderId) {
            try {
                const res = await fetch(`/pos/restaurant/orders/${orderId}/delete`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });
                if (!res.ok) { this.showToast('Failed to delete order (Error ' + res.status + ')', 'error'); return; }
                const data = await res.json();
                if (data.success) {
                    this.heldOrders = this.heldOrders.filter(o => o.id !== orderId);
                    if (this.activeHeldIndex >= this.heldOrders.length) this.activeHeldIndex = Math.max(0, this.heldOrders.length - 1);
                    this.showToast('Order deleted', 'success');
                } else { this.showToast(data.message || 'Failed', 'error'); }
            } catch (e) { console.error('Delete held order error:', e); this.showToast('Error deleting order', 'error'); }
        },

        async payHeldOrderDirect(orderId, method, savedTotal) {
            try {
                const res = await fetch(`/pos/restaurant/orders/${orderId}/pay`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ payment_method: method }) });
                const data = await res.json();
                if (data.success) {
                    this.heldOrders = this.heldOrders.filter(o => o.id !== orderId);
                    this.lastInvoiceNumber = data.invoice_number || ''; this.lastTransactionId = data.transaction_id || null;
                    this.lastTotal = savedTotal || data.total_amount || 0; this.lastPaymentMethod = method; this.showReceipt = true;
                    this.$nextTick(() => { setTimeout(() => this.triggerConfetti(), 300); });
                } else { if (data.stock_error) { this.stockError = data.message; this.showPayModal = true; } this.showToast(data.message || 'Payment failed', 'error'); }
            } catch (e) { this.showToast('Payment error', 'error'); }
        },

        recallOrder(order) {
            if (this.cart.length > 0 && !confirm('Current cart has items. Replace with recalled order?')) return;
            this.cart = order.items.map(i => ({ item_id: i.item_id, item_type: i.item_type, item_name: i.item_name, quantity: parseFloat(i.quantity), unit_price: parseFloat(i.unit_price), special_notes: i.special_notes || '', is_tax_exempt: i.is_tax_exempt || false, item_discount_type: i.item_discount_type || 'percentage', item_discount_value: parseFloat(i.item_discount_value) || 0, showItemDiscount: parseFloat(i.item_discount_value) > 0 }));
            this.kitchenNotes = order.kitchen_notes || '';
            this.recalledOrderId = order.id;
            this.priorityOrder = order.priority || false;
            if (order.discount_type && parseFloat(order.discount_value) > 0) { this.discountType = order.discount_type; this.discountValue = parseFloat(order.discount_value) || 0; this.showDiscount = true; } else { this.discountType = 'percentage'; this.discountValue = 0; this.discountAmount = 0; this.showDiscount = false; }
            if (order.table) { this.selectedTable = { id: order.table.id, table_number: order.table.table_number }; this.orderType = 'dine_in'; }
            this.selectedCustomer = order.customer_id ? { id: order.customer_id, name: order.customer_name || 'Customer', phone: order.customer_phone || '' } : null;
            this.customerPhoneQuery = this.selectedCustomer ? (this.selectedCustomer.phone || this.selectedCustomer.name) : '';
            this.heldOrders = this.heldOrders.filter(o => o.id !== order.id); this.showHeldOrders = false; this.showToast('Order recalled for editing', 'success');
        },

        async addQuickCustomer() {
            if (!this.quickCustomerName.trim() || !this.quickCustomerPhone.trim()) {
                this.showToast('Name and phone are required', 'error'); return;
            }
            try {
                const res = await fetch('{{ route("pos.restaurant.customer-store") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ name: this.quickCustomerName.trim(), phone: this.quickCustomerPhone.trim(), address: this.quickCustomerAddress.trim() || null }),
                });
                const data = await res.json();
                if (data.customer || data.success) {
                    const cust = data.customer || { id: Date.now(), name: this.quickCustomerName.trim(), phone: this.quickCustomerPhone.trim(), address: this.quickCustomerAddress.trim() };
                    if (!data.existing) this.allCustomers.push(cust);
                    this.selectedCustomer = cust; this.showQuickAdd = false;
                    this.customerPhoneQuery = cust.phone || cust.name;
                    this.quickCustomerName = ''; this.quickCustomerPhone = ''; this.quickCustomerAddress = ''; this.showCustomerPicker = false;
                    this.showToast(data.existing ? 'Customer found: ' + cust.name : 'Customer added: ' + cust.name, 'success');
                } else { this.showToast(data.message || 'Failed', 'error'); }
            } catch (e) { this.showToast('Error adding customer', 'error'); }
        },

        get effectiveDiscountLimit() {
            if (this.posRole === 'pos_admin') return 100;
            return this.managerOverrideActive ? {{ $hasManagerPin ? ($company->manager_discount_limit ?? 50) : 100 }} : this.discountLimit;
        },
        checkDiscountLimit(val, type) {
            if (type === 'percentage' && val > this.effectiveDiscountLimit) return false;
            if (type === 'amount' && this.effectiveSubtotal > 0 && (val / this.effectiveSubtotal * 100) > this.effectiveDiscountLimit) return false;
            return true;
        },
        async requestManagerOverride() {
            if (!this.hasManagerPin) { this.showToast('Manager PIN not configured', 'error'); return; }
            this.showManagerPinModal = true; this.managerPin = ''; this.managerPinError = '';
        },
        async submitManagerPin() {
            try {
                const res = await fetch('{{ route("pos.restaurant.verify-manager-pin") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ pin: this.managerPin }),
                });
                const data = await res.json();
                if (data.success) {
                    this.managerOverrideActive = true; this.showManagerPinModal = false;
                    this.showToast('Manager override granted', 'success');
                } else { this.managerPinError = data.message || 'Invalid PIN'; }
            } catch (e) { this.managerPinError = 'Connection error'; }
        },
        async loadCustomerHistory(customerId) {
            this.loadingCustomerHistory = true; this.customerHistory = null;
            try {
                const res = await fetch(`/pos/restaurant/api/customer-history/${customerId}`);
                if (res.ok) { this.customerHistory = await res.json(); this.showCustomerHistory = true; }
            } catch (e) {}
            this.loadingCustomerHistory = false;
        },
        reorderItems(order) {
            for (const item of order.items) {
                const existing = this.cart.find(c => c.item_id === item.item_id && c.item_type === item.item_type);
                if (existing) { existing.quantity += item.qty; } else {
                    this.cart.push({ item_id: item.item_id, item_type: item.item_type, item_name: item.name, quantity: item.qty, unit_price: item.price, special_notes: '', is_tax_exempt: false, item_discount_type: 'percentage', item_discount_value: 0, showItemDiscount: false });
                }
            }
            this.showCustomerHistory = false; this.showToast('Items added to cart', 'success');
        },
        getCartCost() {
            return this.cart.reduce((s, i) => {
                if (i.item_type === 'service') return s;
                const cost = this.ingredientCosts[i.item_id] || 0;
                return s + (cost * i.quantity);
            }, 0);
        },
        showToast(msg, type) { this.toast = { show: true, message: msg, type }; setTimeout(() => this.toast.show = false, 2500); },
        triggerConfetti() {
            const container = document.getElementById('confettiContainer');
            if (!container) return;
            const colors = ['#22c55e', '#7c3aed', '#f59e0b', '#3b82f6', '#ef4444', '#ec4899', '#14b8a6'];
            for (let i = 0; i < 30; i++) {
                const piece = document.createElement('div');
                piece.className = 'confetti-piece';
                piece.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                piece.style.left = Math.random() * 100 + '%';
                piece.style.top = '-10px';
                piece.style.animationDelay = Math.random() * 0.5 + 's';
                piece.style.animationDuration = (1 + Math.random() * 1) + 's';
                if (Math.random() > 0.5) { piece.style.borderRadius = '50%'; piece.style.width = '6px'; piece.style.height = '6px'; }
                container.appendChild(piece);
                setTimeout(() => piece.remove(), 2000);
            }
        },
    };
}
</script>
</x-pos-layout>
