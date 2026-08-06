<x-fbr-pos-layout>
<div class="max-w-6xl mx-auto" x-data="stockPage()">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Stock &amp; Purchase</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Maal ka hisaab — stock, kam-stock alerts, supplier aur purchase entry</p>
        </div>
        <div class="flex items-center gap-3">
            {{-- Stock tracking toggle --}}
            <form method="POST" action="{{ route('fbrpos.stock.toggle') }}">
                @csrf
                <input type="hidden" name="enabled" value="{{ $stockEnabled ? 0 : 1 }}">
                <button type="submit"
                        onclick="return confirm('{{ $stockEnabled ? 'Stock tracking OFF karein? Sale par stock nahi katega.' : 'Stock tracking ON karein? Har sale par stock khud kam hoga.' }}')"
                        class="flex items-center gap-2 px-4 py-2 rounded-xl border-2 text-sm font-bold transition {{ $stockEnabled ? 'bg-green-50 border-green-400 text-green-700 dark:bg-green-900/20 dark:text-green-400' : 'bg-gray-50 border-gray-300 text-gray-500 dark:bg-gray-800 dark:border-gray-600' }}">
                    <span class="w-2.5 h-2.5 rounded-full {{ $stockEnabled ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                    Stock Tracking {{ $stockEnabled ? 'ON' : 'OFF' }}
                </button>
            </form>
            <a href="{{ route('fbrpos.create') }}" class="text-sm text-blue-600 hover:underline">← Sale Screen</a>
        </div>
    </div>

    @if(session('success'))<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">{{ session('error') }}</div>@endif

    @if(!$stockEnabled)
    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 px-4 py-3 rounded-lg mb-5 text-sm">
        {{ __('pos.stock_off_notice') }}
    </div>
    @endif

    {{-- Stat tiles --}}
    <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Products</p>
            <p class="text-2xl font-extrabold text-gray-900 dark:text-white mt-1">{{ $rows->count() }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border-l-4 {{ $lowStock->count() > 0 ? 'border-amber-500' : 'border-gray-200 dark:border-gray-700' }}">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Kam Stock Alert</p>
            <p class="text-2xl font-extrabold {{ $lowStock->count() > 0 ? 'text-amber-600' : 'text-gray-900 dark:text-white' }} mt-1">{{ $lowStock->count() }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border-l-4 {{ $negative->count() > 0 ? 'border-red-500' : 'border-gray-200 dark:border-gray-700' }}">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Minus Stock</p>
            <p class="text-2xl font-extrabold {{ $negative->count() > 0 ? 'text-red-600' : 'text-gray-900 dark:text-white' }} mt-1">{{ $negative->count() }}</p>
        </div>
    </div>

    {{-- Low stock alert list --}}
    @if($lowStock->isNotEmpty())
    <div class="bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800 rounded-xl p-4 mb-6">
        <h3 class="font-bold text-amber-800 dark:text-amber-300 mb-2 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.74-2.991l-7-12a2 2 0 00-3.48 0l-7 12A2 2 0 005 19z"/></svg>
            {{ __('pos.stock_low_title') }}
        </h3>
        <div class="flex flex-wrap gap-2">
            @foreach($lowStock as $r)
            <span class="px-3 py-1.5 rounded-lg bg-white dark:bg-gray-800 border border-amber-300 dark:border-amber-700 text-sm">
                <strong class="text-gray-900 dark:text-white">{{ $r->name }}</strong>
                <span class="text-amber-700 dark:text-amber-400 font-bold ml-1">{{ rtrim(rtrim(number_format($r->quantity, 3), '0'), '.') }} {{ $r->uom }}</span>
                <span class="text-gray-400 text-xs">(min {{ rtrim(rtrim(number_format($r->min_stock_level, 3), '0'), '.') }})</span>
            </span>
            @endforeach
        </div>
    </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        {{-- Purchase entry --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5">
            <h3 class="font-bold text-gray-900 dark:text-white mb-3">Naya Stock Receive (Purchase)</h3>
            <form method="POST" action="{{ route('fbrpos.stock.purchase') }}" @submit="return purchaseRows.length > 0">
                @csrf
                <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">Supplier (optional)</label>
                <select name="supplier_id" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600 mb-3">
                    <option value="">— Bina supplier —</option>
                    @foreach($suppliers as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}{{ $s->city ? ' (' . $s->city . ')' : '' }}</option>
                    @endforeach
                </select>

                <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.stock_add_product_lbl') }}</label>
                <div class="relative mb-3">
                    <input type="text" x-model="prodSearch" @input="searchProducts()" @keydown.enter.prevent="pickFirst()"
                           placeholder="{{ __('pos.ph_stock_search') }}" autocomplete="off" name="stock_prod_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                    <div x-show="prodResults.length > 0" x-cloak class="absolute z-20 mt-1 w-full bg-white dark:bg-gray-800 border dark:border-gray-600 rounded-lg shadow-lg max-h-52 overflow-y-auto">
                        <template x-for="p in prodResults" :key="p.id">
                            <button type="button" @click="addRow(p)" class="w-full text-left px-3 py-2 text-sm hover:bg-blue-50 dark:hover:bg-gray-700 dark:text-white border-b dark:border-gray-700 last:border-0">
                                <span x-text="p.name" class="font-semibold"></span>
                                <span class="text-xs text-gray-400 ml-1" x-text="p.sku ? '· ' + p.sku : ''"></span>
                                <span class="text-xs text-gray-400 ml-1" x-text="'· ' + p.uom"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <template x-for="(row, i) in purchaseRows" :key="row.product_id">
                    <div class="flex items-center gap-2 mb-2 bg-gray-50 dark:bg-gray-700/50 rounded-lg px-3 py-2">
                        <input type="hidden" :name="`items[${i}][product_id]`" :value="row.product_id">
                        <span class="flex-1 text-sm font-semibold text-gray-900 dark:text-white truncate" x-text="row.name"></span>
                        <input type="number" :name="`items[${i}][quantity]`" x-model="row.quantity" step="0.001" min="0.001" required
                               :placeholder="'Qty (' + row.uom + ')'" class="w-24 border rounded px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        <input type="number" :name="`items[${i}][unit_price]`" x-model="row.unit_price" step="0.01" min="0" required
                               placeholder="Kharid Rate" class="w-28 border rounded px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        <button type="button" @click="purchaseRows.splice(i, 1)" class="text-red-500 hover:text-red-700 font-bold px-1">&times;</button>
                    </div>
                </template>

                <input type="text" name="notes" maxlength="300" placeholder="Note (optional)" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600 mt-2 mb-3">

                <button type="submit" :disabled="purchaseRows.length === 0"
                        class="w-full py-2.5 rounded-lg bg-blue-600 text-white font-bold hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed">
                    {{ __('pos.stock_receive_btn') }}
                </button>
            </form>
        </div>

        {{-- Suppliers --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5">
            <h3 class="font-bold text-gray-900 dark:text-white mb-3">Suppliers</h3>
            <form method="POST" action="{{ route('fbrpos.stock.supplier') }}" class="grid grid-cols-2 gap-2 mb-4">
                @csrf
                <input type="text" name="name" required maxlength="150" placeholder="Supplier ka naam *" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="col-span-2 border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                <input type="text" name="phone" maxlength="30" placeholder="Phone" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                <input type="text" name="city" maxlength="80" placeholder="Sheher" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                <button type="submit" class="col-span-2 py-2 rounded-lg bg-gray-800 dark:bg-gray-600 text-white text-sm font-bold hover:bg-gray-900">+ Supplier Add</button>
            </form>
            @if($suppliers->isEmpty())
                <p class="text-sm text-gray-400 text-center py-4">{{ __('pos.stock_no_suppliers') }}</p>
            @else
            <div class="max-h-48 overflow-y-auto divide-y dark:divide-gray-700">
                @foreach($suppliers as $s)
                <div class="py-2 flex items-center justify-between text-sm">
                    <div>
                        <p class="font-semibold text-gray-900 dark:text-white">{{ $s->name }}</p>
                        <p class="text-xs text-gray-400">{{ $s->phone ?: '' }}{{ $s->city ? ' · ' . $s->city : '' }}</p>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    {{-- Stock list --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h3 class="font-bold text-gray-900 dark:text-white">Stock List</h3>
            <input type="text" x-model="stockFilter" placeholder="Product search..." autocomplete="off" name="stock_list_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                   class="border rounded-lg px-3 py-1.5 text-sm w-48 dark:bg-gray-700 dark:text-white dark:border-gray-600">
        </div>
        <div class="max-h-[480px] overflow-y-auto">
            <table class="w-full text-sm table-cards">
                <thead class="bg-gray-50 dark:bg-gray-700 text-left sticky top-0">
                    <tr>
                        <th class="px-4 py-2">Product</th>
                        <th class="px-4 py-2 text-right">Stock</th>
                        <th class="px-4 py-2 text-right">Min Level</th>
                        <th class="px-4 py-2 text-right">Last Kharid</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                    <tr class="border-t dark:border-gray-700"
                        x-show="stockFilter === '' || '{{ strtolower(addslashes($r->name . ' ' . ($r->sku ?? ''))) }}'.includes(stockFilter.toLowerCase())">
                        <td class="px-4 py-2">
                            <span class="font-semibold text-gray-900 dark:text-white">{{ $r->name }}</span>
                            <span class="text-xs text-gray-400 ml-1">{{ $r->sku ? '· ' . $r->sku : '' }}</span>
                        </td>
                        <td class="px-4 py-2 text-right font-bold {{ $r->quantity < 0 ? 'text-red-600' : ($r->min_stock_level > 0 && $r->quantity <= $r->min_stock_level ? 'text-amber-600' : 'text-gray-900 dark:text-white') }}">
                            {{ rtrim(rtrim(number_format($r->quantity, 3), '0'), '.') }} <span class="text-xs font-normal text-gray-400">{{ $r->uom }}</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" value="{{ $r->min_stock_level > 0 ? rtrim(rtrim(number_format($r->min_stock_level, 3, '.', ''), '0'), '.') : '' }}"
                                   step="0.001" min="0" placeholder="—"
                                   @change="saveMinLevel({{ $r->product_id }}, $event.target)"
                                   class="w-20 border rounded px-2 py-1 text-right text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        </td>
                        <td class="px-4 py-2 text-right text-gray-500 dark:text-gray-400">{{ $r->last_purchase_price > 0 ? 'Rs ' . number_format($r->last_purchase_price, 2) : '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Recent purchases --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
            <h3 class="font-bold text-gray-900 dark:text-white">Recent Purchases</h3>
        </div>
        @if($recentPurchases->isEmpty())
            <p class="text-sm text-gray-400 text-center py-6">{{ __('pos.stock_no_purchases') }}</p>
        @else
        <table class="w-full text-sm table-cards">
            <thead class="bg-gray-50 dark:bg-gray-700 text-left">
                <tr><th class="px-4 py-2">Number</th><th class="px-4 py-2">Date</th><th class="px-4 py-2">Supplier</th><th class="px-4 py-2 text-right">Items</th><th class="px-4 py-2 text-right">Total</th></tr>
            </thead>
            <tbody>
                @foreach($recentPurchases as $po)
                <tr class="border-t dark:border-gray-700">
                    <td class="px-4 py-2 font-semibold text-gray-900 dark:text-white">{{ $po->po_number }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ $po->received_date?->format('d M Y') ?? $po->created_at->format('d M Y') }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ $po->supplier?->name ?? '—' }}</td>
                    <td class="px-4 py-2 text-right text-gray-500">{{ $po->items->count() }}</td>
                    <td class="px-4 py-2 text-right font-bold text-gray-900 dark:text-white">Rs {{ number_format($po->total_amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>

<script>
function stockPage() {
    return {
        stockFilter: '',
        prodSearch: '',
        prodResults: [],
        purchaseRows: [],
        // Baked product list for instant client-side search (name/sku/barcode).
        // NOTE: complex expressions inside the json Blade directive break its
        // paren matcher (nested fn arrows) — the compiled view got truncated.
        // So: build the collection in a php block, UTF-8-safe encode + fallback.
        @php
            $bakedStockProducts = $rows->map(fn ($r) => ['id' => $r->product_id, 'name' => $r->name, 'sku' => $r->sku, 'uom' => $r->uom])->values();
            $bakedStockJson = json_encode($bakedStockProducts, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]';
        @endphp
        allProducts: {!! $bakedStockJson !!},
        searchProducts() {
            const q = this.prodSearch.trim().toLowerCase();
            if (q.length < 1) { this.prodResults = []; return; }
            this.prodResults = this.allProducts.filter(p =>
                (p.name || '').toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q)
            ).slice(0, 12);
        },
        pickFirst() { if (this.prodResults.length > 0) this.addRow(this.prodResults[0]); },
        addRow(p) {
            if (!this.purchaseRows.find(r => r.product_id === p.id)) {
                this.purchaseRows.push({ product_id: p.id, name: p.name, uom: p.uom || 'U', quantity: '', unit_price: '' });
            }
            this.prodSearch = '';
            this.prodResults = [];
        },
        async saveMinLevel(productId, el) {
            const val = parseFloat(el.value || '0') || 0;
            try {
                const res = await fetch(`{{ route('fbrpos.stock.minlevel') }}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ product_id: productId, min_stock_level: val }),
                });
                if (res.ok) {
                    el.classList.add('ring-2', 'ring-green-400');
                    setTimeout(() => el.classList.remove('ring-2', 'ring-green-400'), 900);
                }
            } catch (e) {}
        },
    };
}
</script>
</x-fbr-pos-layout>
