<x-fbr-pos-layout>
{{-- Purchase returns (Task 1580): surplus / wrong / damaged goods going back
     to the distributor. Distinct from pharmacy expiry CLAIMS — this moves
     stock out immediately and posts a credit note to the supplier ledger. --}}
@php
    $trim3 = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $flags = JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
    $bakedProducts = json_encode($products->map(fn ($p) => [
        'id' => (int) $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'uom' => $p->uom ?: 'U',
    ])->values(), $flags) ?: '[]';
    $bakedBills = json_encode($billsData, $flags) ?: '[]';
    $reasonLabels = collect($reasons)->mapWithKeys(fn ($r) => [$r => __('pos.sl_reason_' . $r)]);
@endphp
<div class="max-w-6xl mx-auto" x-data="returnsPage()">
    <a href="{{ route('fbrpos.stock') }}" class="inline-flex items-center gap-1 text-sm text-blue-600 hover:underline mb-3">← {{ __('pos.stock_page_title') }}</a>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">{{ __('pos.sl_returns_title') }} <x-new-badge feature="fbr_purchase_returns" /></h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.sl_returns_sub') }}</p>
        </div>
    </div>

    @if(session('success'))<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">{{ $errors->first() }}</div>@endif

    @include('fbr-pos.partials.branch-bar')

    <div class="grid lg:grid-cols-5 gap-6 mb-6">
        {{-- New return form --}}
        <div class="lg:col-span-3 bg-white dark:bg-gray-800 rounded-xl shadow p-5">
            <h3 class="font-bold text-gray-900 dark:text-white mb-3">{{ __('pos.sl_return_new_btn') }}</h3>
            <form method="POST" action="{{ route('fbrpos.stock.return.store') }}" @submit="if (!lines.length || !lines.every(l => num(l.quantity) > 0)) $event.preventDefault()">
                @csrf
                {{-- Mode: against a received bill, or free-form for a supplier --}}
                <div class="flex rounded-lg overflow-hidden border border-gray-200 dark:border-gray-600 mb-3 text-sm font-bold">
                    <button type="button" @click="setMode('bill')" :class="mode === 'bill' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300'" class="flex-1 py-2">{{ __('pos.sl_mode_bill') }}</button>
                    <button type="button" @click="setMode('free')" :class="mode === 'free' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300'" class="flex-1 py-2">{{ __('pos.sl_mode_free') }}</button>
                </div>

                <div x-show="mode === 'bill'">
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.sl_against_bill_lbl') }}</label>
                    <select name="purchase_order_id" x-model="poId" @change="loadBill()" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600 mb-3">
                        <option value="">{{ __('pos.sl_pick_bill') }}</option>
                        <template x-for="b in bills" :key="b.id">
                            <option :value="b.id" x-text="b.label + (b.supplier ? ' · ' + b.supplier : '')" :selected="String(b.id) === String(poId)"></option>
                        </template>
                    </select>
                    <p x-show="billLoading" class="text-xs text-gray-400 mb-2">{{ __('pos.stock_purch_loading') }}</p>
                    <p x-show="billError" x-cloak class="text-xs text-red-600 mb-2" x-text="billError"></p>
                    {{-- Lines from the bill: tick what goes back --}}
                    <div x-show="billLines.length > 0" x-cloak class="mb-3 rounded-lg border border-gray-200 dark:border-gray-600 divide-y dark:divide-gray-700 max-h-72 overflow-y-auto">
                        <template x-for="l in billLines" :key="l.item_id">
                            <label class="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer" :class="l.remaining <= 0 ? 'opacity-50' : ''">
                                <input type="checkbox" :disabled="l.remaining <= 0" :checked="isPicked(l)" @change="togglePicked(l, $event.target.checked)" class="rounded">
                                <span class="flex-1 min-w-0">
                                    <span class="font-semibold text-gray-900 dark:text-white truncate block" x-text="l.name"></span>
                                    <span class="text-[11px] text-gray-400">
                                        <span x-text="'{{ __('pos.sl_received_short') }} ' + l.received + ' ' + l.uom"></span>
                                        <span x-show="l.returned > 0" x-text="' · {{ __('pos.sl_already_returned') }} ' + l.returned"></span>
                                        <span x-show="l.batch_number" x-text="' · ' + l.batch_number"></span>
                                    </span>
                                </span>
                                <span class="text-xs text-gray-500 whitespace-nowrap" x-text="'Rs ' + fmt2(l.unit_cost)"></span>
                            </label>
                        </template>
                    </div>
                </div>

                <div x-show="mode === 'free'" x-cloak>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.sl_pdf_supplier') }}</label>
                    <select name="supplier_id" x-model="supplierId" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600 mb-3">
                        <option value="">{{ __('pos.sl_pick_supplier') }}</option>
                        @foreach($suppliers as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.stock_add_product_lbl') }}</label>
                    <div class="relative mb-3">
                        <input type="text" x-model="prodSearch" @input="searchProducts()" @keydown.enter.prevent="pickFirst()"
                               placeholder="{{ __('pos.ph_stock_search') }}" autocomplete="off" name="ret_prod_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                               class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        <div x-show="prodResults.length > 0" x-cloak class="absolute z-20 mt-1 w-full bg-white dark:bg-gray-800 border dark:border-gray-600 rounded-lg shadow-lg max-h-52 overflow-y-auto">
                            <template x-for="p in prodResults" :key="p.id">
                                <button type="button" @click="addFree(p)" class="w-full text-left px-3 py-2 text-sm hover:bg-blue-50 dark:hover:bg-gray-700 dark:text-white border-b dark:border-gray-700 last:border-0">
                                    <span x-text="p.name" class="font-semibold"></span>
                                    <span class="text-xs text-gray-400 ml-1" x-text="p.sku ? '· ' + p.sku : ''"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- The lines actually going back --}}
                <template x-for="(l, i) in lines" :key="l.key">
                    <div class="mb-2 bg-gray-50 dark:bg-gray-700/50 rounded-lg px-3 py-2">
                        <input type="hidden" :name="`items[${i}][product_id]`" :value="l.product_id">
                        <input type="hidden" :name="`items[${i}][purchase_order_item_id]`" :value="l.item_id || ''">
                        <input type="hidden" :name="`items[${i}][batch_id]`" :value="l.batch_id || ''">
                        <div class="flex items-center gap-2">
                            <span class="flex-1 text-sm font-semibold text-gray-900 dark:text-white truncate" x-text="l.name"></span>
                            <input type="number" :name="`items[${i}][quantity]`" x-model="l.quantity" step="0.001" min="0.001" :max="l.remaining || null" required
                                   :placeholder="@js(__('pos.stock_qty_ph')) + ' (' + l.uom + ')'" class="w-24 border rounded px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                            <input type="number" :name="`items[${i}][unit_cost]`" x-model="l.unit_cost" step="0.01" min="0" required
                                   placeholder="{{ __('pos.stock_kharid_rate_ph') }}" class="w-28 border rounded px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                            <button type="button" @click="removeLine(i)" class="text-red-500 hover:text-red-700 font-bold px-1">&times;</button>
                        </div>
                        @if($batchTracking)
                        <div class="flex items-center gap-2 mt-2 pt-2 border-t border-gray-200 dark:border-gray-600">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 shrink-0">💊</span>
                            <template x-if="l.batches && l.batches.length > 0">
                                <select @change="pickBatch(l, $event.target.value)" class="flex-1 min-w-0 border rounded px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                                    <option value="">{{ __('pos.sl_batch_untracked') }}</option>
                                    <template x-for="b in l.batches" :key="b.id">
                                        <option :value="b.id" :selected="String(b.id) === String(l.batch_id)" x-text="b.batch + (b.expiry ? ' · ' + b.expiry : '') + ' · ' + b.quantity"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="!l.batches || l.batches.length === 0">
                                <input type="text" :name="`items[${i}][batch_number]`" x-model="l.batch_number" maxlength="60" autocomplete="off"
                                       placeholder="{{ __('pos.ph_col_batch') }}" class="flex-1 min-w-0 border rounded px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                            </template>
                            <span class="text-xs text-gray-400 whitespace-nowrap" x-show="l.remaining !== null && l.remaining !== undefined" x-text="'{{ __('pos.sl_max_short') }} ' + l.remaining"></span>
                        </div>
                        @else
                        <p class="text-[11px] text-gray-400 mt-1" x-show="l.remaining !== null && l.remaining !== undefined" x-text="'{{ __('pos.sl_max_short') }} ' + l.remaining + ' ' + l.uom"></p>
                        @endif
                    </div>
                </template>

                <div class="grid grid-cols-2 gap-2 mt-3">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.sl_return_reason_lbl') }}</label>
                        <select name="reason" required class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                            @foreach($reasonLabels as $k => $lbl)
                            <option value="{{ $k }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.sl_return_date_lbl') }}</label>
                        <input type="date" name="returned_on" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                    </div>
                    <input type="text" name="supplier_reference" maxlength="60" placeholder="{{ __('pos.sl_return_sup_ref_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                    <input type="text" name="notes" maxlength="500" placeholder="{{ __('pos.stock_note_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                    @if($multiBranch ?? false)
                    <div class="col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.sl_branch_lbl') }}</label>
                        <select name="branch_id" required class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                            <option value="">{{ __('pos.branch_select') }}</option>
                            @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ (int) ($activeBranchId ?? 0) === (int) $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif
                </div>

                <div class="flex items-center justify-between mt-3 mb-3 text-sm">
                    <span class="text-gray-600 dark:text-gray-300">{{ __('pos.sl_credit_note_total') }}</span>
                    <span class="font-extrabold text-lg text-purple-700 dark:text-purple-300" x-text="'Rs ' + fmt2(creditTotal())"></span>
                </div>
                <button type="submit" :disabled="lines.length === 0"
                        class="w-full py-2.5 rounded-lg bg-purple-600 text-white font-bold hover:bg-purple-700 disabled:opacity-40 disabled:cursor-not-allowed">
                    {{ __('pos.sl_return_post_btn') }}
                </button>
                <p class="text-[11px] text-gray-400 mt-2">{{ __('pos.sl_return_hint') }}</p>
            </form>
        </div>

        {{-- Recent returns --}}
        <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow p-5">
            <h3 class="font-bold text-gray-900 dark:text-white mb-3">{{ __('pos.sl_returns_recent') }}</h3>
            @if($returns->isEmpty())
                <p class="text-sm text-gray-400 text-center py-6">{{ __('pos.sl_returns_none') }}</p>
            @else
            <div class="divide-y dark:divide-gray-700">
                @foreach($returns as $r)
                <div class="py-2.5 text-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <a href="{{ route('fbrpos.stock.return.print', $r->id) }}" class="font-semibold text-blue-600 dark:text-blue-400 hover:underline">{{ $r->return_number }}</a>
                            <span class="text-xs text-gray-400 ml-1">{{ $r->returned_on?->format('d M Y') }}</span>
                            <p class="text-xs text-gray-600 dark:text-gray-300 truncate">{{ $r->supplier?->name ?? '—' }}{{ $r->purchaseOrder ? ' · ' . $r->purchaseOrder->po_number : '' }} · {{ $reasonLabels[$r->reason] ?? $r->reason }}</p>
                            <p class="text-[11px] text-gray-400 truncate">
                                @foreach($r->items as $it){{ $loop->first ? '' : ', ' }}{{ $it->product?->name ?? '#' . $it->product_id }} ×{{ $trim3($it->quantity) }}@endforeach
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-bold text-gray-900 dark:text-white">Rs {{ number_format($r->credit_amount, 2) }}</p>
                            <a href="{{ route('fbrpos.stock.return.print', $r->id) }}" target="_blank" class="text-xs font-bold text-purple-700 dark:text-purple-300 hover:underline">{{ __('pos.print') }}</a>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            <div class="mt-3">{{ $returns->links() }}</div>
            @endif
        </div>
    </div>
</div>

<script>
function returnsPage() {
    return {
        mode: 'bill',
        bills: {!! $bakedBills !!},
        allProducts: {!! $bakedProducts !!},
        batchTracking: {{ $batchTracking ? 'true' : 'false' }},
        poId: {{ $preselectPo ? (int) $preselectPo : 'null' }},
        supplierId: {{ $preselectSupplier ? (int) $preselectSupplier : 'null' }},
        billLines: [],
        billLoading: false,
        billError: '',
        lines: [],
        prodSearch: '',
        prodResults: [],
        init() {
            if (this.poId) { this.loadBill(); }
            else if (this.supplierId) { this.mode = 'free'; }
        },
        num(v) { const n = parseFloat(v); return isNaN(n) ? 0 : n; },
        fmt2(v) { return (Math.round((this.num(v) + Number.EPSILON) * 100) / 100).toFixed(2); },
        creditTotal() { return this.lines.reduce((s, l) => s + this.num(l.quantity) * this.num(l.unit_cost), 0); },
        setMode(m) {
            if (m === this.mode) return;
            this.mode = m;
            this.lines = [];
            if (m === 'free') { this.poId = ''; this.billLines = []; }
        },
        async loadBill() {
            this.lines = [];
            this.billLines = [];
            this.billError = '';
            if (!this.poId) return;
            this.billLoading = true;
            try {
                const res = await fetch(`{{ url('/fbr-pos/stock/purchases') }}/${this.poId}/lines`, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) { this.billError = @js(__('pos.sl_bill_not_found')); return; }
                const data = await res.json();
                if (data.purchase && data.purchase.voided) { this.billError = @js(__('pos.sl_return_bill_void')); return; }
                this.billLines = data.lines || [];
                if (data.purchase && data.purchase.supplier_id) this.supplierId = data.purchase.supplier_id;
            } catch (e) {
                this.billError = @js(__('pos.sl_bill_not_found'));
            } finally {
                this.billLoading = false;
            }
        },
        isPicked(l) { return this.lines.some(x => x.item_id === l.item_id); },
        async togglePicked(l, on) {
            if (!on) { this.lines = this.lines.filter(x => x.item_id !== l.item_id); return; }
            if (this.isPicked(l)) return;
            const line = {
                key: 'i' + l.item_id, item_id: l.item_id, product_id: l.product_id, name: l.name, uom: l.uom,
                quantity: '', unit_cost: this.fmt2(l.unit_cost), remaining: l.remaining,
                batch_id: '', batch_number: l.batch_number || '', batches: [],
            };
            this.lines.push(line);
            if (this.batchTracking) await this.fetchBatches(line);
        },
        addFree(p) {
            if (!this.lines.some(x => x.product_id === p.id && !x.item_id)) {
                const line = { key: 'p' + p.id, item_id: null, product_id: p.id, name: p.name, uom: p.uom || 'U',
                    quantity: '', unit_cost: '', remaining: null, batch_id: '', batch_number: '', batches: [] };
                this.lines.push(line);
                if (this.batchTracking) this.fetchBatches(line);
            }
            this.prodSearch = '';
            this.prodResults = [];
        },
        removeLine(i) { this.lines.splice(i, 1); },
        // Batch picker (pharmacy): live batches of this product on the active branch.
        async fetchBatches(line) {
            try {
                const res = await fetch(`{{ route('fbrpos.pharmacy.batch.options', [], false) }}?product_id=${line.product_id}`, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const data = await res.json();
                const batches = (data.batches || []).filter(b => b.quantity > 0);
                const target = this.lines.find(x => x.key === line.key);
                if (!target) return;
                target.batches = batches;
                // Pre-pick the batch the bill line carried, when it still exists.
                if (target.batch_number) {
                    const m = batches.find(b => b.batch === target.batch_number);
                    if (m) this.pickBatch(target, m.id);
                }
            } catch (e) {}
        },
        pickBatch(line, id) {
            line.batch_id = id || '';
            if (!id) { line.batch_number = ''; return; }
            const b = (line.batches || []).find(x => String(x.id) === String(id));
            if (b) {
                line.batch_number = b.batch;
                if (!line.item_id && !line.unit_cost && b.cost > 0) line.unit_cost = this.fmt2(b.cost);
            }
        },
        searchProducts() {
            const q = this.prodSearch.trim().toLowerCase();
            if (q.length < 1) { this.prodResults = []; return; }
            this.prodResults = this.allProducts.filter(p =>
                (p.name || '').toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q) || (p.barcode || '').toLowerCase().includes(q)
            ).slice(0, 12);
        },
        pickFirst() { if (this.prodResults.length > 0) this.addFree(this.prodResults[0]); },
    };
}
</script>
</x-fbr-pos-layout>
