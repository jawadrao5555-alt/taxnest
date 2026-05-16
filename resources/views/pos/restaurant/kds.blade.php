<x-pos-layout>
<div x-data="kdsScreen()" x-init="startPolling(); $nextTick(() => $refs.scanInput && $refs.scanInput.focus())"
     @click.self="$refs.scanInput && $refs.scanInput.focus()"
     class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    {{-- Hidden scanner input: stays focused; barcode scanners auto-type then send Enter --}}
    <input type="text" x-ref="scanInput" x-model="scanBuffer"
           @keydown.enter.prevent="processScan()"
           @blur="setTimeout(() => $refs.scanInput && $refs.scanInput.focus(), 100)"
           autocomplete="off"
           style="position:fixed; top:-9999px; left:-9999px; opacity:0; width:1px; height:1px;">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Kitchen Display System</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Active orders for kitchen staff</p>
        </div>
        <div class="flex items-center gap-3">
            <div class="flex gap-2 text-xs">
                <span class="px-2 py-1 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 font-medium">Held: <span x-text="orders.filter(o => o.status === 'held').length"></span></span>
                <span class="px-2 py-1 rounded bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 font-medium">Preparing: <span x-text="orders.filter(o => o.status === 'preparing').length"></span></span>
                <span class="px-2 py-1 rounded bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 font-medium">Ready: <span x-text="orders.filter(o => o.status === 'ready').length"></span></span>
            </div>
            <div class="inline-flex rounded-lg overflow-hidden border border-gray-300 dark:border-gray-600 text-xs font-semibold">
                <button @click="viewMode = 'list'" :class="viewMode === 'list' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300'" class="px-3 py-1.5">📋 List</button>
                <button @click="viewMode = 'aggregate'" :class="viewMode === 'aggregate' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300'" class="px-3 py-1.5">📊 Aggregate</button>
            </div>
            <button @click="refreshOrders()" class="px-3 py-1.5 text-sm rounded-lg bg-purple-600 text-white hover:bg-purple-700 font-medium">Refresh</button>
        </div>
    </div>

    {{-- Scan-to-Ready banner: shows scanner status, click to refocus --}}
    <div @click="$refs.scanInput && $refs.scanInput.focus()"
         class="mb-4 px-4 py-3 rounded-xl border-2 border-dashed border-emerald-400 bg-emerald-50 dark:bg-emerald-900/10 dark:border-emerald-600 flex items-center justify-between cursor-pointer select-none">
        <div class="flex items-center gap-3">
            <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7V5a2 2 0 012-2h2M4 17v2a2 2 0 002 2h2m8-18h2a2 2 0 012 2v2m-4 14h2a2 2 0 002-2v-2M8 12h.01M12 12h.01M16 12h.01"/></svg>
            <div>
                <div class="text-sm font-bold text-emerald-800 dark:text-emerald-300">📡 Scanner Active — Scan KOT barcode to mark order READY</div>
                <div class="text-xs text-emerald-700 dark:text-emerald-400">Buffer: <span x-text="scanBuffer || '(waiting…)'" class="font-mono"></span> &nbsp;|&nbsp; Click anywhere to refocus</div>
            </div>
        </div>
    </div>

    <style>
        @keyframes urgentPulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.4); } 50% { box-shadow: 0 0 0 8px rgba(239,68,68,0); } }
        .kds-urgent { animation: urgentPulse 1.5s ease-in-out infinite; }
        .kds-timer-green { background: linear-gradient(135deg, #dcfce7, #bbf7d0); color: #166534; }
        .kds-timer-yellow { background: linear-gradient(135deg, #fef9c3, #fde68a); color: #92400e; }
        .kds-timer-red { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; font-weight: 800; }
    </style>
    {{-- AGGREGATE VIEW: product-wise totals (vendor request — less confusion than full orders) --}}
    <div x-show="viewMode === 'aggregate'" class="mb-4">
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
            <template x-for="row in aggregateItems" :key="row.name">
                <div :class="{ 'ring-4 ring-emerald-500 scale-105': lastScanFlash && lastScanFlashItem === row.name }"
                     class="bg-white dark:bg-gray-800 border-2 border-gray-300 dark:border-gray-700 rounded-xl p-3 text-center transition-all duration-300">
                    <div class="text-3xl font-black text-purple-700 dark:text-purple-300" x-text="row.qty"></div>
                    <div class="text-sm font-bold text-gray-900 dark:text-white mt-1 truncate" :title="row.name" x-text="row.name"></div>
                    <div class="text-[10px] text-gray-500 mt-1" x-text="row.orders + ' order(s)'"></div>
                </div>
            </template>
        </div>
        <div x-show="aggregateItems.length === 0" class="text-center py-12 text-gray-500 dark:text-gray-400 text-sm">
            No pending items. All caught up!
        </div>
    </div>

    {{-- LIST VIEW: order cards (default) --}}
    <div x-show="viewMode === 'list'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        <template x-for="order in orders" :key="order.id">
            <div :class="{
                'border-amber-400 bg-amber-50 dark:bg-amber-900/10': order.status === 'held',
                'border-blue-400 bg-blue-50 dark:bg-blue-900/10': order.status === 'preparing',
                'border-green-400 bg-green-50 dark:bg-green-900/10': order.status === 'ready',
                'ring-4 ring-emerald-500 scale-105': lastScanFlash === order.id,
                'ring-2 ring-red-500 kds-urgent': order.elapsed_minutes > 15 && lastScanFlash !== order.id,
                'ring-1 ring-amber-400': order.elapsed_minutes > 5 && order.elapsed_minutes <= 15 && lastScanFlash !== order.id
            }" class="border-2 rounded-xl overflow-hidden dark:border-opacity-50 transition-all duration-300">
                <div class="px-4 py-3 flex items-center justify-between" :class="{
                    'bg-amber-100 dark:bg-amber-900/30': order.status === 'held',
                    'bg-blue-100 dark:bg-blue-900/30': order.status === 'preparing',
                    'bg-green-100 dark:bg-green-900/30': order.status === 'ready',
                }">
                    <div>
                        <span class="font-bold text-gray-900 dark:text-white text-sm" x-text="order.order_number"></span>
                        <span x-show="order.table" class="ml-2 text-xs bg-white dark:bg-gray-800 px-1.5 py-0.5 rounded text-purple-600 dark:text-purple-400 font-medium" x-text="'T-' + order.table"></span>
                        <span x-show="order.priority" class="ml-1 text-[9px] bg-red-600 text-white px-1.5 py-0.5 rounded-full font-black animate-pulse">RUSH</span>
                    </div>
                    <div class="text-right">
                        <div class="text-xs font-bold px-2 py-0.5 rounded-full inline-block" :class="order.elapsed_minutes <= 5 ? 'kds-timer-green' : (order.elapsed_minutes <= 15 ? 'kds-timer-yellow' : 'kds-timer-red')" x-text="order.elapsed_minutes + ' min'"></div>
                        <div class="text-[10px] text-gray-400 mt-0.5" x-text="order.created_at"></div>
                    </div>
                </div>

                <div class="p-4 bg-white dark:bg-gray-800/50">
                    <template x-for="(item, idx) in order.items" :key="idx">
                        <div class="flex items-start gap-2 py-1.5 border-b border-gray-100 dark:border-gray-700 last:border-0">
                            <span class="w-6 h-6 rounded bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 flex items-center justify-center text-xs font-bold flex-shrink-0" x-text="item.qty"></span>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium text-gray-900 dark:text-white" x-text="item.name"></div>
                                <div x-show="item.notes" class="text-xs text-amber-600 dark:text-amber-400 italic" x-text="item.notes"></div>
                            </div>
                        </div>
                    </template>
                    <div x-show="order.kitchen_notes" class="mt-2 p-2 bg-amber-50 dark:bg-amber-900/20 rounded text-xs text-amber-700 dark:text-amber-400">
                        <strong>Note:</strong> <span x-text="order.kitchen_notes"></span>
                    </div>
                </div>

                <div class="px-4 py-3 bg-gray-50 dark:bg-gray-800 flex gap-2">
                    <template x-if="order.status === 'held'">
                        <button @click="updateStatus(order.id, 'preparing')" class="flex-1 py-2 text-xs rounded-lg bg-blue-600 text-white hover:bg-blue-700 font-semibold">Start Preparing</button>
                    </template>
                    <template x-if="order.status === 'preparing'">
                        <button @click="updateStatus(order.id, 'ready')" class="flex-1 py-2 text-xs rounded-lg bg-green-600 text-white hover:bg-green-700 font-semibold">Mark Ready</button>
                    </template>
                    <template x-if="order.status === 'ready'">
                        <span class="flex-1 py-2 text-xs rounded-lg bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 font-semibold text-center">Ready for Pickup</span>
                    </template>
                    <button @click="updateStatus(order.id, 'cancelled')" class="py-2 px-3 text-xs rounded-lg border border-red-300 text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400">Cancel</button>
                </div>
            </div>
        </template>
    </div>

    <div x-show="orders.length === 0" class="text-center py-16">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">All Clear!</h3>
        <p class="text-gray-500 dark:text-gray-400 text-sm">No active kitchen orders</p>
    </div>

    <div x-show="toast.show" x-transition class="fixed bottom-4 right-4 z-50 max-w-sm">
        <div :class="toast.type === 'success' ? 'bg-green-600' : 'bg-red-600'" class="text-white px-4 py-3 rounded-lg shadow-lg text-sm" x-text="toast.message"></div>
    </div>
</div>

@php
$kdsOrdersJson = $orders->map(function($o) {
    $elapsed = now()->diffInMinutes($o->created_at);
    $items = $o->items->map(function($i) {
        return ['name' => $i->item_name, 'qty' => $i->quantity, 'notes' => $i->special_notes];
    })->values();
    return [
        'id' => $o->id,
        'order_number' => $o->order_number,
        'status' => $o->status,
        'priority' => (bool)$o->priority,
        'table' => $o->table ? $o->table->table_number : null,
        'items' => $items,
        'kitchen_notes' => $o->kitchen_notes,
        'elapsed_minutes' => $elapsed,
        'is_urgent' => $elapsed > 15,
        'created_at' => $o->created_at->format('H:i'),
    ];
})->values();
@endphp
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

        get aggregateItems() {
            const map = new Map();
            this.orders.forEach(o => {
                if (o.status !== 'held' && o.status !== 'preparing') return;
                (o.items || []).forEach(it => {
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

        startPolling() {
            this.$watch('viewMode', v => { try { localStorage.setItem('kds_view_mode', v); } catch(e){} });
            this.polling = setInterval(() => this.refreshOrders(), 15000);
            this.timerInterval = setInterval(() => {
                this.orders.forEach(o => { o.elapsed_minutes++; });
                const hasUrgent = this.orders.some(o => o.elapsed_minutes > 15 && (o.status === 'held' || o.status === 'preparing'));
                if (hasUrgent) this.playUrgentBeep();
            }, 60000);
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
                if (res.ok) this.orders = await res.json();
            } catch (e) {}
        },

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
                        // Flash the card green briefly
                        this.lastScanFlash = data.order_id;
                        setTimeout(() => { this.lastScanFlash = null; }, 1200);
                        // Update local state — set status to ready
                        const o = this.orders.find(x => x.id === data.order_id);
                        if (o) o.status = 'ready';
                        // Then refresh from server (auto-removes ready→completed transitions)
                        setTimeout(() => this.refreshOrders(), 800);
                    }
                } else {
                    this.playScanBeep(false);
                    this.showToast(data.message || 'Scan failed', 'error');
                }
            } catch (e) {
                this.playScanBeep(false);
                this.showToast('Scan error', 'error');
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

        async updateStatus(orderId, status) {
            try {
                const res = await fetch(`/pos/restaurant/kds/${orderId}/status`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ status }),
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast(data.message, 'success');
                    if (status === 'completed' || status === 'cancelled') {
                        this.orders = this.orders.filter(o => o.id !== orderId);
                    } else {
                        const order = this.orders.find(o => o.id === orderId);
                        if (order) order.status = status;
                    }
                } else {
                    this.showToast(data.message, 'error');
                }
            } catch (e) { this.showToast('Error updating order', 'error'); }
        },

        showToast(msg, type) {
            this.toast = { show: true, message: msg, type };
            setTimeout(() => this.toast.show = false, 3000);
        },
    };
}
</script>
</x-pos-layout>
