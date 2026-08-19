<x-pos-layout>
@php
    // Per-waiter style pref (owner, 5 Aug 2026): waiter apni marzi se Full/Saaf.
    // Effective = user's own pick (BOTH-direction override), else company style.
    // WAITER-ONLY: admins/managers previewing this tablet keep the company style.
    $waiterIsWaiterRole = (auth('pos')->user()->pos_role ?? null) === 'pos_waiter';
    $waiterOwnStyle = $waiterIsWaiterRole ? (auth('pos')->user()->pos_personal_style ?? null) : null;
    $waiterEffStyle = in_array($waiterOwnStyle, array_keys(\App\Models\User::WAITER_STYLES), true)
        ? $waiterOwnStyle
        : (optional(\App\Models\Company::find(app('currentCompanyId')))->pos_dashboard_style ?? 'default');
    // Fast Food mode (Pizza Master, 10 Aug 2026): speed-first LAYOUT — customer
    // name, Urgent aur "Mazeed" fold GAYAB; sirf table + items + ek note box +
    // send. Koi feature DELETE nahi hua — Full/Saaf theme par sab wapis aa
    // jata hai (per-waiter pick, Theme button).
    $waiterSimple = ($waiterEffStyle === 'fastfood');
@endphp
@if($waiterEffStyle === 'saaf')<link rel="stylesheet" href="{{ asset('css/pos-saaf.css') }}?v=5">@endif
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
            {{-- Per-waiter style switch (owner, 5 Aug 2026): waiter apni marzi se
                 Saaf/Full chun sake — sirf ISI waiter ki screen badalti hai,
                 dukan ki setting ko haath nahi lagta. Waiter-only (403 on server). --}}
            {{-- Theme picker button (owner, 8 Aug 2026): purani 3-button patti
                 hata di — ab ek "Theme" button modal kholta hai jis mein SAB
                 available themes list hoti hain (User::WAITER_STYLES se), taake
                 nayi theme banate hi khud-ba-khud yahan aa jaye. --}}
            @if($waiterIsWaiterRole)
            <button type="button" @click="showThemeTab = true" title="{{ __('pos.ti_waiter_style') }}"
                    class="px-3 py-2 rounded-xl text-xs font-bold bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-teal-500 transition flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.098 19.902a3.75 3.75 0 005.304 0l6.401-6.402M6.75 21A3.75 3.75 0 013 17.25V4.125C3 3.504 3.504 3 4.125 3h5.25c.621 0 1.125.504 1.125 1.125v4.072M6.75 21a3.75 3.75 0 003.75-3.75V8.197M6.75 21h13.125c.621 0 1.125-.504 1.125-1.125v-5.25c0-.621-.504-1.125-1.125-1.125h-4.072M10.5 8.197l2.88-2.88c.438-.439 1.15-.439 1.59 0l3.712 3.713c.44.44.44 1.152 0 1.59l-2.879 2.88M6.75 17.25h.008v.008H6.75v-.008z"/></svg>
                {{ __('pos.waiter_theme_btn') }}
            </button>
            @endif
            {{-- Waiter APK download (Aug 2026) — cookie-less public static file,
                 same pattern as Rider APK on rider-tracking page. --}}
            @php
                // Task #463: show latest APK version next to the download button so
                // waiters can tell if their installed app is outdated. Same key
                // /api/app-version?app=waiter serves; empty = show nothing.
                $waiterApkVersion = trim((string) \App\Models\SystemSetting::get('waiter_app_latest_version', ''));
                // Task #470: mirror the /download page gating — hide the button
                // entirely when the APK file isn't uploaded yet, so waiters never
                // hit a 404 from a dead link.
                $waiterApkExists = is_file(public_path('downloads/taxnest-waiter.apk'));
            @endphp
            @if($waiterApkExists)
            <a href="{{ url('/downloads/taxnest-waiter.apk') }}"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200">
                ⬇ {{ __('pos.waiter_app_download') }}@if($waiterApkVersion !== '') <span class="font-normal text-gray-500 dark:text-gray-400">v{{ $waiterApkVersion }}</span>@endif
            </a>
            @endif
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
        <span class="text-sm font-bold text-amber-800 dark:text-amber-300">{{ __('pos.adding_items_to') }} <span class="font-mono" x-text="appendOrderNumber"></span> <span x-show="appendTableLabel" x-cloak class="inline-block align-middle text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-200 text-amber-900 dark:bg-amber-800/60 dark:text-amber-200" x-text="'T-' + appendTableLabel"></span> <span x-show="appendCustomerLabel" x-cloak class="inline-block align-middle text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-200 text-amber-900 dark:bg-amber-800/60 dark:text-amber-200" x-text="appendCustomerLabel"></span> {{ __('pos.only_new_items_print') }}</span>
        <button @click="cancelAppend()" class="px-3 py-1.5 rounded-lg bg-white dark:bg-gray-800 text-xs font-bold text-gray-600 dark:text-gray-300 border border-gray-300 dark:border-gray-600">{{ __('pos.cancel') }}</button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- ── LEFT: product picker (ZFC issue #11, 28 Jul 2026: on MOBILE the
             punched ORDER shows on TOP, search/grid below — waiter had to scroll
             after every item; desktop stays picker-left / order-right) ──────── --}}
        <div class="lg:col-span-2 order-2 lg:order-1">

@if($waiterEffStyle === 'buttons')
            {{-- ── BUTTONS HOME (Task #340, Aug 2026): big tap targets for each
                 table + a Parcel button. Replaces the product grid on first load.
                 Tables are fetched eagerly on init and polled every 30 s. ──── --}}
            <div x-show="buttonsView && !appendOrderId" class="space-y-2.5">

                {{-- Loading state --}}
                <div x-show="tablesLoading && tables.length === 0" class="text-center py-10 text-sm text-gray-400">
                    {{ __('pos.waiter_buttons_loading') }}
                </div>

                {{-- Occupied tables (running orders) — red, timer + count badge --}}
                <template x-for="t in tablesOccupied()" :key="'bocc-' + t.id">
                    <button @click="selectButtonTable(t)"
                            class="w-full flex items-center justify-between rounded-2xl bg-red-50 dark:bg-red-900/20 border-2 border-red-300 dark:border-red-700 px-5 py-4 text-left transition active:scale-[.98]">
                        <span class="text-lg font-black text-red-700 dark:text-red-300"
                              x-text="'{{ __('pos.table_t_prefix2') }}' + t.table_number + ' · ' + t.floor"></span>
                        <span class="flex items-center gap-3">
                            <span x-show="elapsedSince(t.occupied_since)"
                                  class="text-sm font-bold text-red-400 dark:text-red-400"
                                  x-text="'⏱ ' + elapsedSince(t.occupied_since)"></span>
                            <span x-show="t.active_orders > 0"
                                  class="min-w-[28px] h-7 px-2 rounded-full bg-red-600 text-white text-sm font-black flex items-center justify-center"
                                  x-text="t.active_orders"></span>
                        </span>
                    </button>
                </template>

                {{-- Free / reserved tables — green --}}
                <template x-for="t in tablesAvailable()" :key="'bfree-' + t.id">
                    <button @click="selectButtonTable(t)"
                            class="w-full flex items-center justify-between rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 border-2 border-emerald-300 dark:border-emerald-700 px-5 py-4 text-left transition active:scale-[.98]">
                        <span class="text-lg font-black text-emerald-700 dark:text-emerald-300"
                              x-text="'{{ __('pos.table_t_prefix2') }}' + t.table_number + ' · ' + t.floor"></span>
                        <span class="text-xs font-bold uppercase text-emerald-500 dark:text-emerald-400" x-text="t.status"></span>
                    </button>
                </template>

                {{-- No tables note (shown once loading is done and list is empty) --}}
                <p x-show="!tablesLoading && tables.length === 0"
                   class="text-center text-sm text-gray-400 py-4">
                    {{ __('pos.waiter_buttons_free_note') }}
                </p>

                {{-- Parcel button — amber, badge = my open takeaway/delivery orders.
                     Task #342 (Aug 2026): tap = inline sub-list of open parcel orders
                     (tap to append, tables jaisa flow) + "+ Naya Parcel Order". --}}
                {{-- Task 626 (owner, 13 Aug 2026): takeaway OFF = waiter app par
                     takeaway ka KOI UI element nahi — Parcel button + sub-list
                     poori tarah chhupe, chahe purane khule parcel orders hon
                     (Task 527 ka append-allow rasta owner ke faisle par band).
                     Purane orders ka settle path cashier/counter side barqarar. --}}
                @if($waiterCanTakeaway ?? true)
                <button @click="selectButtonParcel()"
                        x-show="canTakeaway || parcelOrders().length > 0"
                        class="w-full flex items-center justify-between rounded-2xl bg-amber-50 dark:bg-amber-900/20 border-2 border-amber-400 dark:border-amber-600 px-5 py-4 transition active:scale-[.98]">
                    <span class="text-lg font-black text-amber-700 dark:text-amber-300">
                        📦 {{ __('pos.waiter_buttons_parcel') }}
                    </span>
                    <span class="flex items-center gap-2">
                        <span x-show="parcelOrders().length > 0"
                              class="min-w-[28px] h-7 px-2 rounded-full bg-amber-500 text-white text-sm font-black flex items-center justify-center"
                              x-text="parcelOrders().length"></span>
                        <span x-show="parcelOrders().length > 0" x-cloak
                              class="text-amber-500 dark:text-amber-400 text-sm font-black"
                              x-text="parcelListOpen ? '▲' : '▼'"></span>
                    </span>
                </button>

                {{-- Inline open-parcel-orders sub-list (Task #342) --}}
                <div x-show="parcelListOpen" x-cloak class="space-y-2 pl-3 border-l-4 border-amber-300 dark:border-amber-700 ml-2">
                    <template x-for="o in parcelOrders()" :key="'bparcel-' + o.id">
                        <button @click="startAppendParcel(o)"
                                class="w-full flex items-center justify-between rounded-2xl bg-white dark:bg-gray-800 border-2 border-amber-200 dark:border-amber-800 px-4 py-3 text-left transition active:scale-[.98]">
                            <span class="min-w-0">
                                <span class="block font-mono text-sm font-black text-amber-700 dark:text-amber-300" x-text="o.order_number"></span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400 truncate"
                                      x-text="o.items.map(it => it.quantity + '× ' + it.name).join(' · ')"></span>
                            </span>
                            <span class="text-sm font-black text-gray-900 dark:text-white whitespace-nowrap pl-2"
                                  x-text="'Rs ' + Math.round(o.total_amount).toLocaleString()"></span>
                        </button>
                    </template>
                    <button @click="startNewParcel()"
                            class="w-full rounded-2xl bg-amber-500 hover:bg-amber-600 text-white px-4 py-3 text-sm font-black transition active:scale-[.98]">
                        {{ __('pos.waiter_buttons_new_parcel') }}
                    </button>
                </div>
                @endif
            </div>

            {{-- Back button (buttons mode — shown when grid is active, not in append) --}}
            <div x-show="!buttonsView && !appendOrderId" x-cloak class="mb-3">
                <button @click="buttonsView = true"
                        class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-bold bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-teal-400 transition">
                    {{ __('pos.waiter_buttons_back') }}
                </button>
            </div>

            {{-- Product grid — hidden until a table/parcel is chosen (or in append mode) --}}
            <div x-show="!buttonsView || appendOrderId" x-cloak>
@else
            {{-- Non-buttons styles: product grid always visible --}}
            <div>
@endif
                {{-- SEARCH + SUGGESTION DROPDOWN (Pizza Master waiter request, 8 Aug
                     2026): jaise hi type karein, milte-julte items ki list SEEDHI
                     search box ke neeche — tap = cart mein. Grid neeche pehle ki
                     tarah filter hota rehta hai; yeh sirf scroll bachata hai.
                     Enter = pehla suggestion add (scanner/keyboard waiters).
                     Inline styles jaan-boojh kar: arbitrary Tailwind classes bina
                     `npm run build` ke invisible ho jati hain (memory). --}}
                <div class="relative mb-3" @click.outside="searchDropOpen = false">
                    <input type="text" x-model="search" @input="searchDropOpen = true; filterProducts()"
                           @focus="if (search.trim()) searchDropOpen = true"
                           @keydown.enter.prevent="pickFirstSuggestion()"
                           @keydown.escape="searchDropOpen = false"
                           autocomplete="off" name="waiter_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                           placeholder="{{ __('pos.ph_search_items') }}"
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-base px-4 py-3 focus:ring-teal-500 focus:border-teal-500">
                    <div x-show="searchDropOpen && search.trim() !== '' && !gridEditMode" x-cloak
                         style="position:absolute; left:0; right:0; top:100%; margin-top:4px; z-index:40; max-height:45vh; overflow-y:auto;"
                         class="rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-lg">
                        <template x-for="(p, si) in suggestions" :key="p.id">
                            <button type="button" @click="pickSuggestion(p)"
                                    class="w-full flex items-center justify-between gap-3 px-4 py-3 text-left border-b border-gray-100 dark:border-gray-700 hover:bg-teal-50 dark:hover:bg-teal-900/20 transition"
                                    :class="si === 0 ? 'bg-teal-50/60 dark:bg-teal-900/10' : ''">
                                <span class="text-sm font-bold text-gray-800 dark:text-gray-100 leading-snug" x-text="p.name"></span>
                                <span class="text-xs font-black text-teal-700 dark:text-teal-400 whitespace-nowrap" x-text="'Rs ' + p.price.toLocaleString()"></span>
                            </button>
                        </template>
                        <div x-show="suggestions.length === 0" class="px-4 py-3 text-sm text-gray-400">{{ __('pos.no_items_match') }}</div>
                    </div>
                </div>
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
                <div class="tn-waiter-grid grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2.5 max-h-[60vh] overflow-y-auto pr-1">
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
        </div>

        {{-- ── RIGHT: order panel (mobile = TOP, see issue #11 note above) ── --}}
        <div class="order-1 lg:order-2 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-col gap-3 h-fit lg:sticky lg:top-4">
            <h2 class="text-sm font-black uppercase tracking-wide text-gray-500 dark:text-gray-400" x-text="appendOrderId ? {{ Js::from(__('pos.new_items')) }} : {{ Js::from(__('pos.order_word')) }}"></h2>

            {{-- SADA MODE (owner, 4 Aug 2026): table SAB SE PEHLE — bada button
                 panel ke top par (mobile par panel khud top par hai). Dine-in
                 default; Takeaway ab "Mazeed" fold ke andar hai (hataya NahiN). --}}
            <button x-show="!appendOrderId && orderType === 'dine_in'" @click="openTables()"
                    class="w-full py-3 rounded-xl text-base font-black border-2 border-dashed transition"
                    :class="selectedTable ? 'border-teal-500 bg-teal-50 dark:bg-teal-900/20 text-teal-700 dark:text-teal-300' : 'border-amber-400 bg-amber-50 dark:bg-amber-900/15 text-amber-700 dark:text-amber-300'"
                    x-text="selectedTable ? ({{ Js::from(__('pos.table_t_prefix2')) }} + selectedTable.table_number + ' · ' + selectedTable.floor) : {{ Js::from(__('pos.choose_table')) }}"></button>

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
                        {{-- Task 632 (ZFC "NOTE: waiter"): mobile Chrome/keyboard ignores
                             autocomplete="off" on text inputs and autofills the saved login
                             (username "waiter" landed in an item note). one-time-code is the
                             strongest suppressor per the anti-autofill guard set. --}}
                        <input type="text" x-model="line.special_notes" placeholder="{{ __('pos.ph_note_for_kitchen') }}"
                               autocomplete="one-time-code" :name="'waiter_note_' + i + '_nofill'" data-lpignore="true" data-form-type="other" data-1p-ignore
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
                     NOT RENDERED for tax-inclusive companies (ZFC issue #13; re-broken
                     & re-fixed 5 Aug 2026): the old inline style="display:none" was
                     WIPED by Alpine x-show=true (x-show owns el.style.display), so the
                     line reappeared. Blade @if = server-side, Alpine can't resurrect it. --}}
                @if (!($taxInclusive ?? false))
                <div class="flex items-center justify-between mt-0.5" x-show="taxEstimate() > 0">
                    <span class="text-[11px] font-semibold text-gray-400 dark:text-gray-500" x-text="{{ Js::from(__('pos.approx_incl_tax_cash')) }} + cashTaxRate + '%)'"></span>
                    <span class="text-sm font-bold text-gray-600 dark:text-gray-300" x-text="'Rs ' + (total() + taxEstimate()).toLocaleString()"></span>
                </div>
                @endif
            </div>

            {{-- Order details (hidden in append mode — the order already has them).
                 SADA MODE (owner, 4 Aug 2026): sirf customer ka naam khula rahta
                 hai; Takeaway toggle, phone, order-note aur cashier chunna sab
                 "Mazeed" fold ke andar — koi feature HATAYA nahi, sirf chhupaya. --}}
            <template x-if="!appendOrderId">
                <div class="space-y-3">
                    @if($waiterSimple)
                    {{-- FAST FOOD MODE: sirf ek note box (kam mirch waghera) — naam,
                         urgent, mazeed sab chhupa. Order = dine-in + counter default. --}}
                    <textarea x-model="kitchenNotes" rows="2" placeholder="{{ __('pos.ph_kitchen_note_order') }}"
                              autocomplete="off" name="waiter_kn_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                              class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm px-3 py-2.5 focus:ring-teal-500 focus:border-teal-500"></textarea>
                    @else
                    <input type="text" x-model="customerName" placeholder="{{ __('pos.ph_customer_name_optional') }}"
                           autocomplete="off" name="waiter_customer_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm px-3 py-2.5 focus:ring-teal-500 focus:border-teal-500">

                    {{-- Urgent/Rush toggle (owner voice note, 7 Aug 2026): waiter ke
                         paas yeh option tha hi nahi — woh nav ka ⚡ logo dabate rahe.
                         Ab wazeh ON/OFF: ON = solid red + ✓ badge, OFF = gray. Sale
                         screen ke priorityOrder jaisa hi flag → KDS badge + KOT
                         *** URGENT *** block khud chalte hain. --}}
                    <button type="button" @click="priority = !priority"
                            class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-black border-2 transition"
                            :class="priority ? 'bg-red-600 border-red-600 text-white shadow-md' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400'">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <span x-text="priority ? ('✓ ' + {{ Js::from(__('pos.rush_badge')) }}) : {{ Js::from(__('pos.rush_title')) }}"></span>
                    </button>

                    <button type="button" @click="moreOpen = !moreOpen" title="{{ __('pos.ti_more_options') }}"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-bold border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-teal-400 transition">
                        <span x-text="(moreOpen ? '▾ ' : '▸ ') + {{ Js::from(__('pos.waiter_more_label')) }}"></span>
                        {{-- Fold band ho tab bhi pata rahe ke andar kuch chuna hua hai --}}
                        <span x-show="!moreOpen && (orderType === 'takeaway' || cashierId)" x-cloak class="text-[10px] font-black text-amber-600 dark:text-amber-400"
                              x-text="[orderType === 'takeaway' ? {{ Js::from(__('pos.take_away')) }} : null, cashierId ? {{ Js::from(__('pos.send_to')) }} + ' ✓' : null].filter(Boolean).join(' · ')"></span>
                    </button>

                    <div x-show="moreOpen" x-cloak class="space-y-3">
                        {{-- Task 527: takeaway punch admin-controlled (default ON).
                             Band ho to type-picker hi nahi — waiter dine-in par
                             locked rehta hai (server order_type=takeaway 403 karta hai). --}}
                        @if($waiterCanTakeaway ?? true)
                        <div class="grid grid-cols-2 gap-2">
                            <button @click="orderType = 'dine_in'" :class="orderType === 'dine_in' ? 'bg-teal-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'" class="py-2.5 rounded-xl text-xs font-bold transition">{{ __('pos.dine_in') }}</button>
                            <button @click="orderType = 'takeaway'; selectedTable = null" :class="orderType === 'takeaway' ? 'bg-teal-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'" class="py-2.5 rounded-xl text-xs font-bold transition">{{ __('pos.take_away') }}</button>
                        </div>
                        @endif
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
                    @endif
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
                             lagaye orders bhi). Compose ke liye ab bhi sirf khali/reserved.
                             ZFC 6 Aug 2026: occupied tile AB HAMESHA tap-able — held
                             order na bhi ho to action modal khul kar table ke maujooda
                             items READ-ONLY dikhata hai (counter/desktop ke orders bhi). --}}
                        <button @click="t.status === 'occupied' ? (tableActionFor = t) : pickTable(t)"
                                :class="t.status === 'occupied' ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-500 dark:text-red-300' : (t.status === 'reserved' ? 'bg-amber-50 dark:bg-amber-900/20 border-amber-300 dark:border-amber-700 text-amber-700 dark:text-amber-300' : 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-300 dark:border-emerald-700 text-emerald-700 dark:text-emerald-300 hover:border-emerald-500')"
                                class="rounded-xl border-2 p-3 text-center transition">
                            <span class="block text-base font-black" x-text="'T-' + t.table_number"></span>
                            <span class="block text-[10px] font-bold mt-0.5" x-text="t.floor + ' · ' + t.seats + {{ Js::from(__('pos.sfx_seats')) }}"></span>
                            <span class="block text-[10px] font-bold uppercase mt-0.5" x-text="t.status"></span>
                            {{-- Occupied timer (owner, 7 Aug 2026) — desktop picker
                                 parity: kitni der se table chal raha hai. --}}
                            <span x-show="t.status === 'occupied' && elapsedSince(t.occupied_since)" class="block text-[10px] font-bold mt-0.5 text-red-500 dark:text-red-300" x-text="'⏱ ' + elapsedSince(t.occupied_since)"></span>
                            <span x-show="t.status === 'occupied' && t.order_id" class="block text-[10px] font-black uppercase mt-1 px-1.5 py-0.5 rounded-full bg-red-100 dark:bg-red-900/40 text-red-600 dark:text-red-300">{{ __('pos.shift_word') }} ⇄</span>
                        </button>
                    </template>
                </div>
                <div x-show="!tablesLoading && tables.length === 0" class="text-center py-8 text-sm text-gray-400">{{ __('pos.no_tables_configured_dot') }}</div>
            </div>
        </div>
    </div>

    {{-- ── Occupied-table action chooser (ZFC, 1 Aug 2026): Add Items ya Shift ──
         ZFC 6 Aug 2026: upar READ-ONLY preview — table ke saare active orders ke
         items (counter/desktop ke lagaye bhi) taake waiter ko pata ho table par
         kya chal raha hai. Add/Shift buttons sirf HELD order par (pehle jaisa). --}}
    <div x-show="tableActionFor" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60" @click="tableActionFor = null"></div>
        <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm max-h-[80vh] flex flex-col overflow-hidden">
            <div class="px-5 py-4 bg-teal-600 flex items-center justify-between">
                <h3 class="text-white font-bold" x-text="tableActionFor ? ('T-' + tableActionFor.table_number + (tableActionFor.order_number ? ' — ' + tableActionFor.order_number : '')) : ''"></h3>
                <button @click="tableActionFor = null" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/25 text-white font-black">×</button>
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-2.5">
                <template x-if="tableActionFor && (tableActionFor.orders_preview || []).length > 0">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1.5">{{ __('pos.already_on_table') }}</p>
                        <div class="space-y-2">
                            <template x-for="o in tableActionFor.orders_preview" :key="'tprev' + o.id">
                                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 p-2.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-mono text-xs font-bold text-gray-800 dark:text-gray-100" x-text="o.order_number"></span>
                                        <span class="flex items-center gap-1.5">
                                            <span class="text-[9px] font-black uppercase px-1.5 py-0.5 rounded-full bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300" x-text="o.status"></span>
                                            <span class="text-xs font-black text-gray-900 dark:text-white" x-text="'Rs ' + Math.round(o.total_amount || 0).toLocaleString()"></span>
                                        </span>
                                    </div>
                                    <div class="mt-1 text-[11px] text-gray-600 dark:text-gray-300 leading-relaxed">
                                        <template x-for="(it, ix) in o.items" :key="'tprevit' + o.id + '-' + ix"><span><span x-text="it.quantity + '× ' + it.name"></span><span x-show="ix < o.items.length - 1"> · </span></span></template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
                {{-- Held order na ho (preparing/ready) to Add/Shift possible nahi —
                     buttons chhupa kar sirf preview; warna waiter dead-tap khata. --}}
                <template x-if="tableActionFor && tableActionFor.order_id">
                    <div class="space-y-2.5">
                        <button @click="startAppendFromTable(tableActionFor)" class="w-full py-3 rounded-xl bg-teal-600 hover:bg-teal-700 text-white font-bold text-sm">{{ __('pos.add_items') }} +</button>
                        <button @click="startShiftFromTable(tableActionFor); tableActionFor = null" class="w-full py-3 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold text-sm">{{ __('pos.shift_word') }} ⇄</button>
                    </div>
                </template>
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
                            {{-- Task 626: takeaway OFF = parcel (non-dine-in) orders par
                                 waiter ka Add Items rasta bhi band — order dikh jata hai
                                 (status/settle cashier side), append nahi hota. --}}
                            <button @click="startAppend(o)" x-show="canTakeaway || o.order_type === 'dine_in'" class="px-4 py-2 rounded-lg bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold transition">{{ __('pos.add_items') }}</button>
                            {{-- Table Shift (owner batch, 26 Jul 2026): sirf dine-in
                                 orders (table wale); khali table par hi jayega. --}}
                            <button x-show="o.table_id" @click="startShift(o)" class="px-4 py-2 rounded-lg bg-white dark:bg-gray-800 border border-teal-300 dark:border-teal-700 text-teal-700 dark:text-teal-300 hover:bg-teal-50 text-xs font-bold transition">⇄ {{ __('pos.change_table') }}</button>
                            {{-- Waiter self-cancel (Task 412): sirf UN-CLAIMED order
                                 (assigned_cashier_id null) — cashier ke claim/settle
                                 ke baad cancel counter se hi hota hai.
                                 Task 527: admin-controlled permission (default OFF) —
                                 band ho to button hi nahi (server bhi 403 deta hai). --}}
                            @if($waiterCanCancel ?? false)
                            <button x-show="!o.assigned_cashier_id" @click="cancelAsk = o" class="px-4 py-2 rounded-lg bg-white dark:bg-gray-800 border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 hover:bg-red-50 text-xs font-bold transition">✕ {{ __('pos.cancel') }}</button>
                            @endif
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- ── Waiter self-cancel confirm (Task 412): KOT-warning wali cashier-modal
         jaisi tasdeeq — waiter apna un-settled order cancel kare. ───────────── --}}
    <div x-show="cancelAsk" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60" @click="if (!cancelBusy) cancelAsk = null"></div>
        <template x-if="cancelAsk">
            <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
                <div class="px-5 pt-5 text-center">
                    <div class="mx-auto w-12 h-12 rounded-full bg-red-100 dark:bg-red-900/40 flex items-center justify-center mb-2">
                        <svg class="w-6 h-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.947-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z"/></svg>
                    </div>
                    <p class="text-base font-black text-gray-900 dark:text-white">{{ __('pos.cancel_order_title') }}</p>
                    <p class="text-lg font-black text-gray-900 dark:text-white mt-0.5"><span class="font-mono" x-text="cancelAsk.order_number"></span> <span x-show="cancelAsk.table" x-text="'• T-' + cancelAsk.table"></span> <span x-text="'• Rs ' + Math.round(cancelAsk.total_amount).toLocaleString()"></span></p>
                </div>
                <div class="px-5 py-3 max-h-40 overflow-y-auto">
                    <div class="space-y-1">
                        <template x-for="(it, ix) in cancelAsk.items" :key="'cx' + ix">
                            <div class="flex items-center justify-between gap-2 text-xs text-gray-700 dark:text-gray-300">
                                <span class="flex-1" x-text="it.quantity + ' × ' + it.name"></span>
                                <span class="text-gray-400" x-text="Math.round(it.quantity * it.unit_price).toLocaleString()"></span>
                            </div>
                        </template>
                    </div>
                    <div x-show="cancelAsk.kot_sent_at" class="mt-3 px-3 py-2 rounded-lg bg-orange-50 dark:bg-orange-900/20 border border-orange-300 dark:border-orange-700">
                        <p class="text-[11px] font-bold text-orange-700 dark:text-orange-300">&#9888;&#65039; {{ __('pos.cancel_kot_warning') }}</p>
                    </div>
                    <p x-show="!cancelAsk.kot_sent_at" class="mt-3 text-[11px] text-gray-400 text-center">{{ __('pos.cancel_no_kot_note') }}</p>
                </div>
                <div class="p-4 grid grid-cols-1 gap-2">
                    <button @click="cancelAsk = null" :disabled="cancelBusy" class="w-full py-2.5 rounded-xl text-sm font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 transition">{{ __('pos.cancel_keep_order') }}</button>
                    <button @click="confirmCancel()" :disabled="cancelBusy" class="w-full py-2.5 rounded-xl text-sm font-extrabold text-white bg-red-600 hover:bg-red-700 disabled:opacity-40 transition"><span x-show="!cancelBusy">{{ __('pos.cancel_yes_free') }}</span><span x-show="cancelBusy">…</span></button>
                </div>
            </div>
        </template>
    </div>

    {{-- ── Theme picker modal (owner, 8 Aug 2026): waiter apni marzi ki theme
         chune — list User::WAITER_STYLES se render hoti hai (single source of
         truth), is liye nayi theme add karte hi yahan khud aa jayegi. Sirf ISI
         waiter ki screen badalti hai (server 403 for non-waiters). ─────────── --}}
    @if($waiterIsWaiterRole)
    <div x-show="showThemeTab" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60" @click="showThemeTab = false"></div>
        <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm flex flex-col overflow-hidden">
            <div class="px-5 py-4 bg-teal-600 flex items-center justify-between">
                <h3 class="text-white font-bold">{{ __('pos.waiter_theme_pick_title') }}</h3>
                <button @click="showThemeTab = false" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/25 text-white font-black">×</button>
            </div>
            <div class="p-4 space-y-2.5">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.ti_waiter_style') }}</p>
                @foreach(\App\Models\User::WAITER_STYLES as $wtKey => $wtLangKey)
                <button type="button" @click="saveStyle('{{ $wtKey }}')" :disabled="styleBusy"
                        class="w-full flex items-center justify-between rounded-xl border-2 px-4 py-3.5 text-left transition active:scale-[.98] disabled:opacity-50 {{ $waiterEffStyle === $wtKey ? 'border-teal-500 bg-teal-50 dark:bg-teal-900/20' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-teal-300' }}">
                    <span class="text-sm font-bold {{ $waiterEffStyle === $wtKey ? 'text-teal-700 dark:text-teal-300' : 'text-gray-700 dark:text-gray-200' }}">{{ __($wtLangKey) }}</span>
                    @if($waiterEffStyle === $wtKey)
                    <span class="w-6 h-6 rounded-full bg-teal-600 text-white text-xs font-black flex items-center justify-center">✓</span>
                    @endif
                </button>
                @endforeach
            </div>
        </div>
    </div>
    @endif

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
        styleBusy: false,
        cashTaxRate: {{ (float) ($cashTaxRate ?? 16) }},
        filtered: [],
        categories: [],
        search: '',
        activeCategory: 'all',
        // Suggestion dropdown under the search box (Pizza Master waiter, 8 Aug 2026).
        searchDropOpen: false,
        get suggestions() { return this.filtered.slice(0, 8); },
        pickSuggestion(p) {
            if (this.gridEditMode) return;
            this.addToCart(p);
            this.search = '';
            this.searchDropOpen = false;
            this.filterProducts();
            // $refs from inside x-for sees only the row's refs (memory) — grab the
            // live input by name so the waiter can type the next item fori
            // (foran) — pick, type, pick — bina dobara tap kiye.
            const el = document.querySelector('input[name="waiter_search_nofill"]');
            if (el) el.focus();
        },
        pickFirstSuggestion() {
            if (!this.search.trim()) return;
            const first = this.suggestions[0];
            if (first) this.pickSuggestion(first);
        },
        cart: [],
        orderType: 'dine_in',
        // Task 527: takeaway punch admin-controlled — false = parcel/takeaway
        // flows locked (buttons style Parcel gating + startNewParcel guard).
        canTakeaway: {{ ($waiterCanTakeaway ?? true) ? 'true' : 'false' }},
        // SADA MODE (owner, 4 Aug 2026): Takeaway/phone/note/cashier "Mazeed"
        // fold ke andar — default band, order bhejne par wapas band.
        moreOpen: false,
        selectedTable: null,
        customerName: '',
        customerPhone: '',
        kitchenNotes: '',
        // Urgent/Rush order (owner, 7 Aug 2026) — KDS + KOT priority flag.
        priority: false,
        cashierId: '',
        sending: false,
        // Task 1010: one UUID per new punch. It stays set after a timeout or
        // network error so the next tap replays the same server-side attempt
        // instead of creating a twin held order/KOT.
        holdAttemptUuid: null,
        showTables: false,
        tables: [],
        tablesLoading: false,
        _tableEtag: null,
        // Buttons style (Task #340, Aug 2026): home button list vs product grid.
        // true = show the home button list; false = show the product grid.
        // Starts true only in buttons mode (PHP-baked); in other styles never used.
        buttonsView: {{ $waiterEffStyle === 'buttons' ? 'true' : 'false' }},
        // PHP-baked JS boolean (Task #340). Lets init() and send() branch on mode
        // using plain JS — no Blade directives needed inside the script block.
        // The test double-brace stripper replaces this with 0, so buttons branches
        // are skipped harmlessly in the node --check harness.
        _buttonsMode: {{ $waiterEffStyle === 'buttons' ? 'true' : 'false' }},
        showMyOrders: false,
        showThemeTab: false,
        myOrders: [],
        myOrdersLoading: false,
        appendOrderId: null,
        appendOrderNumber: '',
        appendTableLabel: '',    // Task 526: append banner mein table number dikhao (parcel = khali)
        appendCustomerLabel: '', // Task 530: parcel append par customer naam ya daily token (dine-in = khali)
        parcelListOpen: false,   // Task #342: inline parcel sub-list on buttons home
        tableActionFor: null,    // occupied-tile chooser (Add Items / Shift)
        shiftFor: null,          // Table Shift (26 Jul 2026): order being shifted
        shiftPickFor: null,      // Multi-order shift (Task 104): table whose held order is being chosen
        appendPickFor: null,     // Multi-order Add Items (Task 108): table whose held order is being chosen
        shiftBusy: false,
        cancelAsk: null,        // Task 412: waiter self-cancel confirm modal (order object)
        cancelBusy: false,
        shiftTablesLoading: false,
        toast: '',
        toastType: 'success',
        _toastTimer: null,
        // Auto-update (ZFC, 29 Jul 2026): page ki code-version; server se poll
        // kar ke naya deploy pakarte hain.
        appVersion: @json($appVersion ?? 'unknown'),
        updateAvailable: false,
        // Occupied-timer tick (7 Aug 2026): elapsedSince() labels refresh live.
        nowTick: Date.now(),

        init() {
            this.categories = [...new Set(this.products.map(p => p.category))].sort();
            this.filterProducts();
            this.initDayCashier();
            this.loadMyOrders();
            setInterval(() => { if (!document.hidden) this.loadMyOrders(); }, 30000);
            // Occupied-timer tick (7 Aug 2026): 30s refresh for elapsedSince labels.
            setInterval(() => { this.nowTick = Date.now(); }, 30000);
            // Version check: every 2 min + whenever the phone comes back to the tab.
            setInterval(() => this.checkVersion(), 120000);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) this.checkVersion(); });
            this.checkVersion();
            // Buttons style (Task #340, Aug 2026): eagerly fetch tables on boot so
            // the home button list is populated immediately. Poll every 30 s (same
            // cadence as the sale-screen table board) to keep counts + timers live.
            // _buttonsMode is PHP-baked — plain JS branch, no Blade directive needed.
            if (this._buttonsMode) {
                this.reloadTablesQuiet();
                setInterval(() => { if (!document.hidden) this.reloadTablesQuiet(); }, 30000);
            }
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
        // Product search mode (owner, 4 Aug 2026) — see filterProducts().
        searchAnyWord: {{ ($searchAnyWord ?? false) ? 'true' : 'false' }},
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

        // ---- MULTI-WORD SEARCH (16 Aug 2026 — same matcher as the cashier
        // universal screen; owner rule: ALL sale surfaces search the same way).
        // The query is tokenized; a hit = every typed token prefix-matches a word
        // of the name. Words split on NON-alphanumeric runs so "(Half)" yields
        // "half"; non-ASCII chars (Urdu names) count as word chars.
        searchTokens(s) {
            return String(s || '').toLowerCase().split(/[^a-z0-9\u0080-\uffff]+/).filter(Boolean);
        },
        // Rank a name against the query. 0 = no match; higher = better (contiguous/
        // in-order matches sort above scattered ones so "(Full)"/"(Half)" pairs
        // order sensibly): 4 = name starts with the raw query, 3 = tokens match
        // CONSECUTIVE name words, 2 = in order with gaps, 1 = scattered word hits.
        // anyWord=false keeps the STRICT PREFIX rule (owner, 24 Jul 2026): the
        // FIRST token must match the very START of the name; later tokens are free.
        // Single-word queries behave exactly as before in both modes.
        nameMatchRank(name, q, anyWord) {
            const lname = String(name).toLowerCase();
            if (lname.startsWith(q)) return 4;
            const tokens = this.searchTokens(q);
            if (!tokens.length) return 0;
            if (!anyWord && !lname.startsWith(tokens[0])) return 0;
            const words = this.searchTokens(lname);
            for (let s = 0; s + tokens.length <= words.length; s++) {
                if (tokens.every((t, k) => words[s + k].startsWith(t))) return 3;
            }
            let wi = 0, inOrder = true;
            for (const t of tokens) {
                while (wi < words.length && !words[wi].startsWith(t)) wi++;
                if (wi >= words.length) { inOrder = false; break; }
                wi++;
            }
            if (inOrder) return 2;
            // Scattered: every token prefix-matches a DISTINCT word (longest tokens
            // claim first) — two tokens must never both count the same word, or
            // "chicken ch" would drag "Chicken Roll" into the results.
            const used = new Array(words.length).fill(false);
            const ok = [...tokens].sort((a, b) => b.length - a.length).every(t => {
                for (let j = 0; j < words.length; j++) {
                    if (!used[j] && words[j].startsWith(t)) { used[j] = true; return true; }
                }
                return false;
            });
            return ok ? 1 : 0;
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
            // screen, owner 24 Jul 2026): the FIRST token matches only from the
            // very START of the name ("f" = Fries…, NOT "Beef Loaded Fries").
            // MULTI-WORD (16 Aug 2026): later tokens prefix-match any later word
            // ("cheese loaded half" → "Cheese Loaded Fries (Half)"). Barcode
            // matching only when the query has a digit/symbol.
            // PER-COMPANY SEARCH MODE (owner, 4 Aug 2026): 'any_word' lets every
            // token match any word right away ("win" → "5 Piece Hot Wings").
            // GLOBAL SEARCH (owner, 4 Aug 2026 — same rule as the cashier universal
            // screen, 22 Jul): while the waiter is TYPING a search, the whole catalog
            // is searchable — hidden (show_on_sale=false / user-pref-hidden) items and
            // every category included. Hide/Show only declutters the idle browsing
            // grid; a search must NEVER come up empty because an item was hidden.
            const codeSearch = /[^a-z\s]/.test(q);
            const scoped = q
                ? this.products
                : pool.filter(p => this.activeCategory === 'all' || p.category === this.activeCategory);
            const rank = new Map();
            let hits = scoped.filter(p => {
                if (!q) return true;
                const r = this.nameMatchRank(p.name, q, this.searchAnyWord);
                if (r > 0) { rank.set(p, r); return true; }
                return codeSearch && p.barcode && String(p.barcode).toLowerCase().includes(q);
            });
            // WORD-START FALLBACK (owner, Aug 2026 — Pizza Master: "Win" found nothing
            // because items are named "5 Piece Hot Wings"): ONLY when the strict-first
            // rule yields ZERO results, rescan in any-word mode. A no-op in any_word
            // mode (same predicate already ran).
            if (q && !hits.length) {
                hits = scoped.filter(p => {
                    const r = this.nameMatchRank(p.name, q, true);
                    if (r > 0) { rank.set(p, r); return true; }
                    return false;
                });
            }
            // While searching, better (name-start/contiguous/in-order) ranks float
            // above scattered and barcode-only hits; stable within each rank.
            if (q) hits.sort((a, b) => (rank.get(b) || 0) - (rank.get(a) || 0));
            this.filtered = hits;
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

        // Per-waiter style pref (owner, 5 Aug 2026): apni marzi ka Full/Saaf —
        // save then full reload so the layout re-renders with the new style.
        async saveStyle(style) {
            if (this.styleBusy) return;
            this.styleBusy = true;
            try {
                const res = await fetch('{{ route('pos.waiter.style') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ style }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                window.location.reload();
            } catch (e) {
                this.styleBusy = false;
                this.showToast(@js(__('pos.setting_save_failed')), 'error');
            }
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

        // Elapsed label since a timestamp — "3m" / "1h 20m" / "just now".
        // (Universal sale-screen picker parity; reads nowTick for live refresh.)
        elapsedSince(ts) {
            if (!ts) return '';
            const ms = this.nowTick - new Date(ts).getTime();
            if (isNaN(ms) || ms < 0) return '';
            const mins = Math.floor(ms / 60000);
            if (mins < 1) return 'just now';
            const h = Math.floor(mins / 60), m = mins % 60;
            return h > 0 ? (h + 'h ' + m + 'm') : (m + 'm');
        },
        async openTables() {
            this.showTables = true;
            this.tablesLoading = true;
            try {
                const headers = { 'Accept': 'application/json' };
                if (this._tableEtag) headers['If-None-Match'] = this._tableEtag;
                const res = await fetch('/pos/waiter/api/tables', { headers });
                if (res.status !== 304) {
                    if (!res.ok) {
                        this.tables = [];
                        this._tableEtag = null;
                    }
                    else {
                        const etag = res.headers.get('ETag');
                        if (etag) this._tableEtag = etag;
                        this.tables = await res.json();
                    }
                }
            } catch (e) {
                this.tables = [];
                this._tableEtag = null;
            }
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

        // Waiter self-cancel (Task 412): apna held, abhi-tak-un-settled order
        // cancel karo. Server par atomic conditional update hai — cashier settle
        // kar chuka ho to 409 + friendly message.
        async confirmCancel() {
            if (!this.cancelAsk || this.cancelBusy) return;
            this.cancelBusy = true;

            // Task 925 — Android popup-blocker fix: open a BLANK named window here,
            // synchronously inside the click/tap handler, while the browser still
            // recognises this as a direct user gesture. After the async cancel POST
            // we either navigate the window to the void-slip URL (agent offline path)
            // or close it immediately (agent handled it, or cancel failed).
            // window.open() called after an await loses the user-activation context
            // on Android Chrome, so the popup blocker kills it — hence the pre-open.
            // If window.open() itself is blocked (returns null), _printVoidViaIframe
            // falls back to a hidden iframe which still works on desktop Chrome.
            const voidWin = window.open('', 'waiter-void-print', 'width=380,height=620');

            try {
                const res = await fetch('/pos/waiter/orders/' + this.cancelAsk.id + '/cancel', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.success) {
                    this.showToast(@js(__('pos.order_cancelled_toast')), 'success');
                    // Task 850 — void slip delivery: agent path (queued=true) needs no
                    // client action. When the agent is offline, navigate the pre-opened
                    // window to the void-ticket URL so auto_print=1 triggers
                    // window.print() in a real window context (works on Android).
                    if (!data.kot_void_queued && data.kot_void_url) {
                        this._printVoidViaIframe(data.kot_void_url + '&auto_print=1', voidWin);
                    } else {
                        // Agent queued the void — no browser print needed; close the
                        // pre-opened blank window so nothing lingers on screen.
                        if (voidWin && !voidWin.closed) voidWin.close();
                    }
                } else {
                    // Cancel failed — discard the pre-opened window immediately.
                    if (voidWin && !voidWin.closed) voidWin.close();
                    this.showToast(data.message || @js(__('pos.cancel_failed')), 'error');
                }
            } catch (e) {
                if (voidWin && !voidWin.closed) voidWin.close();
                this.showToast(@js(__('pos.cancel_failed_conn')), 'error');
            }
            this.cancelBusy = false;
            this.cancelAsk = null;
            this.loadMyOrders();
        },

        startAppend(o) {
            // Task 626: takeaway OFF → parcel orders mein append ka client rasta
            // bhi band (koi shortcut isay na khole); server bhi 403 karta hai.
            if (!this.canTakeaway && o.order_type && o.order_type !== 'dine_in') {
                this.showToast(@js(__('pos.waiter_takeaway_not_allowed')), 'error');
                return;
            }
            if (this.cart.length && !confirm(@js(__('pos.discard_unsent_items_q')))) return;
            this.cart = [];
            this.appendOrderId = o.id;
            this.appendOrderNumber = o.order_number;
            // Task 526: My Orders API pehle hi order ka table bhejti hai (orderJson
            // 'table' = table_number); parcel/takeaway par null → badge chhupa rehta hai.
            this.appendTableLabel = o.table ? String(o.table) : '';
            // Task 530: PARCEL (bina table) orders par pehchaan — customer naam
            // (agar dala gaya ho) warna daily Order-Matching token (#N). Dine-in
            // par T-x badge hi kaafi hai, yeh khali rehta hai.
            this.appendCustomerLabel = !o.table
                ? (o.customer_name ? String(o.customer_name) : (o.token_no ? '#' + o.token_no : ''))
                : '';
            this.showMyOrders = false;
        },
        cancelAppend() {
            this.appendOrderId = null;
            this.appendOrderNumber = '';
            this.appendTableLabel = '';
            this.appendCustomerLabel = '';
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
            // Task 526: order_number na ho to banner khud hi T-x dikhata hai — badge skip.
            this.appendTableLabel = (t.order_number && t.table_number) ? String(t.table_number) : '';
            this.appendCustomerLabel = ''; // dine-in path — T-x badge kaafi hai
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
            this.appendTableLabel = (o.order_number && t.table_number) ? String(t.table_number) : '';
            this.appendCustomerLabel = ''; // dine-in path — T-x badge kaafi hai
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
                const headers = { 'Accept': 'application/json' };
                if (this._tableEtag) headers['If-None-Match'] = this._tableEtag;
                const res = await fetch('/pos/waiter/api/tables', { headers });
                if (res.status !== 304 && res.ok) {
                    const etag = res.headers.get('ETag');
                    if (etag) this._tableEtag = etag;
                    this.tables = await res.json();
                }
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
            // Table-required guard (owner, 9 Aug 2026): a dine-in punch without a
            // table printed a KOT at a live shop. Server enforces the same rule;
            // this guard just opens the table picker instead of wasting a round-trip.
            if (!this.appendOrderId && this.orderType === 'dine_in' && !this.selectedTable && {{ ($tablesOn ?? false) ? 'true' : 'false' }}) {
                this.showToast(@js(__('pos.dine_in_table_required')), 'error');
                this.openTables();
                return;
            }
            this.sending = true;
            if (!this.appendOrderId && !this.holdAttemptUuid) {
                this.holdAttemptUuid = this._newHoldUuid();
            }
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
                priority: this.priority,
                hold_uuid: this.holdAttemptUuid,
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
                    this.priority = false;
                    this.holdAttemptUuid = null;
                    // SADA MODE: agla order phir dine-in se, fold band — screen
                    // khud "naya order" halat par wapas (cashier ka intikhab
                    // din bhar qaim rehta hai, jaan boojh kar reset NahiN).
                    this.orderType = 'dine_in'; this.moreOpen = false;
                    this.appendOrderId = null; this.appendOrderNumber = ''; this.appendTableLabel = ''; this.appendCustomerLabel = '';
                    this.loadMyOrders();
                    // Buttons style (Task #340, Aug 2026): after send, return to the
                    // home button list and silently refresh table counts/timers.
                    // _buttonsMode is PHP-baked — plain JS branch, no Blade directive needed.
                    if (this._buttonsMode) {
                        this.buttonsView = true;
                        this.reloadTablesQuiet();
                    }
                }
            } catch (e) {
                this.showToast(@js(__('pos.network_error_try_again')), 'error');
            }
            this.sending = false;
        },

        // Task 1010: crypto UUIDs are preferred; the fallback supports older
        // Android WebViews used by waiter tablets.
        _newHoldUuid() {
            try {
                if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                    return window.crypto.randomUUID();
                }
            } catch (e) {}
            return 'waiter-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
        },

        // ── Buttons style helpers (Task #340, Aug 2026) ─────────────────────────

        // Sorted subsets used by the x-for templates on the buttons home screen.
        // occupied first (by table_number numerically), then available/reserved.
        tablesOccupied() {
            return this.tables
                .filter(t => t.status === 'occupied')
                .sort((a, b) => String(a.table_number).localeCompare(String(b.table_number), undefined, { numeric: true }));
        },
        tablesAvailable() {
            return this.tables
                .filter(t => t.status !== 'occupied')
                .sort((a, b) => String(a.table_number).localeCompare(String(b.table_number), undefined, { numeric: true }));
        },

        // Tap an occupied table → open the existing action modal (Add Items / Shift).
        // Tap a free/reserved table → pre-select it and reveal the product grid.
        selectButtonTable(t) {
            if (t.status === 'occupied') {
                // Reuse the exact same occupied-tile action flow as the table picker.
                this.tableActionFor = t;
            } else {
                this.pickTable(t);   // sets selectedTable, closes picker (showTables)
            }
            // In both cases reveal the product grid so the waiter can add items.
            this.buttonsView = false;
        },

        // My open parcel (takeaway/delivery) orders — used by the Parcel button
        // badge and its inline sub-list (Task #342, Aug 2026).
        parcelOrders() {
            return this.myOrders.filter(o => o.order_type !== 'dine_in');
        },

        // Tap the Parcel button (Task #342): agar khule parcel orders hain to
        // inline sub-list toggle karo (tap = append, tables jaisa flow); warna
        // seedha naya parcel order shuru.
        selectButtonParcel() {
            if (!this.canTakeaway) return; // Task 626: takeaway OFF = parcel flows band
            if (this.parcelOrders().length > 0) {
                this.parcelListOpen = !this.parcelListOpen;
                return;
            }
            this.startNewParcel();
        },

        // "+ Naya Parcel Order" → set takeaway order type and reveal the product grid.
        startNewParcel() {
            if (!this.canTakeaway) return; // Task 527: admin ne takeaway band rakha hai
            this.parcelListOpen = false;
            this.orderType = 'takeaway';
            this.selectedTable = null;
            this.buttonsView = false;
        },

        // Tap an open parcel order in the sub-list → append items to that order
        // (reuses the exact My Orders append flow; grid opens via appendOrderId).
        startAppendParcel(o) {
            this.parcelListOpen = false;
            this.startAppend(o);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        // Silent background table refresh — updates this.tables without opening the
        // showTables modal (used by the buttons-style poll and post-send reset).
        async reloadTablesQuiet() {
            try {
                const headers = { 'Accept': 'application/json' };
                if (this._tableEtag) headers['If-None-Match'] = this._tableEtag;
                const res = await fetch('/pos/waiter/api/tables', { headers });
                if (res.status === 304) return;
                if (!res.ok) return;
                const etag = res.headers.get('ETag');
                if (etag) this._tableEtag = etag;
                this.tables = await res.json();
            } catch (e) { /* network blip — stale data stays until next poll */ }
        },

        showToast(msg, type = 'success') {
            this.toast = msg;
            this.toastType = type;
            clearTimeout(this._toastTimer);
            this._toastTimer = setTimeout(() => { this.toast = ''; }, 3000);
        },

        // Task 850/925 — void-slip browser-print fallback: when the Desktop Agent is
        // offline, navigate to the void-ticket URL so auto_print=1 triggers
        // window.print() and the kitchen gets the VOID slip.
        //
        // voidWin — pre-opened blank window from confirmCancel() (opened synchronously
        //   inside the click handler while user-activation is still live). Navigate it
        //   to the URL so Android Chrome's popup bridge is already established.
        //   Falls back to a fresh window.open() if caller could not pre-open (future
        //   callers), and then to a hidden iframe if even that is blocked.
        _printVoidViaIframe(url, voidWin) {
            if (voidWin && !voidWin.closed) {
                // Use the pre-opened window — already past the popup blocker because
                // it was opened synchronously in the user-gesture context.
                voidWin.location.href = url;
                return;
            }
            // No pre-opened window — try a fresh open (works if still in gesture ctx,
            // or on desktop where popup blocker is off).
            const popup = window.open(url, 'waiter-void-print', 'width=380,height=620');
            if (!popup) {
                // Popup blocked — fall back to hidden iframe (desktop Chrome fallback).
                let frame = document.getElementById('waiter-void-frame');
                if (!frame) {
                    frame = document.createElement('iframe');
                    frame.id = 'waiter-void-frame';
                    frame.style.cssText = 'position:fixed;width:0;height:0;border:none;left:-9999px;top:-9999px;';
                    document.body.appendChild(frame);
                }
                frame.src = url;
            }
        },
    };
}
</script>
</x-pos-layout>
