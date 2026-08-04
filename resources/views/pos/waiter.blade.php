<x-pos-layout>
@php
    // UTF-8-safe encode — a single malformed product name must never kill the
    // whole x-data block (json_encode returns false → empty output → Alpine dead).
    $jsEnc = function ($v) {
        $s = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $s === false ? '[]' : $s;
    };
@endphp
<div x-data="waiterApp()" x-init="init()" class="max-w-7xl mx-auto px-3 sm:px-6 py-4">

    {{-- ── Header ─────────────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.waiter_tablet') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.waiter_tablet_subtitle') }}</p>
        </div>
        <div class="flex items-center gap-2">
            {{-- Waiter APK download (Aug 2026) — cookie-less public static file,
                 same pattern as Rider APK on rider-tracking page. --}}
            <a href="{{ url('/downloads/taxnest-waiter.apk') }}"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200">
                ⬇ {{ __('pos.waiter_app_download') }}
            </a>
            {{-- ZFC (29 Jul 2026): manual refresh — waiter phones keep this tab
                 open for days; this pulls the LATEST code with a cache-buster. --}}
            <button @click="hardRefresh()" title="{{ __('pos.ti_refresh_app') }}" class="px-3 py-2 rounded-xl text-sm font-bold bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-teal-500 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </button>
            <button @click="openMyOrders()" class="relative px-4 py-2 rounded-xl text-sm font-bold bg-teal-600 hover:bg-teal-700 text-white transition">
                {{ __('pos.my_orders') }}
                <span x-show="myOrders.length > 0" x-cloak class="absolute -top-1.5 -right-1.5 min-w-[20px] h-5 px-1 bg-amber-500 text-white text-[10px] rounded-full flex items-center justify-center font-bold" x-text="myOrders.length"></span>
            </button>
        </div>
    </div>

    {{-- ── New-version banner (auto-update check, ZFC 29 Jul 2026) ────────── --}}
    <div x-show="updateAvailable" x-cloak class="mb-3 rounded-xl bg-blue-100 dark:bg-blue-900/30 border border-blue-300 dark:border-blue-700 px-4 py-2.5 flex items-center justify-between gap-2 flex-wrap">
        <span class="text-sm font-bold text-blue-800 dark:text-blue-300">{{ __('pos.new_update_refresh') }}</span>
        <button @click="hardRefresh()" class="px-4 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold">{{ __('pos.refresh_caps') }}</button>
    </div>

    {{-- ── Append-mode banner ─────────────────────────────────────────────── --}}
    <div x-show="appendOrderId" x-cloak class="mb-3 rounded-xl bg-amber-100 dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700 px-4 py-2.5 flex items-center justify-between gap-2 flex-wrap">
        <span class="text-sm font-bold text-amber-800 dark:text-amber-300">{{ __('pos.adding_items_to') }} <span class="font-mono" x-text="appendOrderNumber"></span> {{ __('pos.only_new_items_print') }}</span>
        <button @click="cancelAppend()" class="px-3 py-1.5 rounded-lg bg-white dark:bg-gray-800 text-xs font-bold text-gray-600 dark:text-gray-300 border border-gray-300 dark:border-gray-600">{{ __('pos.cancel') }}</button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- ── LEFT: product picker (ZFC issue #11, 28 Jul 2026: on MOBILE the
             punched ORDER shows on TOP, search/grid below — waiter had to scroll
             after every item; desktop stays picker-left / order-right) ──────── --}}
        <div class="lg:col-span-2 order-2 lg:order-1">
            <input type="text" x-model="search" @input="filterProducts()"
                   autocomplete="off" name="waiter_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                   placeholder="{{ __('pos.ph_search_items') }}"
                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-base px-4 py-3 focus:ring-teal-500 focus:border-teal-500 mb-3">
            <div class="flex gap-2 overflow-x-auto pb-2 mb-2" x-show="categories.length > 1">
                <button @click="activeCategory = 'all'; filterProducts()" :class="activeCategory === 'all' ? 'bg-teal-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700'" class="px-4 py-1.5 rounded-full text-xs font-bold whitespace-nowrap transition">{{ __('pos.all_word') }}</button>
                <template x-for="c in categories" :key="c">
                    <button @click="activeCategory = c; filterProducts()" :class="activeCategory === c ? 'bg-teal-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700'" class="px-4 py-1.5 rounded-full text-xs font-bold whitespace-nowrap transition" x-text="c"></button>
                </template>
            </div>
            {{-- PER-USER grid visibility (owner, 25 Jul 2026): waiter tarteeb apni
                 tablet ke liye — edit mode mein tile tap = chhupao/dikhao. --}}
            <div class="flex items-center gap-2 mb-2">
                <button type="button" @click="gridEditMode = !gridEditMode; filterProducts()"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold border transition"
                        :class="gridEditMode ? 'bg-teal-600 border-teal-600 text-white' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300'">
                    <svg x-show="!gridEditMode" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    <svg x-show="gridEditMode" x-cloak class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    <span x-text="gridEditMode ? {{ Js::from(__('pos.done_word')) }} : {{ Js::from(__('pos.grid_arrange')) }}"></span>
                </button>
                <button type="button" x-show="gridEditMode && hiddenPrefCount > 0" x-cloak @click="resetGridPrefs()" :disabled="gridPrefBusy"
                        class="px-3 py-1.5 rounded-full text-xs font-bold border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 transition disabled:opacity-50">
                    {{ __('pos.show_all_again') }}
                </button>
                <span x-show="gridEditMode" x-cloak class="text-[11px] font-semibold text-teal-700 dark:text-teal-300">{{ __('pos.tap_item_hide_show') }}</span>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2.5 max-h-[60vh] overflow-y-auto pr-1">
                <template x-for="p in filtered" :key="p.id">
                    <button @click="gridEditMode ? toggleItemVisibility(p) : addToCart(p)" class="rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:border-teal-500 dark:hover:border-teal-500 p-3 text-left transition active:scale-95" :class="gridEditMode && !isItemVisible(p) ? 'opacity-40' : ''">
                        <span class="block text-sm font-bold text-gray-800 dark:text-gray-100 leading-snug" x-text="p.name"></span>
                        <span class="block mt-1 text-xs font-black text-teal-700 dark:text-teal-400" x-text="'Rs ' + p.price.toLocaleString()"></span>
                        <span x-show="gridEditMode" x-cloak class="block mt-1 text-[10px] font-bold" :class="isItemVisible(p) ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400'" x-text="isItemVisible(p) ? {{ Js::from(__('pos.visible_word')) }} : {{ Js::from(__('pos.hidden_word')) }}"></span>
                    </button>
                </template>
                <div x-show="filtered.length === 0" class="col-span-full text-center py-8 text-sm text-gray-400">{{ __('pos.no_items_match') }}</div>
            </div>
        </div>

        {{-- ── RIGHT: order panel (mobile = TOP, see issue #11 note above) ── --}}
        <div class="order-1 lg:order-2 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-col gap-3 h-fit lg:sticky lg:top-4">
            <h2 class="text-sm font-black uppercase tracking-wide text-gray-500 dark:text-gray-400" x-text="appendOrderId ? {{ Js::from(__('pos.new_items')) }} : {{ Js::from(__('pos.order_word')) }}"></h2>

            {{-- Cart lines --}}
            <div class="space-y-2 max-h-[32vh] overflow-y-auto" x-show="cart.length">
                <template x-for="(line, i) in cart" :key="line.uid">
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-bold text-gray-800 dark:text-gray-100 flex-1 leading-snug" x-text="line.name"></span>
                            <button @click="cart.splice(i, 1)" class="w-7 h-7 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 flex items-center justify-center text-sm font-black">×</button>
                        </div>
                        <div class="flex items-center justify-between gap-2 mt-2">
                            <div class="flex items-center gap-1.5">
                                <button @click="line.quantity = Math.max(1, line.quantity - 1)" class="w-9 h-9 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-black text-lg">−</button>
                                <span class="w-8 text-center text-base font-black text-gray-900 dark:text-white" x-text="line.quantity"></span>
                                <button @click="line.quantity++" class="w-9 h-9 rounded-lg bg-teal-600 text-white font-black text-lg">+</button>
                            </div>
                            <span class="text-sm font-black text-gray-700 dark:text-gray-200" x-text="'Rs ' + Math.round(line.quantity * line.unit_price).toLocaleString()"></span>
                        </div>
                        <input type="text" x-model="line.special_notes" placeholder="{{ __('pos.ph_note_for_kitchen') }}"
                               autocomplete="off" :name="'waiter_note_' + i + '_nofill'" data-lpignore="true" data-form-type="other" data-1p-ignore
                               class="mt-2 w-full rounded-lg border-gray-200 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-xs px-2.5 py-1.5 focus:ring-teal-500 focus:border-teal-500">
                    </div>
                </template>
            </div>
            <div x-show="!cart.length" class="text-center py-6 text-sm text-gray-400">{{ __('pos.tap_items_to_add') }}</div>

            <div class="border-t border-gray-100 dark:border-gray-700 pt-3" x-show="cart.length">
                <div class="flex items-center justify-between">
                    {{-- ZFC issue #13: tax-inclusive shop => menu price is FINAL — one clean Total. --}}
                    <span class="text-sm font-bold text-gray-500 dark:text-gray-400">{{ ($taxInclusive ?? false) ? __('pos.total_word') : __('pos.total_before_tax') }}</span>
                    <span class="text-xl font-black text-gray-900 dark:text-white" x-text="'Rs ' + total().toLocaleString()"></span>
                </div>
                {{-- Item #5: indicative tax-inclusive estimate (cash rate) — the REAL bill
                     (rate by payment method, discounts) is settled on the cashier screen.
                     HIDDEN for tax-inclusive companies (issue #13). --}}
                <div class="flex items-center justify-between mt-0.5" x-show="taxEstimate() > 0" @if($taxInclusive ?? false) style="display:none !important" @endif>
                    <span class="text-[11px] font-semibold text-gray-400 dark:text-gray-500" x-text="{{ Js::from(__('pos.approx_incl_tax_cash')) }} + cashTaxRate + '%)'"></span>
                    <span class="text-sm font-bold text-gray-600 dark:text-gray-300" x-text="'Rs ' + (total() + taxEstimate()).toLocaleString()"></span>
                </div>
            </div>

            {{-- Order details (hidden in append mode — the order already has them) --}}
            <template x-if="!appendOrderId">
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        <button @click="orderType = 'dine_in'" :class="orderType === 'dine_in' ? 'bg-teal-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'" class="py-2.5 rounded-xl text-xs font-bold transition">{{ __('pos.dine_in') }}</button>
                        <button @click="orderType = 'takeaway'; selectedTable = null" :class="orderType === 'takeaway' ? 'bg-teal-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'" class="py-2.5 rounded-xl text-xs font-bold transition">{{ __('pos.take_away') }}</button>
                    </div>
                    <button x-show="orderType === 'dine_in'" @click="openTables()" class="w-full py-2.5 rounded-xl text-sm font-bold border-2 border-dashed transition"
                            :class="selectedTable ? 'border-teal-500 bg-teal-50 dark:bg-teal-900/20 text-teal-700 dark:text-teal-300' : 'border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400'"
                            x-text="selectedTable ? ({{ Js::from(__('pos.table_t_prefix2')) }} + selectedTable.table_number + ' · ' + selectedTable.floor) : {{ Js::from(__('pos.choose_table')) }}"></button>
                    <input type="text" x-model="customerName" placeholder="{{ __('pos.ph_customer_name_optional') }}"
                           autocomplete="off" name="waiter_customer_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm px-3 py-2.5 focus:ring-teal-500 focus:border-teal-500">
                    <input type="text" x-model="customerPhone" placeholder="{{ __('pos.ph_customer_phone_optional') }}" inputmode="tel"
                           autocomplete="off" name="waiter_phone_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm px-3 py-2.5 focus:ring-teal-500 focus:border-teal-500">
                    <textarea x-model="kitchenNotes" rows="2" placeholder="{{ __('pos.ph_kitchen_note_order') }}"
                              autocomplete="off" name="waiter_kn_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                              class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm px-3 py-2.5 focus:ring-teal-500 focus:border-teal-500"></textarea>
                    <div>
                        {{-- Cashier pick is OPTIONAL (customer feedback, 23 Jul 2026): default = counter,
                             order shows on EVERY cashier's incoming list. Picking a specific cashier
                             still works and sticks for the day (owner, 20 Jul 2026). --}}
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.send_to') }}</label>
                        <select x-model="cashierId" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm px-3 py-2.5 focus:ring-teal-500 focus:border-teal-500">
                            <option value="">{{ __('pos.counter_all_cashiers') }}</option>
                            @foreach($cashiers as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                        <p x-show="cashierId" class="mt-1 text-[11px] text-teal-600 dark:text-teal-400 font-medium">{{ __('pos.cashier_remembered_today') }}</p>
                        <p x-show="!cashierId" class="mt-1 text-[11px] text-gray-400">{{ __('pos.all_cashiers_will_see') }}</p>
                    </div>
                </div>
            </template>

            <button @click="send()" :disabled="sending || !cart.length"
                    class="w-full py-3.5 rounded-xl bg-teal-600 hover:bg-teal-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-base font-black transition"
                    x-text="sending ? {{ Js::from(__('pos.sending_ellipsis')) }} : (appendOrderId ? {{ Js::from(__('pos.add_items_to_order')) }} : {{ Js::from(__('pos.send_order')) }})"></button>
        </div>
    </div>

    {{-- ── Table picker modal ─────────────────────────────────────────────── --}}
    <div x-show="showTables" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60" @click="showTables = false"></div>
        <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] flex flex-col overflow-hidden">
            <div class="px-5 py-4 bg-teal-600 flex items-center justify-between">
                <h3 class="text-white font-bold">{{ __('pos.choose_table') }}</h3>
                <button @click="showTables = false" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/25 text-white font-black">×</button>
            </div>
            <div class="flex-1 overflow-y-auto p-4">
                <div x-show="tablesLoading" class="text-center py-8 text-sm text-gray-400">{{ __('pos.loading_ellipsis') }}</div>
                <div class="grid grid-cols-3 sm:grid-cols-4 gap-2.5">
                    <template x-for="t in tables" :key="t.id">
                        {{-- Occupied tiles (ZFC, 1 Aug 2026): ab tap-able — tap par us
                             table ka order KHALI table par SHIFT hota hai (cashier ke
                             lagaye orders bhi). Compose ke liye ab bhi sirf khali/reserved. --}}
                        <button @click="t.status === 'occupied' ? (tableActionFor = t) : pickTable(t)" :disabled="t.status === 'occupied' && !t.order_id"
                                :class="t.status === 'occupied' ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-500 dark:text-red-300' : (t.status === 'reserved' ? 'bg-amber-50 dark:bg-amber-900/20 border-amber-300 dark:border-amber-700 text-amber-700 dark:text-amber-300' : 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-300 dark:border-emerald-700 text-emerald-700 dark:text-emerald-300 hover:border-emerald-500')"
                                class="rounded-xl border-2 p-3 text-center transition">
                            <span class="block text-base font-black" x-text="'T-' + t.table_number"></span>
                            <span class="block text-[10px] font-bold mt-0.5" x-text="t.floor + ' · ' + t.seats + {{ Js::from(__('pos.sfx_seats')) }}"></span>
                            <span class="block text-[10px] font-bold uppercase mt-0.5" x-text="t.status"></span>
                            <span x-show="t.status === 'occupied' && t.order_id" class="block text-[10px] font-black uppercase mt-1 px-1.5 py-0.5 rounded-full bg-red-100 dark:bg-red-900/40 text-red-600 dark:text-red-300">{{ __('pos.shift_word') }} ⇄</span>
                        </button>
                    </template>
                </div>
                <div x-show="!tablesLoading && tables.length === 0" class="text-center py-8 text-sm text-gray-400">{{ __('pos.no_tables_configured_dot') }}</div>
            </div>
        </div>
    </div>

    {{-- ── Occupied-table action chooser (ZFC, 1 Aug 2026): Add Items ya Shift ── --}}
    <div x-show="tableActionFor" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60" @click="tableActionFor = null"></div>
        <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xs overflow-hidden">
            <div class="px-5 py-4 bg-teal-600 flex items-center justify-between">
                <h3 class="text-white font-bold" x-text="tableActionFor ? ('T-' + tableActionFor.table_number + ' — ' + (tableActionFor.order_number || '')) : ''"></h3>
                <button @click="tableActionFor = null" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/25 text-white font-black">×</button>
            </div>
            <div class="p-4 space-y-2.5">
                <button @click="startAppendFromTable(tableActionFor)" class="w-full py-3 rounded-xl bg-teal-600 hover:bg-teal-700 text-white font-bold text-sm">{{ __('pos.add_items') }} +</button>
                <button @click="startShiftFromTable(tableActionFor); tableActionFor = null" class="w-full py-3 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold text-sm">{{ __('pos.shift_word') }} ⇄</button>
            </div>
        </div>
    </div>

    {{-- ── My Orders modal ────────────────────────────────────────────────── --}}
    <div x-show="showMyOrders" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60" @click="showMyOrders = false"></div>
        <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xl max-h-[80vh] flex flex-col overflow-hidden">
            <div class="px-5 py-4 bg-teal-600 flex items-center justify-between">
                <h3 class="text-white font-bold">{{ __('pos.my_open_orders') }}</h3>
                <button @click="showMyOrders = false" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/25 text-white font-black">×</button>
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-3">
                <div x-show="myOrdersLoading" class="text-center py-8 text-sm text-gray-400">{{ __('pos.loading_ellipsis') }}</div>
                <div x-show="!myOrdersLoading && myOrders.length === 0" class="text-center py-8 text-sm text-gray-400">{{ __('pos.no_open_orders_settled') }}</div>
                <template x-for="o in myOrders" :key="o.id">
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-3">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-mono text-sm font-bold text-gray-800 dark:text-gray-100" x-text="o.order_number"></span>
                                <span x-show="o.table" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300" x-text="'T-' + o.table"></span>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300" x-text="'→ ' + (o.assigned_cashier || {{ Js::from(__('pos.any_cashier')) }})"></span>
                            </div>
                            <span class="text-sm font-black text-gray-900 dark:text-white" x-text="'Rs ' + Math.round(o.total_amount).toLocaleString()"></span>
                        </div>
                        <div class="mt-1.5 text-xs text-gray-600 dark:text-gray-300 leading-relaxed">
                            <template x-for="(it, ix) in o.items" :key="ix"><span><span x-text="it.quantity + '× ' + it.name"></span><span x-show="ix < o.items.length - 1"> · </span></span></template>
                        </div>
                        <div class="mt-2.5 flex items-center gap-2">
                            <button @click="startAppend(o)" class="px-4 py-2 rounded-lg bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold transition">{{ __('pos.add_items') }}</button>
                            {{-- Table Shift (owner batch, 26 Jul 2026): sirf dine-in
                                 orders (table wale); khali table par hi jayega. --}}
                            <button x-show="o.table_id" @click="startShift(o)" class="px-4 py-2 rounded-lg bg-white dark:bg-gray-800 border border-teal-300 dark:border-teal-700 text-teal-700 dark:text-teal-300 hover:bg-teal-50 text-xs font-bold transition">⇄ {{ __('pos.change_table') }}</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- ── Multi-order shift: order-selection step (Task 104, Aug 2026) ──────
         Table par 1 se zyada HELD orders → pehle waiter chune kaunsa order
         shift hoga (order number + items count), phir shift modal. --}}
    <div x-show="shiftPickFor" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60" @click="shiftPickFor = null"></div>
        <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xs max-h-[70vh] flex flex-col overflow-hidden">
            <div class="px-5 py-4 bg-amber-500 flex items-center justify-between">
                <h3 class="text-white font-bold" x-text="shiftPickFor ? ('T-' + shiftPickFor.table_number + ' — ' + {{ Js::from(__('pos.which_order_shift_q')) }}) : ''"></h3>
                <button @click="shiftPickFor = null" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/25 text-white font-black">×</button>
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-2.5">
                <template x-for="o in (shiftPickFor ? shiftPickFor.held_orders : [])" :key="'shiftpick' + o.id">
                    <button @click="pickShiftOrder(o)" class="w-full py-3 px-4 rounded-xl border-2 border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 text-left hover:border-amber-500 transition flex items-center justify-between gap-2">
                        <span class="font-mono text-sm font-bold text-gray-800 dark:text-gray-100" x-text="o.order_number"></span>
                        <span class="text-[11px] font-bold text-amber-700 dark:text-amber-300" x-text="o.items_count + {{ Js::from(__('pos.sfx_items')) }}"></span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    {{-- ── Multi-order Add Items: order-selection step (Task 108, Aug 2026) ──
         Table par 1 se zyada HELD orders → waiter chune items KIS order mein
         jayein (order number + items count), phir wohi append flow. --}}
    <div x-show="appendPickFor" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60" @click="appendPickFor = null"></div>
        <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xs max-h-[70vh] flex flex-col overflow-hidden">
            <div class="px-5 py-4 bg-teal-600 flex items-center justify-between">
                <h3 class="text-white font-bold" x-text="appendPickFor ? ('T-' + appendPickFor.table_number + ' — ' + {{ Js::from(__('pos.which_order_add_q')) }}) : ''"></h3>
                <button @click="appendPickFor = null" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/25 text-white font-black">×</button>
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-2.5">
                <template x-for="o in (appendPickFor ? appendPickFor.held_orders : [])" :key="'appendpick' + o.id">
                    <button @click="pickAppendOrder(o)" class="w-full py-3 px-4 rounded-xl border-2 border-teal-300 dark:border-teal-700 bg-teal-50 dark:bg-teal-900/20 text-left hover:border-teal-500 transition flex items-center justify-between gap-2">
                        <span class="font-mono text-sm font-bold text-gray-800 dark:text-gray-100" x-text="o.order_number"></span>
                        <span class="text-[11px] font-bold text-teal-700 dark:text-teal-300" x-text="o.items_count + {{ Js::from(__('pos.sfx_items')) }}"></span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    {{-- ── Table Shift modal (owner batch, 26 Jul 2026) ───────────────────────
         Waiter apna held dine-in order KHALI table par shift kare. Timer
         continue, KOT dobara nahi. Race-safe server-side. --}}
    <div x-show="shiftFor" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60" @click="if (!shiftBusy) shiftFor = null"></div>
        <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xl max-h-[80vh] flex flex-col overflow-hidden">
            <div class="px-5 py-4 bg-teal-600 flex items-center justify-between">
                <h3 class="text-white font-bold" x-text="shiftFor ? (shiftFor.order_number + {{ Js::from(__('pos.pick_new_table_suffix')) }}) : ''"></h3>
                <button @click="shiftFor = null" :disabled="shiftBusy" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/25 text-white font-black">×</button>
            </div>
            <div class="flex-1 overflow-y-auto p-4">
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.only_empty_tables_hint') }}</p>
                <div x-show="shiftTablesLoading" class="text-center py-8 text-sm text-gray-400">{{ __('pos.loading_ellipsis') }}</div>
                <div x-show="!shiftTablesLoading && shiftFreeTables().length === 0" class="text-center py-8 text-sm text-gray-400">{{ __('pos.no_empty_table_dot') }}</div>
                <div class="grid grid-cols-3 sm:grid-cols-4 gap-2">
                    <template x-for="t in shiftFreeTables()" :key="'shift' + t.id">
                        <button @click="doShift(t)" :disabled="shiftBusy" class="rounded-xl border-2 p-3 text-center transition bg-emerald-50 dark:bg-emerald-900/20 border-emerald-300 dark:border-emerald-700 text-emerald-700 dark:text-emerald-300 hover:border-emerald-500 disabled:opacity-40">
                            <span class="block text-base font-black" x-text="'T-' + t.table_number"></span>
                            <span class="block text-[10px] font-bold mt-0.5" x-text="t.floor + ' · ' + t.seats + {{ Js::from(__('pos.sfx_seats')) }}"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Toast ──────────────────────────────────────────────────────────── --}}
    <div x-show="toast" x-cloak x-transition class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[60] px-5 py-3 rounded-xl text-sm font-bold text-white"
         :class="toastType === 'error' ? 'bg-red-600' : 'bg-teal-600'" x-text="toast"></div>
</div>

<script>
function waiterApp() {
    return {
        products: {!! $jsEnc($products) !!},
        // PER-USER grid visibility (owner, 25 Jul 2026): {"product:12":0}. Pref
        // overrides admin show_on_sale BOTH directions — this waiter's grid only.
        userGridPrefs: {!! $jsEnc((object) ($userGridPrefs ?? [])) !!},
        gridEditMode: false,
        gridPrefBusy: false,
        cashTaxRate: {{ (float) ($cashTaxRate ?? 16) }},
        filtered: [],
        categories: [],
        search: '',
        activeCategory: 'all',
        cart: [],
        orderType: 'dine_in',
        selectedTable: null,
        customerName: '',
        customerPhone: '',
        kitchenNotes: '',
        cashierId: '',
        sending: false,
        showTables: false,
        tables: [],
        tablesLoading: false,
        showMyOrders: false,
        myOrders: [],
        myOrdersLoading: false,
        appendOrderId: null,
        appendOrderNumber: '',
        tableActionFor: null,    // occupied-tile chooser (Add Items / Shift)
        shiftFor: null,          // Table Shift (26 Jul 2026): order being shifted
        shiftPickFor: null,      // Multi-order shift (Task 104): table whose held order is being chosen
        appendPickFor: null,     // Multi-order Add Items (Task 108): table whose held order is being chosen
        shiftBusy: false,
        shiftTablesLoading: false,
        toast: '',
        toastType: 'success',
        _toastTimer: null,
        // Auto-update (ZFC, 29 Jul 2026): page ki code-version; server se poll
        // kar ke naya deploy pakarte hain.
        appVersion: @json($appVersion ?? 'unknown'),
        updateAvailable: false,

        init() {
            this.categories = [...new Set(this.products.map(p => p.category))].sort();
            this.filterProducts();
            this.initDayCashier();
            this.loadMyOrders();
            setInterval(() => { if (!document.hidden) this.loadMyOrders(); }, 30000);
            // Version check: every 2 min + whenever the phone comes back to the tab.
            setInterval(() => this.checkVersion(), 120000);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) this.checkVersion(); });
            this.checkVersion();
        },

        async checkVersion() {
            if (this.updateAvailable || document.hidden) return;
            try {
                const res = await fetch('{{ route("pos.waiter.version") }}?_=' + Date.now(), { cache: 'no-store' });
                if (!res.ok) return;
                const data = await res.json();
                if (data.v && this.appVersion !== 'unknown' && data.v !== this.appVersion) {
                    // Cart mein items hain => sirf banner (order zaya na ho);
                    // khali app => khud hi refresh.
                    if (this.cart.length === 0 && !this.sending) { this.hardRefresh(); }
                    else { this.updateAvailable = true; }
                }
            } catch (e) { /* offline / network blip — agli dafa sahi */ }
        },

        hardRefresh() {
            // Cache-buster query — SW skip-list + fresh URL = guaranteed new code.
            const u = new URL(window.location.href);
            u.searchParams.set('_r', Date.now());
            window.location.replace(u.toString());
        },

        // Once-per-day cashier (owner, 20 Jul 2026): the waiter picks a cashier
        // ONCE — it sticks for the whole day on this tablet (per waiter login).
        // New day (LOCAL date, not UTC) = fresh pick. Stale/removed cashier ids
        // are dropped so the select never silently posts to a dead account.
        _dayCashierKey: 'waiter_day_cashier_{{ auth("pos")->id() }}',
        validCashierIds: @json($cashiers->pluck('id')->map(fn ($i) => (string) $i)),
        _today() { return new Date().toLocaleDateString('en-CA'); },
        initDayCashier() {
            try {
                const saved = JSON.parse(localStorage.getItem(this._dayCashierKey) || 'null');
                if (saved && saved.date === this._today() && this.validCashierIds.includes(String(saved.id))) {
                    this.cashierId = String(saved.id);
                }
            } catch (e) { /* corrupt storage — start fresh */ }
            this.$watch('cashierId', id => {
                try {
                    if (id) localStorage.setItem(this._dayCashierKey, JSON.stringify({ date: this._today(), id: String(id) }));
                    else localStorage.removeItem(this._dayCashierKey);
                } catch (e) {}
            });
        },

        filterProducts() {
            const q = this.search.trim().toLowerCase();
            // Effective visibility = user pref ?? admin show_on_sale (pref-less
            // behavior identical to the old server-side filter). Edit mode shows
            // ALL items (hidden dimmed) so the waiter can un-hide them.
            const pool = this.products.filter(p => this.gridEditMode || this.isItemVisible(p));
            // Category pills track EFFECTIVE visibility (no empty pill for a
            // category whose items are all hidden — matches pre-feature output).
            this.categories = [...new Set(pool.map(p => p.category))].sort();
            if (this.activeCategory !== 'all' && !this.categories.includes(this.activeCategory)) this.activeCategory = 'all';
            // STRICT PREFIX (ZFC, 1 Aug 2026 — same rule as the cashier sale
            // screen, owner 24 Jul 2026): NAME matches only from the very START
            // of the name ("f" = Fries…, NOT "Beef Loaded Fries"). Barcode
            // matching only when the query has a digit/symbol.
            const codeSearch = /[^a-z\s]/.test(q);
            this.filtered = pool.filter(p =>
                (this.activeCategory === 'all' || p.category === this.activeCategory) &&
                (!q || p.name.toLowerCase().startsWith(q)
                    || (codeSearch && p.barcode && String(p.barcode).toLowerCase().includes(q)))
            );
        },

        isItemVisible(p) {
            const key = 'product:' + p.id;
            if (this.userGridPrefs[key] !== undefined) return this.userGridPrefs[key] == 1;
            return p.show_on_sale !== false;
        },
        async toggleItemVisibility(p) {
            const key = 'product:' + p.id;
            const newVisible = !this.isItemVisible(p);
            const prev = this.userGridPrefs[key];
            this.userGridPrefs[key] = newVisible ? 1 : 0; // optimistic
            try {
                const res = await fetch('/pos/grid-prefs/toggle', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ item_type: 'product', item_id: p.id, visible: newVisible })
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                this.filterProducts();
            } catch (e) {
                if (prev === undefined) delete this.userGridPrefs[key]; else this.userGridPrefs[key] = prev;
                this.showToast(@js(__('pos.save_failed_try_again')), 'error');
            }
        },
        async resetGridPrefs() {
            if (this.gridPrefBusy) return;
            this.gridPrefBusy = true;
            try {
                const res = await fetch('/pos/grid-prefs/reset', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                this.userGridPrefs = {};
                this.filterProducts();
                this.showToast(@js(__('pos.all_items_visible_again')), 'success');
            } catch (e) {
                this.showToast(@js(__('pos.reset_failed_try_again')), 'error');
            } finally {
                this.gridPrefBusy = false;
            }
        },
        get hiddenPrefCount() {
            return Object.values(this.userGridPrefs).filter(v => v == 0).length;
        },

        addToCart(p) {
            const ex = this.cart.find(l => l.item_id === p.id);
            if (ex) { ex.quantity++; return; }
            this.cart.push({
                uid: 'w' + Date.now() + '_' + Math.random().toString(36).slice(2, 8),
                item_id: p.id, name: p.name, quantity: 1,
                unit_price: p.price, special_notes: '',
                is_tax_exempt: !!p.is_tax_exempt,
            });
        },

        total() {
            return Math.round(this.cart.reduce((s, l) => s + l.quantity * l.unit_price, 0));
        },

        // Item #5 — indicative tax at the CASH rate on non-exempt lines only.
        // Whole-rupee like every other POS total; real tax is settled by the cashier.
        taxEstimate() {
            const taxable = this.cart.reduce((s, l) => s + (l.is_tax_exempt ? 0 : l.quantity * l.unit_price), 0);
            return Math.round(taxable * this.cashTaxRate / 100);
        },

        async openTables() {
            this.showTables = true;
            this.tablesLoading = true;
            try {
                const res = await fetch('/pos/waiter/api/tables', { headers: { 'Accept': 'application/json' } });
                this.tables = res.ok ? await res.json() : [];
            } catch (e) { this.tables = []; }
            this.tablesLoading = false;
        },
        pickTable(t) {
            if (t.status === 'occupied') return;
            this.selectedTable = t;
            this.showTables = false;
        },

        async loadMyOrders() {
            try {
                const res = await fetch('/pos/waiter/api/orders', { headers: { 'Accept': 'application/json' } });
                if (res.ok) this.myOrders = await res.json();
            } catch (e) { /* silent */ }
        },
        openMyOrders() {
            this.showMyOrders = true;
            this.myOrdersLoading = true;
            this.loadMyOrders().finally(() => { this.myOrdersLoading = false; });
        },

        startAppend(o) {
            if (this.cart.length && !confirm(@js(__('pos.discard_unsent_items_q')))) return;
            this.cart = [];
            this.appendOrderId = o.id;
            this.appendOrderNumber = o.order_number;
            this.showMyOrders = false;
        },
        cancelAppend() {
            this.appendOrderId = null;
            this.appendOrderNumber = '';
            this.cart = [];
        },

        // Add Items to ANY held order from the table picker (ZFC, 1 Aug 2026):
        // desktop/cashier ke lagaye orders bhi — reuses the append flow.
        // Multi-order tables (Task 108, Aug 2026): 1 se zyada HELD orders hon
        // to pehle chhota order-selection step (shift wale jaisa), phir append.
        startAppendFromTable(t) {
            if (!t || !t.order_id) return;
            const held = Array.isArray(t.held_orders) ? t.held_orders : [];
            if (held.length > 1) {
                this.appendPickFor = t;
                this.tableActionFor = null;
                return;
            }
            this.appendOrderId = t.order_id;
            this.appendOrderNumber = t.order_number || ('T-' + t.table_number);
            this.tableActionFor = null;
            this.showTables = false;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        // Shift from the table picker (ZFC, 1 Aug 2026): occupied tile tap —
        // shift that table's ACTIVE order (any creator) to an empty table.
        // Multi-order tables (Task 104, Aug 2026): 1 se zyada HELD orders hon
        // to pehle chhota order-selection step, phir shift modal.
        startShiftFromTable(t) {
            if (!t.order_id) return;
            const held = Array.isArray(t.held_orders) ? t.held_orders : [];
            if (held.length > 1) {
                this.shiftPickFor = t;
                return;
            }
            this.showTables = false;
            this.startShift({ id: t.order_id, order_number: t.order_number || ('T-' + t.table_number), table_id: t.id });
        },
        pickAppendOrder(o) {
            const t = this.appendPickFor;
            this.appendPickFor = null;
            if (!t || !o) return;
            this.appendOrderId = o.id;
            this.appendOrderNumber = o.order_number || ('T-' + t.table_number);
            this.showTables = false;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        pickShiftOrder(o) {
            const t = this.shiftPickFor;
            this.shiftPickFor = null;
            if (!t || !o) return;
            this.showTables = false;
            this.startShift({ id: o.id, order_number: o.order_number || ('T-' + t.table_number), table_id: t.id });
        },
        // ── Table Shift (owner batch, 26 Jul 2026) ──────────────────────────
        async startShift(o) {
            this.shiftFor = o;
            this.showMyOrders = false;
            this.shiftTablesLoading = true;
            try {
                const res = await fetch('/pos/waiter/api/tables', { headers: { 'Accept': 'application/json' } });
                if (res.ok) this.tables = await res.json();
            } catch (e) { /* silent — grid shows "koi khali table nahi" */ }
            this.shiftTablesLoading = false;
        },
        shiftFreeTables() {
            return this.tables.filter(t => t.status === 'available' && !(t.active_orders > 0) && !(this.shiftFor && Number(t.id) === Number(this.shiftFor.table_id)));
        },
        async doShift(t) {
            if (this.shiftBusy || !this.shiftFor) return;
            this.shiftBusy = true;
            try {
                const res = await fetch('/pos/waiter/orders/' + this.shiftFor.id + '/shift-table', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ table_id: t.id }),
                });
                const data = await res.json().catch(() => null);
                if (data && data.success) {
                    this.showToast(data.message || (@js(__('pos.order_shifted_to_prefix')) + t.table_number + @js(__('pos.order_shifted_to_suffix'))), 'success');
                    this.shiftFor = null;
                    this.loadMyOrders();
                } else {
                    this.showToast((data && data.message) || @js(__('pos.shift_failed')), 'error');
                }
            } catch (e) {
                this.showToast(@js(__('pos.network_error_try_again')), 'error');
            }
            this.shiftBusy = false;
        },

        async send() {
            if (this.sending || !this.cart.length) return;
            this.sending = true;
            const items = this.cart.map(l => ({
                name: l.name, quantity: l.quantity, unit_price: l.unit_price,
                item_id: l.item_id, special_notes: l.special_notes || null,
            }));
            const url = this.appendOrderId
                ? '/pos/waiter/orders/' + this.appendOrderId + '/items'
                : '/pos/waiter/orders';
            const body = this.appendOrderId ? { items } : {
                items,
                cashier_id: this.cashierId || null,
                order_type: this.orderType,
                table_id: this.orderType === 'dine_in' ? (this.selectedTable?.id || null) : null,
                customer_name: this.customerName || null,
                customer_phone: this.customerPhone || null,
                kitchen_notes: this.kitchenNotes || null,
            };
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify(body),
                });
                const data = await res.json().catch(() => null);
                if (!res.ok || !data || !data.success) {
                    this.showToast((data && data.message) || (@js(__('pos.failed_http_prefix')) + res.status + ')'), 'error');
                } else {
                    this.showToast(data.message || @js(__('pos.sent_excl')), 'success');
                    this.cart = [];
                    this.customerName = ''; this.customerPhone = ''; this.kitchenNotes = '';
                    this.selectedTable = null;
                    this.appendOrderId = null; this.appendOrderNumber = '';
                    this.loadMyOrders();
                }
            } catch (e) {
                this.showToast(@js(__('pos.network_error_try_again')), 'error');
            }
            this.sending = false;
        },

        showToast(msg, type = 'success') {
            this.toast = msg;
            this.toastType = type;
            clearTimeout(this._toastTimer);
            this._toastTimer = setTimeout(() => { this.toast = ''; }, 3000);
        },
    };
}
</script>
</x-pos-layout>
