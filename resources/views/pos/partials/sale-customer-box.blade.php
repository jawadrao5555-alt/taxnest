{{-- Customer search box (name/mobile + dropdown + inline quick-add) — shared partial.
     Rendered FIRST in the sale-screen action bar for ALL styles (markup byte-identical
     to the original inline block). Owner rule (23 Jul 2026): the guided flow starts with
     the customer step, so this box stays at the START — a brief saaf-only move to the
     cart panel broke the flow and was reverted; do not relocate it per-style.
     $inCart=true renders a full-width variant (currently unused).
     Same Alpine component scope either way — $refs.customerPhoneInput, Alt+P and the guided
     customer step keep working unchanged. --}}
@php($inCart = $inCart ?? false)
@if($inCart)
        <div class="relative w-full">
@else
        {{-- WIDE variant (owner, 24 Jul 2026): the action bar is now 2 rows — customer box
             grows on row 1 so long names/numbers show comfortably (was 180–220px). --}}
        <div class="relative flex-1" style="min-width:220px;max-width:520px;">
@endif
            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
            <input type="search" x-ref="customerPhoneInput" x-model="customerPhoneQuery" @input="onCustomerPhoneInput()" @keydown.enter.prevent="if(!$event.repeat) onCustomerPhoneEnter()" @keydown.down.prevent="custNav(1)" @keydown.up.prevent="custNav(-1)" @keydown.escape.prevent="customerPhoneDropdown = false" @keydown.tab.prevent="$refs.searchInput?.focus()" @click.away="customerPhoneDropdown = false" placeholder="Customer name or mobile..." class="w-full pl-9 pr-7 py-2.5 rounded-xl text-sm border-2 transition shadow-sm" :class="selectedCustomer ? 'font-bold border-blue-400 dark:border-blue-600 bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-200' : 'font-medium border-blue-200 dark:border-blue-800 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-400'" autocomplete="one-time-code" name="pos_customer_phone_nofill" data-lpignore="true" data-form-type="other">
            <kbd x-show="!customerPhoneQuery && !selectedCustomer && !customerSearching" class="absolute right-2 top-1/2 -translate-y-1/2 text-[8px] text-gray-400 bg-gray-100 dark:bg-gray-700 px-1 py-0.5 rounded font-mono">Alt+P</kbd>
            {{-- Inline search spinner --}}
            <svg x-show="customerSearching && !selectedCustomer" x-cloak class="absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-blue-500 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <button x-show="(customerPhoneQuery || selectedCustomer) && !customerSearching" @click="clearCustomerInput()" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-red-500 transition">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div x-show="customerPhoneDropdown && customerPhoneResults.length > 0 && !showNewCustomerInline" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-2xl z-50 max-h-52 overflow-y-auto" style="min-width:280px;">
                {{-- Item #2 (owner, Jul 2026): ↑↓ arrow-key navigation — custHiIndex is the
                     keyboard-highlighted row; Enter picks IT (not always the first result). --}}
                <template x-for="(cr, ci) in customerPhoneResults" :key="cr.id">
                    <button @click="selectCustomerFromPhone(cr)" @mouseenter="custHiIndex = ci" :data-cust-row="ci" class="w-full flex items-center gap-2 px-3 py-2.5 text-left hover:bg-blue-50 dark:hover:bg-blue-900/20 transition border-b border-gray-50 dark:border-gray-800" :class="ci === custHiIndex ? 'bg-blue-100 dark:bg-blue-900/30' : ''">
                        <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0"><span class="text-xs font-bold text-blue-600" x-text="cr.name.charAt(0)"></span></div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-gray-900 dark:text-white truncate" x-text="cr.name"></p>
                            <p class="text-xs text-gray-400" x-text="cr.phone + (cr.stats ? ' • ' + cr.stats.total_orders + ' orders • Rs.' + Number(cr.stats.total_spent).toLocaleString() : '')"></p>
                            <template x-if="cr.address"><p class="text-xs text-gray-400 truncate" x-text="cr.address"></p></template>
                        </div>
                        <template x-if="cr.stats && cr.stats.is_frequent"><span class="freq-badge">VIP</span></template>
                    </button>
                </template>
            </div>

            {{-- Inline "no match → quick add" hint (NO popup, INLINE only) --}}
            <div x-show="customerPhoneDropdown && !showNewCustomerInline && customerPhoneResults.length === 0 && isPhoneLike(customerPhoneQuery) && !customerSearching" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-blue-200 dark:border-blue-800 rounded-xl shadow-2xl z-50 overflow-hidden" style="min-width:280px;">
                <button @click="openInlineNewCustomer()" class="w-full flex items-center gap-2 px-3 py-2.5 text-left hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                    <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-bold text-blue-700 dark:text-blue-300">Add new customer</p>
                        <p class="text-[10px] text-gray-500" x-text="customerPhoneQuery + ' · press Enter'"></p>
                    </div>
                </button>
            </div>

            {{-- Inline new-customer quick form (NO popup) --}}
            <div x-show="showNewCustomerInline" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-900 border-2 border-blue-400 dark:border-blue-600 rounded-xl shadow-2xl z-50 p-3 space-y-2" style="min-width:300px;" @keydown.escape.prevent="cancelInlineNewCustomer()">
                <div class="flex items-center justify-between">
                    <p class="text-[10px] font-bold text-blue-600 dark:text-blue-400 uppercase tracking-wider">+ New Customer</p>
                    <button type="button" @click="cancelInlineNewCustomer()" class="text-gray-400 hover:text-red-500 text-[10px] font-semibold">Cancel</button>
                </div>
                <div class="text-[10px] font-semibold text-gray-600 dark:text-gray-400 px-2 py-1.5 bg-gray-100 dark:bg-gray-800 rounded-lg">
                    <span class="text-gray-400">Mobile:</span> <span class="text-gray-900 dark:text-white font-bold" x-text="newCustomerPhone"></span>
                </div>
                <input type="text" x-ref="newCustomerNameInput" x-model="newCustomerName"
                    autocomplete="one-time-code" name="pos_newcust_name_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                    @keydown.enter.prevent="$refs.newCustomerAddressInput?.focus()"
                    placeholder="Customer name (optional)"
                    class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-400">
                <input type="text" x-ref="newCustomerAddressInput" x-model="newCustomerAddress"
                    autocomplete="one-time-code" name="pos_newcust_addr_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                    @keydown.enter.prevent="saveNewCustomer()"
                    placeholder="Address (optional)"
                    class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-400">
                <button type="button" @click="saveNewCustomer()" :disabled="savingCustomer" class="w-full py-2 text-xs font-bold text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-60 transition">
                    <span x-show="!savingCustomer">Save & Select (Enter)</span>
                    <span x-show="savingCustomer">Saving…</span>
                </button>
            </div>
        </div>
