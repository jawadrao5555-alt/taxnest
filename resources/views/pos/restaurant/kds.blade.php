<x-pos-layout>
<div x-data="kdsScreen()" x-init="startPolling()" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
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
            <button @click="refreshOrders()" class="px-3 py-1.5 text-sm rounded-lg bg-purple-600 text-white hover:bg-purple-700 font-medium">Refresh</button>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        <template x-for="order in orders" :key="order.id">
            <div :class="{
                'border-amber-400 bg-amber-50 dark:bg-amber-900/10': order.status === 'held',
                'border-blue-400 bg-blue-50 dark:bg-blue-900/10': order.status === 'preparing',
                'border-green-400 bg-green-50 dark:bg-green-900/10': order.status === 'ready',
                'ring-2 ring-red-500': order.is_urgent
            }" class="border-2 rounded-xl overflow-hidden dark:border-opacity-50">
                <div class="px-4 py-3 flex items-center justify-between" :class="{
                    'bg-amber-100 dark:bg-amber-900/30': order.status === 'held',
                    'bg-blue-100 dark:bg-blue-900/30': order.status === 'preparing',
                    'bg-green-100 dark:bg-green-900/30': order.status === 'ready',
                }">
                    <div>
                        <span class="font-bold text-gray-900 dark:text-white text-sm" x-text="order.order_number"></span>
                        <span x-show="order.table" class="ml-2 text-xs bg-white dark:bg-gray-800 px-1.5 py-0.5 rounded text-purple-600 dark:text-purple-400 font-medium" x-text="'T-' + order.table"></span>
                    </div>
                    <div class="text-right">
                        <div class="text-xs font-medium" :class="order.is_urgent ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400'" x-text="order.elapsed_minutes + ' min'"></div>
                        <div class="text-[10px] text-gray-400" x-text="order.created_at"></div>
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
                        <button @click="updateStatus(order.id, 'completed')" class="flex-1 py-2 text-xs rounded-lg bg-gray-600 text-white hover:bg-gray-700 font-semibold">Done / Served</button>
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

<script>
function kdsScreen() {
    return {
        orders: @json($orders->map(function($o) {
            $elapsed = now()->diffInMinutes($o->created_at);
            return [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'status' => $o->status,
                'table' => $o->table?->table_number,
                'items' => $o->items->map(fn($i) => ['name' => $i->item_name, 'qty' => $i->quantity, 'notes' => $i->special_notes]),
                'kitchen_notes' => $o->kitchen_notes,
                'elapsed_minutes' => $elapsed,
                'is_urgent' => $elapsed > 15,
                'created_at' => $o->created_at->format('H:i'),
            ];
        })),
        polling: null,
        toast: { show: false, message: '', type: 'success' },

        startPolling() {
            this.polling = setInterval(() => this.refreshOrders(), 15000);
        },

        async refreshOrders() {
            try {
                const res = await fetch('{{ route("pos.restaurant.live-orders") }}');
                if (res.ok) this.orders = await res.json();
            } catch (e) {}
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
