<x-fbr-pos-layout>
@php
    // Selection checkboxes + bulk bar are admin-only (endpoint 403s non-admins
    // anyway — don't render controls a cashier can't use). Labels stay open.
    $fbrIsAdmin = auth('fbrpos')->user() && auth('fbrpos')->user()->role === 'company_admin';
@endphp
<div class="max-w-6xl mx-auto" x-data="fbrProductBulk()">
    @include('fbr-pos.partials.back-link')
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.products_word') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.manage_products_tax_config') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            {{-- 🏷 Label print page (Task 1272) — selection-aware: picked rows preselect on the labels page --}}
            <a :href="labelsUrl()" href="{{ route('fbrpos.products.labels') }}"
               class="inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                <span x-text="selected.length > 0 ? @js(__('pos.print')) + ' ' + selected.length : @js(__('pos.print_labels'))">{{ __('pos.print_labels') }}</span>
            </a>
            <a href="{{ route('fbrpos.products.create') }}" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition shadow-sm">
                {{ __('pos.plus_new_product') }}
            </a>
        </div>
    </div>

    @if(session('success'))
    <div class="mb-4 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 rounded-xl text-sm">
        {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-xl text-sm">
        {{ session('error') }}
    </div>
    @endif

    {{-- Plan product usage vs cap (Task 362): visibility for at-cap / over-cap shops (e.g. after a downgrade) --}}
    @if(!empty($productLimitStatus))
        @if($productLimitStatus['over'])
        <div class="mb-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-xl text-sm">
            <span class="font-semibold">{{ __('pos.product_limit_usage', ['used' => $productLimitStatus['used'], 'limit' => $productLimitStatus['limit']]) }}</span><br>
            {{ __('pos.product_limit_over_cap', ['used' => $productLimitStatus['used'], 'limit' => $productLimitStatus['limit']]) }}
        </div>
        @elseif($productLimitStatus['used'] >= $productLimitStatus['limit'])
        <div class="mb-4 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 text-amber-800 dark:text-amber-300 rounded-xl text-sm">
            {{ __('pos.product_limit_at_cap', ['used' => $productLimitStatus['used'], 'limit' => $productLimitStatus['limit']]) }}
        </div>
        @else
        <div class="mb-4 text-xs text-gray-500 dark:text-gray-400">
            {{ __('pos.product_limit_usage', ['used' => $productLimitStatus['used'], 'limit' => $productLimitStatus['limit']]) }}
        </div>
        @endif
    @endif

    @if(auth('fbrpos')->user() && auth('fbrpos')->user()->role === 'company_admin'
        && \App\Services\PosFeatureService::planAllows($company ?? \App\Models\Company::find(app('currentCompanyId')), 'excel_enabled'))
    {{-- ═══ EXCEL EXPORT / BULK IMPORT (FBR mirror of the PRA POS round-trip) ═══
         Strict plan binding (Aug 2026): whole section hidden without excel_enabled —
         the template/import routes are fbrPlanGate('excel_enabled')-blocked too. --}}
    <div x-data="{ open: false }" class="mb-6">
        <button type="button" @click="open = !open"
                class="inline-flex items-center gap-1.5 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-gray-700 dark:text-gray-300 px-4 py-2 rounded-lg text-xs font-semibold shadow-sm hover:shadow transition">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            {{ __('pos.bulk_import_products_excel') }}
            <svg class="w-3 h-3 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>

        <div x-show="open" x-cloak class="mt-3 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md p-5">
            <div class="mb-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg px-4 py-3">
                <p class="text-xs text-blue-800 dark:text-blue-300"><strong>{{ __('pos.easy_way_label') }}</strong> {{ __('pos.import_easy_way_text') }}</p>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                    <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">{{ __('pos.import_step1_title') }}</h4>
                    @if($products->total() > 0)
                    <p class="text-xs text-gray-500 mb-3">{{ __('pos.import_step1_has_products') }}</p>
                    @else
                    <p class="text-xs text-gray-500 mb-3">{{ __('pos.import_step1_empty') }}</p>
                    @endif
                    <a href="{{ route('fbrpos.products.template') }}" class="inline-flex items-center gap-1.5 bg-gradient-to-r from-blue-500 to-blue-700 text-white px-5 py-2 rounded-lg text-xs font-semibold shadow-md hover:shadow-lg transition no-underline">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        {{ $products->total() > 0 ? __('pos.download_excel_count', ['count' => $products->total()]) : __('pos.download_excel_template') }}
                    </a>
                    <div class="mt-3 text-[11px] text-gray-400">
                        <p class="font-semibold text-gray-500 mb-1">{{ __('pos.only_two_required') }}</p>
                        <p>{!! __('pos.import_required_columns_note') !!}</p>
                    </div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                    <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">{{ __('pos.import_step2_title') }}</h4>
                    <p class="text-xs text-gray-500 mb-3">{{ __('pos.import_step2_hint') }}</p>
                    <form method="POST" action="{{ route('fbrpos.products.import') }}" enctype="multipart/form-data" class="space-y-3">
                        @csrf
                        <input type="file" name="csv_file" accept=".xlsx,.xls,.csv,.txt" required class="block w-full text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 dark:file:bg-blue-900/30 dark:file:text-blue-300">
                        <button type="submit" class="inline-flex items-center gap-1.5 bg-gradient-to-r from-emerald-500 to-emerald-700 text-white px-5 py-2 rounded-lg text-xs font-semibold shadow-md hover:shadow-lg transition">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                            {{ __('pos.upload_and_import') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endif

    <div class="mb-6">
        <form method="GET" action="{{ route('fbrpos.products') }}" class="flex flex-col sm:flex-row gap-3">
            <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('pos.search_name_hs_ph') }}"
                class="flex-1 rounded-lg bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-700 dark:text-white shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">{{ __('pos.search_word') }}</button>
            @if($search)
            <a href="{{ route('fbrpos.products') }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium hover:bg-gray-300 transition">{{ __('pos.clear_word') }}</a>
            @endif
        </form>
    </div>

    @if(auth('fbrpos')->user() && auth('fbrpos')->user()->role === 'company_admin')
    {{-- ═══ SALE-SCREEN VISIBILITY (bulk hide/show — admin only) ═══ --}}
    <div class="flex flex-wrap items-center gap-2 mb-4 p-3 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm">
        <span class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('pos.sale_screen_visibility') }}</span>
        <form method="POST" action="{{ route('fbrpos.products.bulk-sale') }}" class="inline"
              onsubmit="return confirm(@js(__('pos.confirm_hide_all_products')))">
            @csrf
            <input type="hidden" name="action" value="hide">
            <button type="submit" class="px-3 py-2 rounded-lg bg-gray-700 hover:bg-gray-800 text-white text-xs font-semibold">{{ __('pos.hide_all_btn') }}</button>
        </form>
        <form method="POST" action="{{ route('fbrpos.products.bulk-sale') }}" class="inline"
              onsubmit="return confirm(@js(__('pos.confirm_show_all_products')))">
            @csrf
            <input type="hidden" name="action" value="show">
            <button type="submit" class="px-3 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs font-semibold">{{ __('pos.show_all_btn') }}</button>
        </form>
        <span class="text-[11px] text-gray-400">{{ __('pos.hidden_products_hint') }}</span>
    </div>

    {{-- ═══ BULK ACTION BAR (Task 1272 — selected rows; PRA mirror, admin only) ═══ --}}
    <div x-show="selected.length > 0" x-transition x-cloak
         class="flex flex-wrap items-center gap-2 mb-4 p-3 rounded-xl bg-blue-600 text-white shadow-lg">
        <span class="font-bold text-sm" x-text="selected.length + ' ' + @js(__('pos.selected_word'))"></span>
        <div class="flex-1"></div>
        <button @click="doBulk('activate')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">{{ __('pos.activate') }}</button>
        <button @click="doBulk('deactivate')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">{{ __('pos.deactivate') }}</button>
        <button @click="doBulk('sale_show')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">{{ __('pos.bulk_show_on_grid') }}</button>
        <button @click="doBulk('sale_hide')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">{{ __('pos.bulk_hide_from_grid') }}</button>
        <button @click="doBulk('price')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">{{ __('pos.set_price') }}</button>
        <button @click="doBulk('price_percent')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">{{ __('pos.price_pct_plus_minus') }}</button>
        <button @click="doBulk('exempt_on')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">{{ __('pos.tax_exempt_on') }}</button>
        <button @click="doBulk('exempt_off')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">{{ __('pos.tax_exempt_off') }}</button>
        <button @click="doBulk('third_on')" class="px-3 py-1.5 rounded-lg bg-indigo-500/80 hover:bg-indigo-600/80 text-xs font-semibold">{{ __('pos.third_schedule_on') }}</button>
        <button @click="doBulk('third_off')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">{{ __('pos.third_schedule_off') }}</button>
        <button @click="doBulk('delete')" class="px-3 py-1.5 rounded-lg bg-red-500 hover:bg-red-600 text-xs font-semibold">{{ __('pos.delete') }}</button>
        <button @click="selected = []" class="px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-xs font-semibold">{{ __('pos.clear') }}</button>
    </div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    @if($fbrIsAdmin)
                    <th class="px-3 py-3 w-8"><input type="checkbox" @change="toggleAll($event)" :checked="allPageSelected" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"></th>
                    @endif
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.product_col') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.hs_code_col') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.tax_type_col') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.tax_rate_col') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.price_col') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.status_col') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.actions_col') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($products as $product)
                @php
                    $taxBadges = [
                        'taxable' => ['bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300', intval($product->default_tax_rate) . '%'],
                        'exempt' => ['bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300', __('pos.exempt_word')],
                        'custom' => ['bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300', intval($product->default_tax_rate) . '%'],
                    ];
                    $taxType = $product->tax_type ?? 'taxable';
                    $badge = $taxBadges[$taxType] ?? $taxBadges['taxable'];
                @endphp
                <tr class="even:bg-gray-50/50 dark:even:bg-gray-800/50 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                    @if($fbrIsAdmin)
                    <td class="px-3 py-3"><input type="checkbox" value="{{ $product->id }}" x-model.number="selected" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"></td>
                    @endif
                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">{{ $product->name }}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400 font-mono">{{ $product->hs_code ?: '-' }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $badge[0] }}">
                            {{ $badge[1] }}
                        </span>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium {{ $taxType === 'exempt' ? 'text-green-600' : 'text-amber-600' }}">
                        {{ $taxType === 'exempt' ? '0%' : intval($product->default_tax_rate) . '%' }}
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">PKR {{ number_format($product->default_price, 2) }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if($product->is_active)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">{{ __('pos.active_word') }}</span>
                        @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">{{ __('pos.inactive_word') }}</span>
                        @endif
                        @if(!($product->show_on_sale ?? true))
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-300 ml-1" title="{{ __('pos.title_hidden_from_sale') }}">{{ __('pos.hidden_word') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-right text-sm space-x-2">
                        <a href="{{ route('fbrpos.products.edit', $product->id) }}" class="text-blue-600 hover:text-blue-800 font-medium">{{ __('pos.edit') }}</a>
                        <form method="POST" action="{{ route('fbrpos.products.toggle-sale', $product->id) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 font-medium" title="{{ ($product->show_on_sale ?? true) ? __('pos.title_hide_from_sale_grid') : __('pos.title_show_on_sale_grid') }}">
                                {{ ($product->show_on_sale ?? true) ? __('pos.hide_word') : __('pos.show_word') }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('fbrpos.products.toggle', $product->id) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-{{ $product->is_active ? 'red' : 'green' }}-600 hover:text-{{ $product->is_active ? 'red' : 'green' }}-800 font-medium">
                                {{ $product->is_active ? __('pos.deactivate') : __('pos.activate') }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('fbrpos.products.destroy', $product->id) }}" class="inline" onsubmit="return confirm(@js(__('pos.confirm_delete_named', ['name' => $product->name])))">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-600 hover:text-red-800 font-medium">{{ __('pos.delete') }}</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $fbrIsAdmin ? 8 : 7 }}" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                        {{ __('pos.no_products_yet_short') }} <a href="{{ route('fbrpos.products.create') }}" class="text-blue-600 hover:text-blue-800 font-medium">{{ __('pos.add_your_first_product') }}</a>.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    @if($products->hasPages())
    <div class="mt-4">{{ $products->links() }}</div>
    @endif

    <script>
        // Task 1272 — selection + bulk ops (PRA productCatalog mirror, trimmed
        // for the server-paginated FBR list; select-all = current page).
        function fbrProductBulk() {
            return {
                selected: [],
                pageIds: @json($products->pluck('id')->map(fn($i) => (int) $i)->values()),
                csrf: '{{ csrf_token() }}',
                bulkUrl: '{{ route('fbrpos.products.bulk') }}',
                labelsBase: '{{ route('fbrpos.products.labels') }}',
                labelsUrl() { return this.selected.length > 0 ? this.labelsBase + '?ids=' + this.selected.join(',') : this.labelsBase; },
                get allPageSelected() {
                    return this.pageIds.length > 0 && this.pageIds.every(id => this.selected.includes(id));
                },
                toggleAll(e) {
                    if (e.target.checked) { this.selected = [...new Set([...this.selected, ...this.pageIds])]; }
                    else { this.selected = this.selected.filter(id => !this.pageIds.includes(id)); }
                },
                postForm(action, fields) {
                    const f = document.createElement('form');
                    f.method = 'POST'; f.action = action;
                    const add = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); };
                    add('_token', this.csrf);
                    for (const [k, v] of Object.entries(fields)) {
                        if (Array.isArray(v)) v.forEach(val => add(k + '[]', val));
                        else add(k, v);
                    }
                    document.body.appendChild(f); f.submit();
                },
                doBulk(action) {
                    if (this.selected.length === 0) return;
                    const fields = { action: action, ids: this.selected };
                    if (action === 'delete') { if (!confirm(@js(__('pos.js_confirm_bulk_delete')).replace(':count', this.selected.length))) return; }
                    if (action === 'price') {
                        const v = prompt(@js(__('pos.js_prompt_set_price')).replace(':count', this.selected.length), '');
                        if (v === null) return;
                        const num = parseFloat(String(v).replace(/[^0-9.]/g, ''));
                        if (isNaN(num) || num < 0) { alert(@js(__('pos.js_alert_invalid_price'))); return; }
                        if (!confirm(@js(__('pos.js_confirm_set_price')).replace(':count', this.selected.length).replace(':price', num))) return;
                        fields.price_value = num;
                    }
                    if (action === 'price_percent') {
                        const v = prompt(@js(__('pos.js_prompt_price_percent')), '');
                        if (v === null) return;
                        const num = parseFloat(v);
                        if (isNaN(num) || num === 0 || num < -90 || num > 500) { alert(@js(__('pos.js_alert_invalid_percent'))); return; }
                        if (!confirm(@js(__('pos.js_confirm_price_percent')).replace(':count', this.selected.length).replace(':percent', (num > 0 ? '+' : '') + num))) return;
                        fields.percent_value = num;
                    }
                    if (action === 'exempt_on' && !confirm(@js(__('pos.js_confirm_exempt_on')).replace(':count', this.selected.length))) return;
                    if (action === 'exempt_off' && !confirm(@js(__('pos.js_confirm_exempt_off')).replace(':count', this.selected.length))) return;
                    if (action === 'third_on' && !confirm(@js(__('pos.js_confirm_third_on')).replace(':count', this.selected.length))) return;
                    if (action === 'third_off' && !confirm(@js(__('pos.js_confirm_third_off')).replace(':count', this.selected.length))) return;
                    this.postForm(this.bulkUrl, fields);
                },
            };
        }
    </script>
</div>
</x-fbr-pos-layout>
