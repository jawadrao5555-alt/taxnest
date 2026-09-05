{{--
    💊 Batch & Expiry (Task 1558) — the shelf as a medical store actually sees
    it: not "42 in stock" but "18 of batch A2291 dying in March, 24 of B7710
    good till next year". Every filter chip here answers a question the shop
    asks out loud — what is about to die, what is already dead, what is boxed
    up waiting for the distributor.

    Expects: $batches (paginator), $counts, $filter, $search, $suppliers,
             $nearDays, plus the branch view-model.
--}}
<x-fbr-pos-layout>
<div class="max-w-7xl mx-auto" x-data="pharmacyBatches()">
    @include('fbr-pos.partials.back-link')

    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">💊 {{ __('pos.ph_batches_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.ph_batches_sub') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('fbrpos.pharmacy.reports') }}" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">{{ __('pos.ph_nav_reports') }}</a>
            <a href="{{ route('fbrpos.pharmacy.claims') }}" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">{{ __('pos.ph_nav_claims') }}</a>
            <button type="button" @click="openAdd()" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-sm font-bold hover:bg-emerald-700">+ {{ __('pos.ph_add_batch') }}</button>
        </div>
    </div>

    @if(session('success'))<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm">{{ $errors->first() }}</div>@endif

    @include('fbr-pos.partials.branch-bar')

    {{-- Filter chips double as the summary: the number IS the answer. --}}
    <div class="flex flex-wrap gap-2 mb-4">
        @foreach([
            'all' => ['label' => __('pos.ph_filter_all'), 'cls' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200', 'on' => 'bg-gray-800 text-white'],
            'near' => ['label' => __('pos.ph_filter_near', ['days' => $nearDays]), 'cls' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300', 'on' => 'bg-amber-500 text-white'],
            'expired' => ['label' => __('pos.ph_filter_expired'), 'cls' => 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300', 'on' => 'bg-red-600 text-white'],
            'quarantined' => ['label' => __('pos.ph_filter_quarantined'), 'cls' => 'bg-orange-50 text-orange-700 dark:bg-orange-900/20 dark:text-orange-300', 'on' => 'bg-orange-500 text-white'],
            'written_off' => ['label' => __('pos.ph_filter_written_off'), 'cls' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300', 'on' => 'bg-slate-700 text-white'],
        ] as $key => $chip)
            <a href="{{ route('fbrpos.pharmacy.batches', ['filter' => $key, 'q' => $search]) }}"
               class="px-3.5 py-2 rounded-xl text-xs font-bold transition {{ $filter === $key ? $chip['on'] : $chip['cls'] }}">
                {{ $chip['label'] }}
                <span class="ml-1 opacity-70">{{ $counts[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    <form method="GET" class="mb-4 flex gap-2">
        <input type="hidden" name="filter" value="{{ $filter }}">
        <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('pos.ph_search_batch_ph') }}"
               class="flex-1 rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
        <button class="px-4 py-2 rounded-xl bg-blue-600 text-white text-sm font-semibold">{{ __('pos.filter_btn') }}</button>
    </form>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_medicine') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_batch') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_expiry') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_qty') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_cost') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_supplier') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($batches as $b)
                    @php
                        $days = $b->daysToExpiry();
                        $expired = $b->isExpired();
                    @endphp
                    <tr class="{{ $expired ? 'bg-red-50/50 dark:bg-red-900/10' : '' }}">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $b->product?->name ?? '—' }}</div>
                            @if($b->product?->generic_name)
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $b->product->generic_name }} {{ $b->product->strength }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $b->batch_number }}</td>
                        <td class="px-4 py-3">
                            @if($b->expiry_date)
                                <span class="font-semibold {{ $expired ? 'text-red-600 dark:text-red-400' : ($days !== null && $days <= $nearDays ? 'text-amber-600 dark:text-amber-400' : 'text-gray-700 dark:text-gray-300') }}">
                                    {{ $b->expiry_date->format('d M Y') }}
                                </span>
                                <div class="text-[11px] {{ $expired ? 'text-red-500' : 'text-gray-400' }}">
                                    @if($expired)
                                        {{ __('pos.ph_expired_ago', ['days' => abs($days)]) }}
                                    @else
                                        {{ __('pos.ph_days_left', ['days' => $days]) }}
                                    @endif
                                </div>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">{{ rtrim(rtrim(number_format((float) $b->quantity, 3, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-300">{{ number_format((float) $b->cost_price, 2) }}</td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $b->supplier?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @php
                                $statusCls = [
                                    \App\Models\ProductBatch::STATUS_ACTIVE => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                                    \App\Models\ProductBatch::STATUS_QUARANTINED => 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300',
                                    \App\Models\ProductBatch::STATUS_WRITTEN_OFF => 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200',
                                ][$b->status] ?? 'bg-gray-100 text-gray-600';
                            @endphp
                            <span class="px-2 py-1 rounded-lg text-[11px] font-bold {{ $statusCls }}">{{ __('pos.ph_status_' . $b->status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if($b->status === \App\Models\ProductBatch::STATUS_ACTIVE)
                                <button type="button" @click="openAction({{ $b->id }}, 'quarantine', @js($b->batch_number))" class="text-xs font-semibold text-orange-600 hover:underline">{{ __('pos.ph_act_quarantine') }}</button>
                            @elseif($b->status === \App\Models\ProductBatch::STATUS_QUARANTINED)
                                <button type="button" @click="openAction({{ $b->id }}, 'release', @js($b->batch_number))" class="text-xs font-semibold text-green-600 hover:underline">{{ __('pos.ph_act_release') }}</button>
                            @endif
                            @if($b->status !== \App\Models\ProductBatch::STATUS_WRITTEN_OFF && (float) $b->quantity > 0)
                                <button type="button" @click="openAction({{ $b->id }}, 'write_off', @js($b->batch_number))" class="ml-3 text-xs font-semibold text-red-600 hover:underline">{{ __('pos.ph_act_write_off') }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400 text-sm">{{ __('pos.ph_no_batches') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $batches->links() }}</div>

    {{-- ── Add / opening batch ─────────────────────────────────────────── --}}
    <div x-show="showAdd" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showAdd = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl w-full max-w-lg p-5 max-h-[90vh] overflow-y-auto">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">{{ __('pos.ph_add_batch') }}</h2>
            <form method="POST" action="{{ route('fbrpos.pharmacy.batch.store') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_medicine') }}</label>
                    <input type="text" x-model="productSearch" @input.debounce.300ms="lookupProducts()" placeholder="{{ __('pos.ph_search_medicine_ph') }}"
                           class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                    <input type="hidden" name="product_id" :value="productId" required>
                    <div x-show="productHits.length" class="mt-1 border border-gray-200 dark:border-gray-700 rounded-lg max-h-44 overflow-y-auto">
                        <template x-for="p in productHits" :key="p.id">
                            <button type="button" @click="pickProduct(p)" class="block w-full text-left px-3 py-2 text-sm hover:bg-emerald-50 dark:hover:bg-emerald-900/20 dark:text-gray-200">
                                <span x-text="p.name" class="font-semibold"></span>
                                <span x-text="p.generic_name ? ' — ' + p.generic_name : ''" class="text-xs text-gray-500"></span>
                            </button>
                        </template>
                    </div>
                    <p x-show="productId" class="mt-1 text-xs text-emerald-600 font-semibold" x-text="productName"></p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_batch') }}</label>
                        <input type="text" name="batch_number" required class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_expiry') }}</label>
                        <input type="text" name="expiry_date" placeholder="{{ __('pos.ph_expiry_ph') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                        <p class="text-[11px] text-gray-400 mt-0.5">{{ __('pos.ph_expiry_hint') }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_qty') }}</label>
                        <input type="number" name="quantity" step="0.001" min="0.001" required class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_cost') }}</label>
                        <input type="number" name="cost_price" step="0.01" min="0" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_retail') }}</label>
                        <input type="number" name="retail_price" step="0.01" min="0" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_supplier') }}</label>
                        <select name="supplier_id" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                            <option value="">—</option>
                            @foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                        </select>
                    </div>
                </div>
                @if($multiBranch ?? false)
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.stock_lands_in_branch') }}</label>
                    <select name="branch_id" required class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                        <option value="">{{ __('pos.branch_select') }}</option>
                        @foreach($branches as $b)<option value="{{ $b->id }}" {{ (int) ($activeBranchId ?? 0) === (int) $b->id ? 'selected' : '' }}>{{ $b->name }}</option>@endforeach
                    </select>
                </div>
                @endif
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_note') }}</label>
                    <input type="text" name="notes" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="showAdd = false" class="px-4 py-2 rounded-xl border border-gray-300 dark:border-gray-700 text-sm font-semibold dark:text-gray-200">{{ __('pos.cancel') }}</button>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-sm font-bold">{{ __('pos.save') }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ── Quarantine / release / write-off ────────────────────────────── --}}
    <div x-show="showAction" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showAction = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl w-full max-w-md p-5">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-1" x-text="actionTitle"></h2>
            <p class="text-xs text-gray-500 mb-4">{{ __('pos.ph_col_batch') }}: <span class="font-mono" x-text="actionBatchNo"></span></p>
            <form method="POST" :action="actionUrl" class="space-y-3">
                @csrf
                <input type="hidden" name="action" :value="actionType">
                <div x-show="actionType === 'write_off'">
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_writeoff_reason') }} *</label>
                    <select name="reason" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                        @foreach(\App\Models\PharmacyStockAction::REASONS as $r)
                            <option value="{{ $r }}">{{ __('pos.ph_reason_' . $r) }}</option>
                        @endforeach
                    </select>
                </div>
                <div x-show="actionType !== 'write_off'">
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_writeoff_reason') }}</label>
                    <select name="reason" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                        @foreach(\App\Models\PharmacyStockAction::REASONS as $r)
                            <option value="{{ $r }}">{{ __('pos.ph_reason_' . $r) }}</option>
                        @endforeach
                    </select>
                </div>
                <div x-show="actionType === 'write_off'">
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_responsible') }} *</label>
                    <input type="text" name="responsible_name" :required="actionType === 'write_off'" placeholder="{{ __('pos.ph_responsible_ph') }}"
                           class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                    <p class="text-[11px] text-gray-400 mt-0.5">{{ __('pos.ph_responsible_hint') }}</p>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_note') }}</label>
                    <input type="text" name="notes" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="showAction = false" class="px-4 py-2 rounded-xl border border-gray-300 dark:border-gray-700 text-sm font-semibold dark:text-gray-200">{{ __('pos.cancel') }}</button>
                    <button type="submit" class="px-4 py-2 rounded-xl text-white text-sm font-bold" :class="actionType === 'write_off' ? 'bg-red-600' : 'bg-emerald-600'">{{ __('pos.confirm') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function pharmacyBatches() {
    return {
        showAdd: false,
        showAction: false,
        productSearch: '',
        productHits: [],
        productId: '',
        productName: '',
        actionId: null,
        actionType: 'quarantine',
        actionBatchNo: '',
        get actionUrl() {
            return this.actionId ? '{{ url('fbr-pos/pharmacy/batches') }}/' + this.actionId + '/action' : '#';
        },
        get actionTitle() {
            return {
                quarantine: @js(__('pos.ph_act_quarantine')),
                release: @js(__('pos.ph_act_release')),
                write_off: @js(__('pos.ph_act_write_off')),
            }[this.actionType] || '';
        },
        openAdd() {
            this.showAdd = true;
            this.productSearch = '';
            this.productHits = [];
            this.productId = '';
            this.productName = '';
        },
        openAction(id, type, batchNo) {
            this.actionId = id;
            this.actionType = type;
            this.actionBatchNo = batchNo;
            this.showAction = true;
        },
        async lookupProducts() {
            const q = this.productSearch.trim();
            if (q.length < 2) { this.productHits = []; return; }
            try {
                const r = await fetch('{{ route('fbrpos.api.products.search', [], false) }}?q=' + encodeURIComponent(q), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!r.ok) { this.productHits = []; return; }
                this.productHits = await r.json();
            } catch (e) { this.productHits = []; }
        },
        pickProduct(p) {
            this.productId = p.id;
            this.productName = p.name;
            this.productSearch = p.name;
            this.productHits = [];
        },
    };
}
</script>
</x-fbr-pos-layout>
