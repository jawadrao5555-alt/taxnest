<x-pos-layout>
<div x-data="kdsScreen()" x-init="startPolling(); $nextTick(() => $refs.scanInput && $refs.scanInput.focus())"
     @click.self="$refs.scanInput && $refs.scanInput.focus()"
     class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    {{-- Hidden scanner input: stays focused; barcode scanners auto-type then send Enter --}}
    <input type="text" x-ref="scanInput" x-model="scanBuffer"
           @keydown.enter.prevent="processScan()"
           @blur="setTimeout(() => { if (!cameraOpen) { $refs.scanInput && $refs.scanInput.focus(); } }, 100)"
           autocomplete="off"
           style="position:fixed; top:-9999px; left:-9999px; opacity:0; width:1px; height:1px;">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.kds_page_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.kds_page_subtitle') }}</p>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <div class="flex gap-2 text-xs">
                <span class="px-2 py-1 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 font-medium">{{ __('pos.kds_new_colon') }} <span x-text="filteredOrders.filter(o => kstate(o) === 'new').length"></span></span>
                <span class="px-2 py-1 rounded bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-400 font-medium">{{ __('pos.kds_preparing_colon') }} <span x-text="filteredOrders.filter(o => kstate(o) === 'preparing').length"></span></span>
                <span class="px-2 py-1 rounded bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 font-medium">{{ __('pos.kds_ready_colon') }} <span x-text="filteredOrders.filter(o => kstate(o) === 'ready').length"></span></span>
            </div>
            @if(($kdsStations ?? collect())->isNotEmpty())
            {{-- Counter/Station picker: pin THIS display to one counter — cards, items
                 and auto-prints then cover only that counter's dishes. Persists per device. --}}
            <select x-model="stationFilter"
                    class="text-xs font-semibold rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 py-1.5 pl-2 pr-7 focus:ring-purple-500 focus:border-purple-500">
                <option value="all">{{ __('pos.all_counters') }}</option>
                <option value="0">{{ __('pos.main_kitchen') }}</option>
                @foreach($kdsStations as $st)
                <option value="{{ $st->id }}">{{ $st->name }}</option>
                @endforeach
            </select>
            @endif
            <div class="inline-flex rounded-lg overflow-hidden border border-gray-300 dark:border-gray-600 text-xs font-semibold">
                <button @click="viewMode = 'list'" :class="viewMode === 'list' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300'" class="px-3 py-1.5">{{ __('pos.view_list') }}</button>
                <button @click="viewMode = 'aggregate'" :class="viewMode === 'aggregate' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300'" class="px-3 py-1.5">{{ __('pos.view_aggregate') }}</button>
            </div>
            <button @click="openCamera()" class="px-3 py-1.5 text-sm rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-medium">{{ __('pos.camera_scan_btn') }}</button>
            <button @click="refreshOrders()" class="px-3 py-1.5 text-sm rounded-lg bg-purple-600 text-white hover:bg-purple-700 font-medium">{{ __('pos.refresh_btn') }}</button>
            <button @click="clearAll()" x-show="filteredOrders.length > 0" class="px-3 py-1.5 text-sm rounded-lg bg-red-600 text-white hover:bg-red-700 font-medium">{{ __('pos.clear_all_btn') }}</button>
        </div>
    </div>

    {{-- Scan-to-Clear banner: shows scanner status, click to refocus --}}
    <div @click="$refs.scanInput && $refs.scanInput.focus()"
         class="mb-4 px-4 py-3 rounded-xl border-2 border-dashed border-emerald-400 bg-emerald-50 dark:bg-emerald-900/10 dark:border-emerald-600 flex items-center justify-between cursor-pointer select-none">
        <div class="flex items-center gap-3">
            <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7V5a2 2 0 012-2h2M4 17v2a2 2 0 002 2h2m8-18h2a2 2 0 012 2v2m-4 14h2a2 2 0 002-2v-2M8 12h.01M12 12h.01M16 12h.01"/></svg>
            <div>
                <div class="text-sm font-bold text-emerald-800 dark:text-emerald-300">{{ __('pos.scanner_active_banner') }}</div>
                <div class="text-xs text-emerald-700 dark:text-emerald-400">{{ __('pos.buffer_colon') }} <span x-text="scanBuffer || {{ Js::from(__('pos.waiting_paren')) }}" class="font-mono"></span> &nbsp;|&nbsp; {{ __('pos.click_anywhere_refocus') }} &nbsp;|&nbsp; {{ __('pos.camera_scan_hint_short') }}</div>
            </div>
        </div>
    </div>

    <style>
        @keyframes urgentPulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.4); } 50% { box-shadow: 0 0 0 8px rgba(239,68,68,0); } }
        .kds-urgent { animation: urgentPulse 1.5s ease-in-out infinite; }
        .kds-timer-green { background: #dcfce7; color: #166534; }
        .kds-timer-yellow { background: #fef9c3; color: #92400e; }
        .kds-timer-red { background: #fee2e2; color: #991b1b; font-weight: 800; }
    </style>
    {{-- AGGREGATE VIEW: product-wise totals (vendor request — less confusion than full orders) --}}
    <div x-show="viewMode === 'aggregate'" class="mb-4">
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
            <template x-for="row in aggregateItems" :key="row.name">
                <div :class="{ 'ring-4 ring-emerald-500 scale-105': lastScanFlash && lastScanFlashItem === row.name }"
                     class="bg-white dark:bg-gray-800 border-2 border-gray-300 dark:border-gray-700 rounded-xl p-3 text-center transition-all duration-300">
                    <div class="text-3xl font-black text-purple-700 dark:text-purple-300" x-text="row.qty"></div>
                    <div class="text-sm font-bold text-gray-900 dark:text-white mt-1 truncate" :title="row.name" x-text="row.name"></div>
                    <div class="text-[10px] text-gray-500 mt-1" x-text="row.orders + {{ Js::from(__('pos.sfx_order_s')) }}"></div>
                </div>
            </template>
        </div>
        <div x-show="aggregateItems.length === 0" class="text-center py-12 text-gray-500 dark:text-gray-400 text-sm">
            {{ __('pos.no_pending_items') }}
        </div>
    </div>

    {{-- LIST VIEW: order cards (default). Card state = KITCHEN lifecycle (new → preparing → ready → cleared),
         never the billing status — clearing removes from the board, the cashier's held bill survives. --}}
    <div x-show="viewMode === 'list'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        <template x-for="order in filteredOrders" :key="order.id">
            <div :class="{
                'border-amber-400 bg-amber-50 dark:bg-amber-900/10': kstate(order) === 'new',
                'border-teal-400 bg-teal-50 dark:bg-teal-900/10': kstate(order) === 'preparing',
                'border-green-400 bg-green-50 dark:bg-green-900/10': kstate(order) === 'ready',
                'ring-4 ring-emerald-500 scale-105': lastScanFlash === order.id,
                'ring-2 ring-red-500 kds-urgent': order.elapsed_minutes > 15 && lastScanFlash !== order.id,
                'ring-1 ring-amber-400': order.elapsed_minutes > 5 && order.elapsed_minutes <= 15 && lastScanFlash !== order.id
            }" class="border-2 rounded-xl overflow-hidden dark:border-opacity-50 transition-all duration-300">
                <div class="px-4 py-3 flex items-center justify-between" :class="{
                    'bg-amber-100 dark:bg-amber-900/30': kstate(order) === 'new',
                    'bg-teal-100 dark:bg-teal-900/30': kstate(order) === 'preparing',
                    'bg-green-100 dark:bg-green-900/30': kstate(order) === 'ready',
                }">
                    <div>
                        <span class="font-bold text-gray-900 dark:text-white text-sm" x-text="order.order_number"></span>
                        <span x-show="order.table" class="ml-2 text-xs bg-white dark:bg-gray-800 px-1.5 py-0.5 rounded text-purple-600 dark:text-purple-400 font-medium" x-text="'T-' + order.table"></span>
                        <span x-show="order.priority" class="ml-1 text-[9px] bg-red-600 text-white px-1.5 py-0.5 rounded-full font-black animate-pulse">{{ __('pos.rush_badge') }}</span>
                    </div>
                    <div class="text-right">
                        <div class="text-xs font-bold px-2 py-0.5 rounded-full inline-block" :class="order.elapsed_minutes <= 5 ? 'kds-timer-green' : (order.elapsed_minutes <= 15 ? 'kds-timer-yellow' : 'kds-timer-red')" x-text="order.elapsed_minutes + {{ Js::from(__('pos.min_suffix')) }}"></div>
                        <div class="text-[10px] text-gray-400 mt-0.5" x-text="order.created_at"></div>
                    </div>
                </div>

                <div class="p-4 bg-white dark:bg-gray-800/50">
                    <template x-for="(item, idx) in myItems(order)" :key="idx">
                        <div class="flex items-start gap-2 py-1.5 border-b border-gray-100 dark:border-gray-700 last:border-0">
                            <span class="w-6 h-6 rounded bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 flex items-center justify-center text-xs font-bold flex-shrink-0" x-text="item.qty"></span>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium text-gray-900 dark:text-white" x-text="item.name"></div>
                                <div x-show="item.notes" class="text-xs text-amber-600 dark:text-amber-400 italic" x-text="item.notes"></div>
                            </div>
                        </div>
                    </template>
                    <div x-show="order.kitchen_notes" class="mt-2 p-2 bg-amber-50 dark:bg-amber-900/20 rounded text-xs text-amber-700 dark:text-amber-400">
                        <strong>{{ __('pos.note_label') }}</strong> <span x-text="order.kitchen_notes"></span>
                    </div>

                    {{-- Task 841: CANCELLED dishes badge — shown until cook acknowledges. --}}
                    <div x-show="hasUnacknowledgedVoids(order)"
                         class="mt-2 p-2 bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-700 rounded">
                        <div class="flex items-center gap-1 mb-1">
                            <svg class="w-3.5 h-3.5 text-red-600 dark:text-red-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                            <span class="text-xs font-bold text-red-700 dark:text-red-400 uppercase tracking-wide">{{ __('pos.kds_cancelled_header') }}</span>
                        </div>
                        <template x-for="vi in (order.void_items || [])" :key="vi.item_name + vi.qty">
                            <div class="text-xs font-semibold text-red-600 dark:text-red-400 flex items-center gap-1 py-0.5">
                                <span class="w-5 h-5 rounded bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 flex items-center justify-center text-[10px] font-black flex-shrink-0" x-text="vi.qty"></span>
                                <span x-text="vi.item_name"></span>
                                <span x-show="vi.notes" class="text-red-400 dark:text-red-500 font-normal italic" x-text="'(' + vi.notes + ')'"></span>
                            </div>
                        </template>
                        <button @click.stop="acknowledgeVoids(order.id, order.void_items)"
                                class="mt-1.5 text-[10px] px-2 py-0.5 bg-red-600 hover:bg-red-700 text-white rounded font-semibold">
                            {{ __('pos.kds_void_ack_btn') }}
                        </button>
                    </div>
                </div>

                <div class="px-4 py-3 bg-gray-50 dark:bg-gray-800 flex gap-2">
                    <template x-if="kstate(order) === 'new'">
                        <button @click="kitchenUpdate(order.id, 'preparing')" class="flex-1 py-2 text-xs rounded-lg bg-teal-600 text-white hover:bg-teal-700 font-semibold">{{ __('pos.start_preparing') }}</button>
                    </template>
                    <template x-if="kstate(order) === 'preparing'">
                        <button @click="kitchenUpdate(order.id, 'ready')" class="flex-1 py-2 text-xs rounded-lg bg-green-600 text-white hover:bg-green-700 font-semibold">{{ __('pos.mark_ready') }}</button>
                    </template>
                    <template x-if="kstate(order) === 'ready'">
                        <span class="flex-1 py-2 text-xs rounded-lg bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 font-semibold text-center">{{ __('pos.ready_for_pickup') }}</span>
                    </template>
                    <button @click="reprintTicket(order.id)" class="py-2 px-3 text-xs rounded-lg border border-teal-300 text-teal-700 hover:bg-teal-50 dark:border-teal-700 dark:text-teal-300 dark:hover:bg-teal-900/20 font-semibold" title="{{ __('pos.ti_reprint_kot') }}">{{ __('pos.kot_btn') }}</button>
                    <button @click="kitchenUpdate(order.id, 'cleared')" class="py-2 px-3 text-xs rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700 font-semibold" title="{{ __('pos.ti_clear_from_board') }}">{{ __('pos.clear') }}</button>
                </div>
            </div>
        </template>
    </div>

    <div x-show="filteredOrders.length === 0" class="text-center py-16">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.all_clear') }}</h3>
        <p class="text-gray-500 dark:text-gray-400 text-sm">{{ __('pos.no_active_kitchen_orders') }}</p>
    </div>

    {{-- Camera scan modal (P5) — html5-qrcode reads the KOT QR/barcode with the device camera --}}
    <div x-show="cameraOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="closeCamera()">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
            <div class="px-4 py-3 flex items-center justify-between border-b border-gray-200 dark:border-gray-700">
                <h3 class="font-bold text-gray-900 dark:text-white text-sm">{{ __('pos.scan_kot_camera') }}</h3>
                <button @click="closeCamera()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-xl leading-none">&times;</button>
            </div>
            <div class="p-4">
                <div id="kdsCameraReader" class="w-full rounded-lg overflow-hidden bg-black" style="min-height: 260px;"></div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2 text-center">{{ __('pos.point_camera_hint') }}</p>
            </div>
        </div>
    </div>

    <div x-show="toast.show" x-transition class="fixed bottom-4 right-4 z-50 max-w-sm">
        <div :class="toast.type === 'success' ? 'bg-green-600' : 'bg-red-600'" class="text-white px-4 py-3 rounded-lg shadow-lg text-sm" x-text="toast.message"></div>
    </div>

    {{-- P6 (F5): hidden iframe host for KDS auto-print — tickets load here with auto_print=1 --}}
    <div id="kdsPrintHost" style="position:fixed; width:0; height:0; overflow:hidden; border:0;"></div>
</div>

@php
$stationItemMap = $stationItemMap ?? [];
$kdsOrdersJson = $orders->map(function($o) use ($stationItemMap) {
    // Kitchen timer starts at KOT time (owner, Jul 2026): clock runs from
    // kot_sent_at (ticket sent), created_at only as legacy fallback. Carbon 3
    // signed diff — measure FROM start TO now so elapsed is positive.
    $kdsStart = $o->kot_sent_at ?: $o->created_at;
    $elapsed = (int) $kdsStart->diffInMinutes(now());
    $items = $o->items->map(function($i) use ($stationItemMap) {
        return ['name' => $i->item_name, 'qty' => $i->quantity, 'notes' => $i->special_notes, 'station_id' => $stationItemMap[$i->id] ?? 0];
    })->values();
    return [
        'id' => $o->id,
        'order_number' => $o->order_number,
        'status' => $o->status,
        'kitchen_status' => $o->kitchen_status ?: 'new',
        'priority' => (bool)$o->priority,
        'table' => $o->table ? $o->table->table_number : null,
        'items' => $items,
        'kitchen_notes' => $o->kitchen_notes,
        'unprinted_count' => $o->items->whereNull('kot_printed_at')->count(),
        'unprinted_by_station' => (object) $o->items->whereNull('kot_printed_at')
            ->groupBy(fn($i) => (string)($stationItemMap[$i->id] ?? 0))
            ->map->count()->toArray(),
        // Task 841: voided dishes for KDS cancelled badge.
        'void_items' => $o->void_items ? json_decode($o->void_items, true) : [],
        'elapsed_minutes' => $elapsed,
        'is_urgent' => $elapsed > 15,
        'created_at' => $kdsStart->format('H:i'),
    ];
})->values();
@endphp
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
function kdsScreen() {
    return {
        orders: @json($kdsOrdersJson),
        polling: null,
        toast: { show: false, message: '', type: 'success' },
        scanBuffer: '',
        lastScanFlash: null,
        lastScanFlashItem: null,
        viewMode: (typeof localStorage !== 'undefined' && localStorage.getItem('kds_view_mode')) || 'list',
        cameraOpen: false,
        cameraScanner: null,
        // Counter/Station routing: pin THIS display to one counter. 'all' = classic
        // full-kitchen board. Persists per device; ?station= URL param overrides.
        hasStations: {{ ($kdsStations ?? collect())->isNotEmpty() ? 'true' : 'false' }},
        validStations: @json(collect($kdsStations ?? [])->pluck('id')->map(fn($i) => (string)$i)->prepend('0')->prepend('all')->values()),
        stationFilter: 'all',
        // P6 (F5): KDS auto-print — this device prints the KOT for every NEW order
        // it sees. Printed ids persist in localStorage so a page refresh never
        // re-prints. First-ever load seeds the set with the current backlog
        // (no print storm) — only orders arriving AFTER that print automatically.
        autoPrintEnabled: {{ ($kdsAutoPrint ?? false) ? 'true' : 'false' }},
        // Silent printer routing — KOT jobs go to the Desktop Agent's queue when
        // enabled; any enqueue failure falls back to the classic iframe print.
        silentKotPrint: {{ ($kdsSilentKot ?? false) ? 'true' : 'false' }},
        printedIds: [],
        printQueue: [],
        printingNow: false,
        // P7: throttle delta re-queues — stamping happens when the ticket renders,
        // but a poll can land mid-print; don't re-fire the same delta within 30s.
        _deltaFiredAt: {},
        // Task 841: per-device acknowledgement of cancelled-items badges.
        // Map of orderId → JSON-string of void_items last acknowledged by this device.
        acknowledgedVoids: {},

        // Kitchen lifecycle state (never the billing status): new → preparing → ready.
        kstate(order) {
            const k = order.kitchen_status || 'new';
            return (k === 'new' || k === 'preparing' || k === 'ready') ? k : 'new';
        },

        // Items belonging to this display's pinned counter ('all' = everything).
        myItems(order) {
            if (!this.hasStations || this.stationFilter === 'all') return order.items || [];
            return (order.items || []).filter(i => String(i.station_id || 0) === String(this.stationFilter));
        },

        get filteredOrders() {
            if (!this.hasStations || this.stationFilter === 'all') return this.orders;
            return this.orders.filter(o => this.myItems(o).length > 0);
        },

        get aggregateItems() {
            const map = new Map();
            this.filteredOrders.forEach(o => {
                const k = this.kstate(o);
                if (k !== 'new' && k !== 'preparing') return;
                this.myItems(o).forEach(it => {
                    const key = (it.name || '').trim();
                    if (!key) return;
                    if (!map.has(key)) map.set(key, { name: key, qty: 0, orders: 0 });
                    const row = map.get(key);
                    row.qty += Number(it.qty) || 0;
                    row.orders += 1;
                });
            });
            return Array.from(map.values()).sort((a, b) => b.qty - a.qty);
        },

        // Task 841: true when the order has void_items that have not yet been
        // acknowledged on this device. A second re-hold that produces a DIFFERENT
        // void list resets the badge even if the cook acknowledged the previous one.
        hasUnacknowledgedVoids(order) {
            const vi = order.void_items || [];
            if (!vi.length) return false;
            const sig = JSON.stringify(vi);
            return (this.acknowledgedVoids[String(order.id)] || '') !== sig;
        },

        acknowledgeVoids(orderId, voidItems) {
            const sig = JSON.stringify(voidItems || []);
            this.acknowledgedVoids[String(orderId)] = sig;
            try {
                const stored = {};
                try { Object.assign(stored, JSON.parse(localStorage.getItem('kds_acked_voids') || '{}')); } catch(e) {}
                stored[String(orderId)] = sig;
                // Prune stale entries (orders no longer on board).
                const liveIds = new Set(this.orders.map(o => String(o.id)));
                Object.keys(stored).forEach(k => { if (!liveIds.has(k)) delete stored[k]; });
                localStorage.setItem('kds_acked_voids', JSON.stringify(stored));
            } catch(e) {}
        },

        initAcknowledgedVoids() {
            try {
                const stored = JSON.parse(localStorage.getItem('kds_acked_voids') || '{}');
                if (stored && typeof stored === 'object') this.acknowledgedVoids = stored;
            } catch(e) {}
        },

        startPolling() {
            this.$watch('viewMode', v => { try { localStorage.setItem('kds_view_mode', v); } catch(e){} });
            this.initStation();
            this.initAutoPrint();
            this.initAcknowledgedVoids();
            this.polling = setInterval(() => this.refreshOrders(), 15000);
            this.timerInterval = setInterval(() => {
                this.orders.forEach(o => { o.elapsed_minutes++; });
                const hasUrgent = this.orders.some(o => o.elapsed_minutes > 15 && (this.kstate(o) === 'new' || this.kstate(o) === 'preparing'));
                if (hasUrgent) this.playUrgentBeep();
            }, 60000);
        },

        initStation() {
            if (!this.hasStations) return;
            // Register the persist-watch FIRST so a ?station= URL override is
            // also saved to localStorage (device stays pinned on next plain load).
            this.$watch('stationFilter', s => { try { localStorage.setItem('kds_station', s); } catch(e) {} });
            let v = new URLSearchParams(window.location.search).get('station');
            if (v === null || v === '') { try { v = localStorage.getItem('kds_station'); } catch(e) {} }
            if (v !== null && this.validStations.includes(String(v))) this.stationFilter = String(v);
        },

        playUrgentBeep() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator(); const gain = ctx.createGain();
                osc.connect(gain); gain.connect(ctx.destination);
                osc.frequency.value = 880; osc.type = 'square';
                gain.gain.value = 0.15;
                osc.start(); osc.stop(ctx.currentTime + 0.15);
                setTimeout(() => { const o2 = ctx.createOscillator(); o2.connect(gain); o2.frequency.value = 880; o2.type = 'square'; o2.start(); o2.stop(ctx.currentTime + 0.15); }, 200);
            } catch(e) {}
        },

        async refreshOrders() {
            try {
                const res = await fetch('{{ route("pos.restaurant.live-orders") }}');
                if (res.ok) {
                    this.orders = await res.json();
                    this.checkAutoPrint();
                }
            } catch (e) {}
        },

        initAutoPrint() {
            if (!this.autoPrintEnabled) return;
            let stored = null;
            try { stored = localStorage.getItem('kds_printed_ids'); } catch(e) {}
            if (stored === null) {
                // First-ever load on this device: seed with the current backlog so
                // we don't blast N tickets at once — only NEW orders print.
                this.printedIds = this.orders.map(o => o.id);
                this.savePrintedIds();
            } else {
                try { this.printedIds = JSON.parse(stored) || []; } catch(e) { this.printedIds = []; }
                this.checkAutoPrint();
            }
            // Ticket iframe signals back when its print dialog closes — strict
            // one-at-a-time ordering so two tickets never race the printer.
            window.addEventListener('message', (ev) => {
                if (ev.data && ev.data.type === 'pos_print_done' && String(ev.data.signal || '').startsWith('kds-')) {
                    this.finishCurrentPrint();
                }
            });
        },

        savePrintedIds() {
            try {
                if (this.printedIds.length > 300) this.printedIds = this.printedIds.slice(-300);
                localStorage.setItem('kds_printed_ids', JSON.stringify(this.printedIds));
            } catch(e) {}
        },

        // Pinned to one counter? Prints must cover ONLY that counter's items.
        get stationPinned() {
            return this.hasStations && this.stationFilter !== 'all';
        },

        checkAutoPrint() {
            if (!this.autoPrintEnabled) return;
            this.orders.forEach(o => {
                // Pinned counter: orders with no items for this counter are not
                // ours — never queue OR mark them printed (items may arrive later).
                if (this.stationPinned && this.myItems(o).length === 0) return;
                // Brand-new order → FULL ticket (station-scoped when pinned).
                if (!this.printedIds.includes(o.id)) {
                    // Cashier-fallback guard (30 Jul 2026): while this KDS was
                    // closed, the sale screen may have printed the ticket itself
                    // (rows get kot_printed_at stamped). Never re-blast a FULL copy:
                    // fully printed → adopt silently; partially printed → delta only.
                    const unprintedNew = this.stationPinned
                        ? Number((o.unprinted_by_station || {})[String(this.stationFilter)] || 0)
                        : (o.unprinted_count || 0);
                    const totalRows = this.stationPinned ? this.myItems(o).length : ((o.items || []).length);
                    if (totalRows > 0 && unprintedNew === 0) {
                        this.printedIds.push(o.id); this.savePrintedIds();
                        return;
                    }
                    if (totalRows > 0 && unprintedNew < totalRows) {
                        this.printedIds.push(o.id); this.savePrintedIds();
                        if (!this.printQueue.some(q => q.id === o.id && q.delta)) {
                            this._deltaFiredAt[o.id] = Date.now();
                            this.printQueue.push({ id: o.id, delta: true });
                        }
                        return;
                    }
                    if (!this.printQueue.some(q => q.id === o.id && !q.delta)) {
                        this.printQueue.push({ id: o.id, delta: false });
                    }
                    return;
                }
                // P7: already-printed order grew NEW items (waiter append) → DELTA
                // ticket: prints ONLY rows with kot_printed_at NULL, then stamps them.
                // Pinned counter: fire only when THIS counter's bucket grew — the
                // order-wide count would fire blank tickets for other counters.
                const unprinted = this.stationPinned
                    ? Number((o.unprinted_by_station || {})[String(this.stationFilter)] || 0)
                    : (o.unprinted_count || 0);
                if (unprinted > 0) {
                    const last = this._deltaFiredAt[o.id] || 0;
                    if (!this.printQueue.some(q => q.id === o.id && q.delta) && (Date.now() - last) > 30000) {
                        this._deltaFiredAt[o.id] = Date.now();
                        this.printQueue.push({ id: o.id, delta: true });
                    }
                }
            });
            this.processPrintQueue();
        },

        // P7: manual duplicate KOT — owner ask: kitchen can reprint the FULL ticket
        // any time (ignores printedIds; works even when auto-print is OFF).
        reprintTicket(orderId) {
            if (!this.printedIds.includes(orderId)) { this.printedIds.push(orderId); this.savePrintedIds(); }
            this.printQueue.push({ id: orderId, delta: false });
            this.processPrintQueue();
            this.showToast(@js(__('pos.kot_sent_to_printer')), 'success');
        },

        processPrintQueue() {
            if (this.printingNow || this.printQueue.length === 0) return;
            const job = this.printQueue.shift();
            // Mark BEFORE printing — a refresh mid-print must never duplicate.
            // (Delta jobs skip this — the id is already in printedIds.)
            if (!job.delta && !this.printedIds.includes(job.id)) {
                this.printedIds.push(job.id);
                this.savePrintedIds();
            }
            this.printingNow = true;

            // Silent printer routing: enqueue for the Desktop Agent first; the agent
            // prints on the configured KOT printer with no dialog. Any failure
            // (agent offline, feature disabled server-side, network) falls back to
            // the classic hidden-iframe print for THIS job only.
            if (this.silentKotPrint) {
                const payload = { type: 'kot', restaurant_order_id: job.id, delta: !!job.delta };
                // Pinned counter device: enqueue ONLY this counter's ticket (its
                // printer). Un-pinned: server splits across counters by itself.
                if (this.stationPinned) payload.station_id = Number(this.stationFilter);
                fetch('/pos/api/print-jobs', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    body: JSON.stringify(payload),
                }).then(r => r.ok ? r.json().catch(() => null) : null).then(d => {
                    if (d && d.success) { this.finishCurrentPrint(); }
                    else { this._iframePrint(job); }
                }).catch(() => { this._iframePrint(job); });
                return;
            }
            this._iframePrint(job);
        },

        // Classic hidden-iframe KOT print (also the silent-print fallback).
        _iframePrint(job) {
            const host = document.getElementById('kdsPrintHost');
            const frame = document.createElement('iframe');
            frame.id = 'kdsPrintFrame';
            const stationQ = this.stationPinned ? `&station=${encodeURIComponent(this.stationFilter)}` : '';
            frame.src = `/pos/restaurant/orders/${job.id}/kitchen-ticket?auto_print=1${job.delta ? '&delta=1' : ''}${stationQ}&_signal=kds-${job.id}`;
            host.appendChild(frame);
            // Fallback: if the iframe never signals (blocked dialog etc.), move on.
            this.printFallbackTimer = setTimeout(() => this.finishCurrentPrint(), 25000);
        },

        finishCurrentPrint() {
            if (!this.printingNow) return;
            this.printingNow = false;
            clearTimeout(this.printFallbackTimer);
            const host = document.getElementById('kdsPrintHost');
            if (host) host.innerHTML = '';
            setTimeout(() => this.processPrintQueue(), 400);
        },

        async openCamera() {
            this.cameraOpen = true;
            await this.$nextTick();
            try {
                if (typeof Html5Qrcode === 'undefined') {
                    throw new Error('Camera library not loaded');
                }
                if (!this.cameraScanner) this.cameraScanner = new Html5Qrcode('kdsCameraReader');
                await this.cameraScanner.start(
                    { facingMode: 'environment' },
                    {{-- Wider, shorter scan box — KOT tickets now carry a CODE128 barcode only (QR removed per owner, 20 Jul 2026) --}}
                    { fps: 10, qrbox: { width: 250, height: 140 } },
                    (decodedText) => { this.onCameraScan(decodedText); },
                    () => {}
                );
            } catch (e) {
                this.showToast(@js(__('pos.camera_not_available')), 'error');
                this.cameraOpen = false;
            }
        },

        async closeCamera() {
            this.cameraOpen = false;
            try { if (this.cameraScanner) { await this.cameraScanner.stop(); } } catch(e) {}
            setTimeout(() => { this.$refs.scanInput && this.$refs.scanInput.focus(); }, 150);
        },

        onCameraScan(text) {
            this.closeCamera();
            this.scanBuffer = (text || '').trim();
            this.processScan();
        },

        // Scan = CLEAR from any state (owner rule Jul 2026). Order leaves the board;
        // the cashier's held bill is untouched.
        async processScan() {
            const raw = (this.scanBuffer || '').trim();
            this.scanBuffer = '';
            if (!raw) return;
            try {
                const res = await fetch('{{ route("pos.restaurant.kds.scan") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    body: JSON.stringify({ code: raw }),
                });
                const data = await res.json();
                if (data.success) {
                    this.playScanBeep(true);
                    this.showToast(data.message, 'success');
                    if (data.order_id) {
                        // Flash briefly, then remove from the board (cleared)
                        this.lastScanFlash = data.order_id;
                        setTimeout(() => {
                            this.lastScanFlash = null;
                            this.orders = this.orders.filter(o => o.id !== data.order_id);
                        }, 700);
                        setTimeout(() => this.refreshOrders(), 1200);
                    }
                } else {
                    this.playScanBeep(false);
                    this.showToast(data.message || @js(__('pos.scan_failed')), 'error');
                }
            } catch (e) {
                this.playScanBeep(false);
                this.showToast(@js(__('pos.scan_error')), 'error');
            }
        },

        playScanBeep(ok) {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator(); const gain = ctx.createGain();
                osc.connect(gain); gain.connect(ctx.destination);
                osc.frequency.value = ok ? 1200 : 300; osc.type = 'square';
                gain.gain.value = 0.18;
                osc.start(); osc.stop(ctx.currentTime + (ok ? 0.08 : 0.25));
            } catch(e) {}
        },

        // Clear All (owner, 20 Jul 2026): wipes THIS board's visible orders only
        // (station-pinned display never clears other counters). Kitchen-side only —
        // cashiers' held bills survive, exactly like per-card Clear.
        async clearAll() {
            const ids = this.filteredOrders.map(o => o.id);
            if (!ids.length) return;
            const label = (this.hasStations && this.stationFilter !== 'all') ? @js(__('pos.this_counters')) : @js(__('pos.all_caps'));
            if (!confirm(@js(__('pos.confirm_clear_board')).replace(':label', label).replace(':count', ids.length))) return;
            try {
                const res = await fetch('{{ route("pos.restaurant.kds.clear-all") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    body: JSON.stringify({ ids: ids }),
                });
                const data = await res.json();
                if (data.success) {
                    this.orders = this.orders.filter(o => !ids.includes(o.id));
                    this.showToast(data.message, 'success');
                    this.refreshOrders();
                } else {
                    this.showToast(data.message || @js(__('pos.clear_all_failed')), 'error');
                }
            } catch (e) {
                this.showToast(@js(__('pos.network_error_clear_all')), 'error');
            }
        },

        // Kitchen-side status change — hits the kitchen-status endpoint which NEVER
        // touches the billing status (tables + cashier bills stay intact).
        async kitchenUpdate(orderId, kstatus) {
            try {
                const res = await fetch(`/pos/restaurant/kds/${orderId}/kitchen-status`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    body: JSON.stringify({ kitchen_status: kstatus }),
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast(data.message, 'success');
                    if (kstatus === 'cleared') {
                        this.orders = this.orders.filter(o => o.id !== orderId);
                    } else {
                        const order = this.orders.find(o => o.id === orderId);
                        if (order) order.kitchen_status = kstatus;
                    }
                } else {
                    this.showToast(data.message || @js(__('pos.update_failed')), 'error');
                    // State may be stale (e.g. cleared elsewhere) — resync.
                    this.refreshOrders();
                }
            } catch (e) { this.showToast(@js(__('pos.error_updating_order')), 'error'); }
        },

        showToast(msg, type) {
            this.toast = { show: true, message: msg, type };
            setTimeout(() => this.toast.show = false, 3000);
        },
    };
}
</script>
</x-pos-layout>
