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
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Waiter Tablet</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Compose the order, then send it to a cashier for payment.</p>
        </div>
        <div class="flex items-center gap-2">
            <button @click="openMyOrders()" class="relative px-4 py-2 rounded-xl text-sm font-bold bg-teal-600 hover:bg-teal-700 text-white transition">
                My Orders
                <span x-show="myOrders.length > 0" x-cloak class="absolute -top-1.5 -right-1.5 min-w-[20px] h-5 px-1 bg-amber-500 text-white text-[10px] rounded-full flex items-center justify-center font-bold" x-text="myOrders.length"></span>
            </button>
        </div>
    </div>

    {{-- ── Append-mode banner ─────────────────────────────────────────────── --}}
    <div x-show="appendOrderId" x-cloak class="mb-3 rounded-xl bg-amber-100 dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700 px-4 py-2.5 flex items-center justify-between gap-2 flex-wrap">
        <span class="text-sm font-bold text-amber-800 dark:text-amber-300">Adding items to <span class="font-mono" x-text="appendOrderNumber"></span> — only the NEW items will print in the kitchen.</span>
        <button @click="cancelAppend()" class="px-3 py-1.5 rounded-lg bg-white dark:bg-gray-800 text-xs font-bold text-gray-600 dark:text-gray-300 border border-gray-300 dark:border-gray-600">Cancel</button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- ── LEFT: product picker ──────────────────────────────────────── --}}
        <div class="lg:col-span-2">
            <input type="text" x-model="search" @input="filterProducts()"
                   autocomplete="off" name="waiter_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                   placeholder="Search items…"
                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-base px-4 py-3 focus:ring-teal-500 focus:border-teal-500 mb-3">
            <div class="flex gap-2 overflow-x-auto pb-2 mb-2" x-show="categories.length > 1">
                <button @click="activeCategory = 'all'; filterProducts()" :class="activeCategory === 'all' ? 'bg-teal-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700'" class="px-4 py-1.5 rounded-full text-xs font-bold whitespace-nowrap transition">All</button>
                <template x-for="c in categories" :key="c">
                    <button @click="activeCategory = c; filterProducts()" :class="activeCategory === c ? 'bg-teal-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700'" class="px-4 py-1.5 rounded-full text-xs font-bold whitespace-nowrap transition" x-text="c"></button>
                </template>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2.5 max-h-[60vh] overflow-y-auto pr-1">
                <template x-for="p in filtered" :key="p.id">
                    <button @click="addToCart(p)" class="rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:border-teal-500 dark:hover:border-teal-500 p-3 text-left transition active:scale-95">
                        <span class="block text-sm font-bold text-gray-800 dark:text-gray-100 leading-snug" x-text="p.name"></span>
                        <span class="block mt-1 text-xs font-black text-teal-700 dark:text-teal-400" x-text="'Rs ' + p.price.toLocaleString()"></span>
                    </button>
                </template>
                <div x-show="filtered.length === 0" class="col-span-full text-center py-8 text-sm text-gray-400">No items match.</div>
            </div>
        </div>

        {{-- ── RIGHT: order panel ────────────────────────────────────────── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-col gap-3 h-fit lg:sticky lg:top-4">
            <h2 class="text-sm font-black uppercase tracking-wide text-gray-500 dark:text-gray-400" x-text="appendOrderId ? 'New Items' : 'Order'"></h2>

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
                        <input type="text" x-model="line.special_notes" placeholder="Note for kitchen (optional)"
                               autocomplete="off" :name="'waiter_note_' + i + '_nofill'" data-lpignore="true" data-form-type="other" data-1p-ignore
                               class="mt-2 w-full rounded-lg border-gray-200 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-xs px-2.5 py-1.5 focus:ring-teal-500 focus:border-teal-500">
                    </div>
                </template>
            </div>
            <div x-show="!cart.length" class="text-center py-6 text-sm text-gray-400">Tap items to add them.</div>

            <div class="border-t border-gray-100 dark:border-gray-700 pt-3 flex items-center justify-between" x-show="cart.length">
                <span class="text-sm font-bold text-gray-500 dark:text-gray-400">Total (before tax)</span>
                <span class="text-xl font-black text-gray-900 dark:text-white" x-text="'Rs ' + total().toLocaleString()"></span>
            </div>

            {{-- Order details (hidden in append mode — the order already has them) --}}
            <template x-if="!appendOrderId">
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        <button @click="orderType = 'dine_in'" :class="orderType === 'dine_in' ? 'bg-teal-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'" class="py-2.5 rounded-xl text-xs font-bold transition">Dine In</button>
                        <button @click="orderType = 'takeaway'; selectedTable = null" :class="orderType === 'takeaway' ? 'bg-teal-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'" class="py-2.5 rounded-xl text-xs font-bold transition">Take Away</button>
                    </div>
                    <button x-show="orderType === 'dine_in'" @click="openTables()" class="w-full py-2.5 rounded-xl text-sm font-bold border-2 border-dashed transition"
                            :class="selectedTable ? 'border-teal-500 bg-teal-50 dark:bg-teal-900/20 text-teal-700 dark:text-teal-300' : 'border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400'"
                            x-text="selectedTable ? ('Table T-' + selectedTable.table_number + ' · ' + selectedTable.floor) : 'Choose Table'"></button>
                    <input type="text" x-model="customerName" placeholder="Customer name (optional)"
                           autocomplete="off" name="waiter_customer_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm px-3 py-2.5 focus:ring-teal-500 focus:border-teal-500">
                    <input type="text" x-model="customerPhone" placeholder="Customer phone (optional)" inputmode="tel"
                           autocomplete="off" name="waiter_phone_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm px-3 py-2.5 focus:ring-teal-500 focus:border-teal-500">
                    <textarea x-model="kitchenNotes" rows="2" placeholder="Kitchen note for the whole order (optional)"
                              autocomplete="off" name="waiter_kn_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                              class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm px-3 py-2.5 focus:ring-teal-500 focus:border-teal-500"></textarea>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">Send to cashier</label>
                        <select x-model="cashierId" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm px-3 py-2.5 focus:ring-teal-500 focus:border-teal-500">
                            <option value="">— choose cashier —</option>
                            @foreach($cashiers as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </template>

            <button @click="send()" :disabled="sending || !cart.length || (!appendOrderId && !cashierId)"
                    class="w-full py-3.5 rounded-xl bg-teal-600 hover:bg-teal-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-base font-black transition"
                    x-text="sending ? 'Sending…' : (appendOrderId ? 'ADD ITEMS TO ORDER' : 'SEND TO CASHIER')"></button>
        </div>
    </div>

    {{-- ── Table picker modal ─────────────────────────────────────────────── --}}
    <div x-show="showTables" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60" @click="showTables = false"></div>
        <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] flex flex-col overflow-hidden">
            <div class="px-5 py-4 bg-teal-600 flex items-center justify-between">
                <h3 class="text-white font-bold">Choose Table</h3>
                <button @click="showTables = false" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/25 text-white font-black">×</button>
            </div>
            <div class="flex-1 overflow-y-auto p-4">
                <div x-show="tablesLoading" class="text-center py-8 text-sm text-gray-400">Loading…</div>
                <div class="grid grid-cols-3 sm:grid-cols-4 gap-2.5">
                    <template x-for="t in tables" :key="t.id">
                        <button @click="pickTable(t)" :disabled="t.status === 'occupied'"
                                :class="t.status === 'occupied' ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-400 cursor-not-allowed' : (t.status === 'reserved' ? 'bg-amber-50 dark:bg-amber-900/20 border-amber-300 dark:border-amber-700 text-amber-700 dark:text-amber-300' : 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-300 dark:border-emerald-700 text-emerald-700 dark:text-emerald-300 hover:border-emerald-500')"
                                class="rounded-xl border-2 p-3 text-center transition">
                            <span class="block text-base font-black" x-text="'T-' + t.table_number"></span>
                            <span class="block text-[10px] font-bold mt-0.5" x-text="t.floor + ' · ' + t.seats + ' seats'"></span>
                            <span class="block text-[10px] font-bold uppercase mt-0.5" x-text="t.status"></span>
                        </button>
                    </template>
                </div>
                <div x-show="!tablesLoading && tables.length === 0" class="text-center py-8 text-sm text-gray-400">No tables configured.</div>
            </div>
        </div>
    </div>

    {{-- ── My Orders modal ────────────────────────────────────────────────── --}}
    <div x-show="showMyOrders" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60" @click="showMyOrders = false"></div>
        <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xl max-h-[80vh] flex flex-col overflow-hidden">
            <div class="px-5 py-4 bg-teal-600 flex items-center justify-between">
                <h3 class="text-white font-bold">My Open Orders</h3>
                <button @click="showMyOrders = false" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/25 text-white font-black">×</button>
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-3">
                <div x-show="myOrdersLoading" class="text-center py-8 text-sm text-gray-400">Loading…</div>
                <div x-show="!myOrdersLoading && myOrders.length === 0" class="text-center py-8 text-sm text-gray-400">No open orders — everything is settled.</div>
                <template x-for="o in myOrders" :key="o.id">
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-3">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-mono text-sm font-bold text-gray-800 dark:text-gray-100" x-text="o.order_number"></span>
                                <span x-show="o.table" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300" x-text="'T-' + o.table"></span>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300" x-text="'→ ' + (o.assigned_cashier || 'any cashier')"></span>
                            </div>
                            <span class="text-sm font-black text-gray-900 dark:text-white" x-text="'Rs ' + Math.round(o.total_amount).toLocaleString()"></span>
                        </div>
                        <div class="mt-1.5 text-xs text-gray-600 dark:text-gray-300 leading-relaxed">
                            <template x-for="(it, ix) in o.items" :key="ix"><span><span x-text="it.quantity + '× ' + it.name"></span><span x-show="ix < o.items.length - 1"> · </span></span></template>
                        </div>
                        <div class="mt-2.5">
                            <button @click="startAppend(o)" class="px-4 py-2 rounded-lg bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold transition">Add Items</button>
                        </div>
                    </div>
                </template>
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
        toast: '',
        toastType: 'success',
        _toastTimer: null,

        init() {
            this.categories = [...new Set(this.products.map(p => p.category))].sort();
            this.filterProducts();
            this.loadMyOrders();
            setInterval(() => { if (!document.hidden) this.loadMyOrders(); }, 30000);
        },

        filterProducts() {
            const q = this.search.trim().toLowerCase();
            this.filtered = this.products.filter(p =>
                (this.activeCategory === 'all' || p.category === this.activeCategory) &&
                (!q || p.name.toLowerCase().includes(q) || (p.barcode && p.barcode.toLowerCase() === q))
            );
        },

        addToCart(p) {
            const ex = this.cart.find(l => l.item_id === p.id);
            if (ex) { ex.quantity++; return; }
            this.cart.push({
                uid: 'w' + Date.now() + '_' + Math.random().toString(36).slice(2, 8),
                item_id: p.id, name: p.name, quantity: 1,
                unit_price: p.price, special_notes: '',
            });
        },

        total() {
            return Math.round(this.cart.reduce((s, l) => s + l.quantity * l.unit_price, 0));
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
            if (this.cart.length && !confirm('Discard the current unsent items?')) return;
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
                cashier_id: this.cashierId,
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
                    this.showToast((data && data.message) || 'Failed (HTTP ' + res.status + ')', 'error');
                } else {
                    this.showToast(data.message || 'Sent!', 'success');
                    this.cart = [];
                    this.customerName = ''; this.customerPhone = ''; this.kitchenNotes = '';
                    this.selectedTable = null;
                    this.appendOrderId = null; this.appendOrderNumber = '';
                    this.loadMyOrders();
                }
            } catch (e) {
                this.showToast('Network error — try again.', 'error');
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
