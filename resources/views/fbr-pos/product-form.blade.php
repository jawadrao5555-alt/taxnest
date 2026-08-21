<x-fbr-pos-layout>
@php
    // Task 1261: single-page product add — supplier block, save-and-continue
    // sticky defaults, multi-row entry mode. Edit mode keeps the classic
    // single-product form (no mode toggle, no supplier block).
    $isEdit = isset($product);
    $sticky = ($sticky ?? []) ?: [];
    $suppliers = $suppliers ?? collect();
    $inventoryAllowed = (bool) ($inventoryAllowed ?? false);

    $initMode = $isEdit ? 'single' : (string) old('entry_mode', $sticky['entry_mode'] ?? 'single');
    if (!in_array($initMode, ['single', 'multi'], true)) { $initMode = 'single'; }

    // Task 1262: Exempt is the default for new products (most entries are
    // exempt). Edit mode / old-input redisplay / sticky keep their own value.
    $initTaxType = (string) old('tax_type', $product->tax_type ?? ($sticky['tax_type'] ?? 'exempt'));
    $initTaxRate = old('default_tax_rate', $product->default_tax_rate ?? ($sticky['default_tax_rate'] ?? 18));
    $initTaxRate = $initTaxRate === null || $initTaxRate === '' ? 18 : $initTaxRate;
    $initThird = old('is_third_schedule', $isEdit ? ($product->is_third_schedule ?? false) : ($sticky['is_third_schedule'] ?? false));
    $initPriceEditable = old('is_price_editable', $isEdit ? $product->is_price_editable : ($sticky['is_price_editable'] ?? true));
    $currentUom = old('uom', $product->uom ?? ($sticky['uom'] ?? 'U'));
    $stickySupplierId = (string) old('supplier_id', $sticky['supplier_id'] ?? '');

    // Multi-mode rows: re-fill every row from old input after a validation
    // failure (rows are never lost); otherwise start with one blank row.
    $seedRows = [];
    if (!$isEdit) {
        foreach ((array) old('rows', []) as $oldRow) {
            if (!is_array($oldRow)) { continue; }
            $seedRows[] = [
                'name' => (string) ($oldRow['name'] ?? ''),
                'default_price' => (string) ($oldRow['default_price'] ?? ''),
                'mrp' => (string) ($oldRow['mrp'] ?? ''),
                'barcode' => (string) ($oldRow['barcode'] ?? ''),
                'opening_stock' => (string) ($oldRow['opening_stock'] ?? ''),
                'unit_cost' => (string) ($oldRow['unit_cost'] ?? ''),
            ];
        }
        if (empty($seedRows)) {
            $seedRows[] = ['name' => '', 'default_price' => '', 'mrp' => '', 'barcode' => '', 'opening_stock' => '', 'unit_cost' => ''];
        }
    }
    // UTF-8-safe encode (never a bare @json that can die on a malformed byte).
    $seedRowsJson = json_encode($seedRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';

    // Which row indexes failed validation — highlights the row boxes.
    $errRowIdx = [];
    foreach ($errors->keys() as $errKey) {
        if (preg_match('/^rows\.(\d+)\./', $errKey, $m)) { $errRowIdx[] = (int) $m[1]; }
    }
    $errRowIdx = array_values(array_unique($errRowIdx));
    $errRowIdxJson = json_encode($errRowIdx) ?: '[]';
@endphp
<div class="mx-auto" :class="mode === 'multi' ? 'max-w-5xl' : 'max-w-3xl'" x-data="fbrProductForm()">
    @include('fbr-pos.partials.back-link')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $isEdit ? __('pos.edit_product') : __('pos.new_product') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $isEdit ? __('pos.update_product_tax_settings') : __('pos.add_product_tax_config') }}</p>
        </div>
        <a href="{{ route('fbrpos.products') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">{{ __('pos.back_to_products') }}</a>
    </div>

    @if($errors->any())
    <div class="mb-4 p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-xl">
        <ul class="list-disc list-inside text-sm">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    @unless($isEdit)
    {{-- Entry mode toggle (Task 1261): single product vs multi-row table --}}
    <div class="mb-5 inline-flex rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-1 shadow-sm">
        <button type="button" @click="mode = 'single'"
                :class="mode === 'single' ? 'bg-blue-600 text-white shadow' : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white'"
                class="px-4 py-2 rounded-lg text-sm font-bold transition">{{ __('pos.fbr_pf_mode_single') }}</button>
        <button type="button" @click="mode = 'multi'"
                :class="mode === 'multi' ? 'bg-blue-600 text-white shadow' : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white'"
                class="px-4 py-2 rounded-lg text-sm font-bold transition">{{ __('pos.fbr_pf_mode_multi') }}</button>
    </div>
    @endunless

    <form method="POST" action="{{ $isEdit ? route('fbrpos.products.update', $product->id) : route('fbrpos.products.store') }}" class="space-y-6">
        @csrf
        @if($isEdit) @method('PUT') @endif
        @unless($isEdit)
        <input type="hidden" name="entry_mode" :value="mode">
        <input type="hidden" name="save_action" value="stay" x-ref="saveAction">
        @endunless

        @unless($isEdit)
        {{-- Multi-row entry table (Task 1261) --}}
        <div x-show="mode === 'multi'" x-cloak class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4 pb-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('pos.fbr_pf_rows_heading') }}</h3>
                <p class="text-xs text-gray-400">{{ __('pos.fbr_pf_multi_hint') }}</p>
            </div>
            <div class="hidden md:flex gap-2 text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">
                <div class="grid flex-1 grid-cols-12 gap-2 px-2">
                    <div class="col-span-4">{{ __('pos.product_name_label') }} *</div>
                    <div class="col-span-2">{{ __('pos.price_pkr') }} *</div>
                    <div class="col-span-2" x-show="thirdSchedule" x-cloak>{{ __('pos.mrp_label') }} *</div>
                    <div class="col-span-{{ $inventoryAllowed ? 2 : 4 }}">{{ __('pos.barcode_label') }}</div>
                    <div class="col-span-2">{{ __('pos.fbr_pf_opening_stock') }}</div>
                    @if($inventoryAllowed)
                    <div class="col-span-2">{{ __('pos.stock_kharid_rate_ph') }}</div>
                    @endif
                </div>
                <div class="w-8 shrink-0"></div>
            </div>
            <template x-for="(row, i) in rows" :key="row._k">
                <div class="flex items-start gap-2 mb-2">
                    <div class="grid flex-1 grid-cols-2 md:grid-cols-12 gap-2 p-2 rounded-lg border"
                         :class="errRows.includes(i) ? 'border-red-400 bg-red-50 dark:bg-red-900/10' : 'border-gray-200 dark:border-gray-700'">
                        <div class="col-span-2 md:col-span-4">
                            <input type="text" :name="`rows[${i}][name]`" x-model="row.name" maxlength="255" required :disabled="mode !== 'multi'"
                                   placeholder="{{ __('pos.product_name_label') }} *" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="md:col-span-2">
                            <input type="number" :name="`rows[${i}][default_price]`" x-model="row.default_price" step="0.01" min="0" required :disabled="mode !== 'multi'"
                                   placeholder="{{ __('pos.price_pkr') }} *"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        {{-- Per-row MRP — Third Schedule (shared checkbox) demands a retail price per product --}}
                        <div class="md:col-span-2" x-show="thirdSchedule" x-cloak>
                            <input type="number" :name="`rows[${i}][mrp]`" x-model="row.mrp" step="0.01" min="0.01" :disabled="mode !== 'multi' || !thirdSchedule"
                                   placeholder="{{ __('pos.mrp_label') }} *"
                                   class="w-full rounded-lg border-blue-300 dark:border-blue-700 dark:bg-gray-800 dark:text-white shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="md:col-span-{{ $inventoryAllowed ? 2 : 4 }}">
                            <input type="text" :name="`rows[${i}][barcode]`" x-model="row.barcode" maxlength="64" :disabled="mode !== 'multi'"
                                   placeholder="{{ __('pos.barcode_label') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm text-sm font-mono focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="md:col-span-2">
                            <input type="number" :name="`rows[${i}][opening_stock]`" x-model="row.opening_stock" step="0.001" min="0" :disabled="mode !== 'multi'"
                                   placeholder="{{ __('pos.fbr_pf_opening_stock') }}"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        @if($inventoryAllowed)
                        <div class="md:col-span-2">
                            <input type="number" :name="`rows[${i}][unit_cost]`" x-model="row.unit_cost" step="0.01" min="0" :disabled="mode !== 'multi'"
                                   placeholder="{{ __('pos.stock_kharid_rate_ph') }}"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        @endif
                    </div>
                    <button type="button" @click="removeRow(i)" x-show="rows.length > 1"
                            class="mt-3 w-8 h-8 shrink-0 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 font-bold text-lg leading-none"
                            aria-label="{{ __('pos.cancel') }}">&times;</button>
                </div>
            </template>
            <button type="button" @click="addRow()"
                    class="mt-1 w-full sm:w-auto px-4 py-2 rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 text-sm font-bold text-gray-600 dark:text-gray-300 hover:border-blue-400 hover:text-blue-600 transition">
                {{ __('pos.fbr_pf_add_row') }}
            </button>
            @error('rows')<p class="text-xs text-red-600 mt-2">{{ $message }}</p>@enderror
        </div>
        @endunless

        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-5 pb-3 border-b border-gray-200 dark:border-gray-700">
                <span x-show="mode === 'single'">{{ __('pos.product_details') }}</span>
                @unless($isEdit)<span x-show="mode === 'multi'" x-cloak>{{ __('pos.fbr_pf_shared_heading') }}</span>@endunless
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2" x-show="mode === 'single'">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.product_name_label') }} <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $product->name ?? '') }}" required :disabled="mode === 'multi'"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="{{ __('pos.ph_chicken_burger') }}">
                </div>
                <div x-show="mode === 'single'">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.price_pkr') }} <span class="text-red-500">*</span></label>
                    <input type="number" step="0.01" min="0" name="default_price" x-model="price" required :disabled="mode === 'multi'"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="0.00">
                    <label class="mt-2 flex items-start gap-2 cursor-pointer p-2.5 rounded-lg border-2 transition"
                        :class="isPriceEditable ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800'">
                        <input type="hidden" name="is_price_editable" value="0" :disabled="mode === 'multi'">
                        <input type="checkbox" name="is_price_editable" value="1" x-model="isPriceEditable" :disabled="mode === 'multi'"
                            class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                        <div>
                            <span class="text-xs font-bold text-gray-800 dark:text-gray-100" x-text="isPriceEditable ? @js(__('pos.price_editable_at_pos')) : @js(__('pos.price_fixed_locked_pos'))"></span>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5" x-text="isPriceEditable ? @js(__('pos.price_editable_hint')) : @js(__('pos.price_fixed_hint'))"></p>
                        </div>
                    </label>
                </div>
                <div x-show="mode === 'single'">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.hs_code_col') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_optional') }}</span></label>
                    <input type="text" name="hs_code" value="{{ old('hs_code', $product->hs_code ?? '') }}" :disabled="mode === 'multi'"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="00000000">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.uom_label') }}</label>
                    <select name="uom" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        @php
                            $uomList = [
                                'U' => 'Units',
                                'PCS' => 'Pieces',
                                'KG' => 'Kilogram',
                                'GM' => 'Gram',
                                'LTR' => 'Liter',
                                'ML' => 'Milliliter',
                                'MTR' => 'Meter',
                                'SQM' => 'Square Meter',
                                'FT' => 'Feet',
                                'IN' => 'Inch',
                                'YDS' => 'Yards',
                                'PKT' => 'Packet',
                                'DOZ' => 'Dozen',
                                'BOX' => 'Box',
                                'CTN' => 'Carton',
                                'BAG' => 'Bag',
                                'BTL' => 'Bottle',
                                'TIN' => 'Tin',
                                'CAN' => 'Can',
                                'BUN' => 'Bundle',
                                'ROL' => 'Roll',
                                'SET' => 'Set',
                            ];
                        @endphp
                        @foreach($uomList as $code => $label)
                            <option value="{{ $code }}" {{ $currentUom == $code ? 'selected' : '' }}>{{ $code }} — {{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div x-show="mode === 'single'">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.barcode_label') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_ean_upc_optional') }}</span></label>
                    <input type="text" name="barcode" value="{{ old('barcode', $product->barcode ?? '') }}" :disabled="mode === 'multi'"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono"
                        placeholder="{{ __('pos.ph_scan_barcode') }}">
                </div>
                <div x-show="mode === 'single'">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.sku_label') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_internal_optional') }}</span></label>
                    <input type="text" name="sku" value="{{ old('sku', $product->sku ?? '') }}" :disabled="mode === 'multi'"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono"
                        placeholder="{{ __('pos.ph_sku_example') }}">
                </div>
                @if($isEdit)
                {{-- Full product edit (Task 1276): remaining FBR reference fields --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.pct_code_label') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_optional') }}</span></label>
                    <input type="text" name="pct_code" value="{{ old('pct_code', $product->pct_code ?? '') }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono"
                        placeholder="0000.0000">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.sro_reference_label') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_optional') }}</span></label>
                    <input type="text" name="sro_reference" value="{{ old('sro_reference', $product->sro_reference ?? '') }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="SRO 1125(I)/2011">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.serial_number') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_optional') }}</span></label>
                    <input type="text" name="serial_number" value="{{ old('serial_number', $product->serial_number ?? '') }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="{{ __('pos.ph_eg_5') }}">
                </div>
                @endif
                @php
                    // Retail Core (Aug 2026): current stock row — min-stock default
                    // and the edit-mode stock adjustment card (Task 1276).
                    // Per-branch stock (Task 1365): the row belongs to the shop the
                    // user is standing in, and any adjustment saved from this form
                    // lands on that same shelf (FbrPosController::panelStockBranchId).
                    $pfCompanyId = (int) ($isEdit
                        ? $product->company_id
                        : (app()->bound('currentCompanyId') ? app('currentCompanyId') : 0));
                    // From the owner's "sab branches" view there is no single
                    // shelf to read or write: show the company total read-only
                    // and switch every stock control off, rather than letting
                    // the save silently land on head office.
                    $pfAllBranches = $pfCompanyId > 0
                        && \App\Services\BranchStockService::viewingAllBranches($pfCompanyId);
                    $pfBranchId = ($isEdit && !$pfAllBranches)
                        ? \App\Services\BranchStockService::writeBranchId($pfCompanyId, null)
                        : null;
                    $stockRow = ($isEdit && !$pfAllBranches)
                        ? \App\Models\InventoryStock::where('company_id', $product->company_id)
                            ->where('product_id', $product->id)
                            ->where(fn ($q) => $pfBranchId ? $q->where('branch_id', $pfBranchId) : $q->whereNull('branch_id'))
                            ->first()
                        : null;
                    $pfTrimQty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
                    $currentStockQty = ($isEdit && $pfAllBranches)
                        ? $pfTrimQty(\App\Models\InventoryStock::where('company_id', $product->company_id)
                            ->where('product_id', $product->id)->sum('quantity'))
                        : ($stockRow ? $pfTrimQty($stockRow->quantity) : '0');
                    $pfBranchName = $pfBranchId
                        ? \App\Services\BranchStockService::branchName($pfCompanyId, $pfBranchId)
                        : null;
                @endphp
                @if($pfAllBranches)
                {{-- Task 1365: one clear reason why the stock boxes are off. --}}
                <div class="md:col-span-2 flex items-start gap-2 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
                    <svg class="w-4 h-4 mt-0.5 shrink-0 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    <p class="text-xs font-semibold text-amber-800 dark:text-amber-200">{{ __('pos.stock_edit_pick_branch') }}</p>
                </div>
                @endif
                @unless($isEdit)
                {{-- Edit mode handles stock in its own adjustment card (Task 1276) --}}
                <div x-show="mode === 'single'">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.fbr_pf_opening_stock') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_optional') }}</span></label>
                    <input type="number" step="0.001" min="0" name="opening_stock" value="{{ old('opening_stock') }}" :disabled="mode === 'multi'" @disabled($pfAllBranches)
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="0">
                </div>
                @endunless
                @if(!$isEdit && $inventoryAllowed)
                <div x-show="mode === 'single'">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.stock_kharid_rate_ph') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_optional') }}</span></label>
                    <input type="number" step="0.01" min="0" name="unit_cost" value="{{ old('unit_cost') }}" :disabled="mode === 'multi'"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="0.00">
                    <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.fbr_pf_unit_cost_hint') }}</p>
                </div>
                @endif
                <div x-show="mode === 'single'">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Min Stock Alert Level <span class="text-gray-400 text-xs">{{ __('pos.paren_optional') }}</span></label>
                    <input type="number" step="0.001" min="0" name="min_stock_level" value="{{ old('min_stock_level', $stockRow->min_stock_level ?? '') }}" :disabled="mode === 'multi'" @disabled($pfAllBranches)
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        placeholder="e.g. 10">
                    <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.min_level_alert_hint') }}</p>
                </div>
                @if($isEdit)
                {{-- Show-on-sale + Active toggles (Task 1276) — same columns as the list-page quick toggles --}}
                <div class="md:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="flex items-start gap-2 cursor-pointer p-2.5 rounded-lg border-2 transition"
                        :class="showOnSale ? 'border-blue-300 bg-blue-50 dark:border-blue-700 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800'">
                        <input type="hidden" name="show_on_sale" value="0">
                        <input type="checkbox" name="show_on_sale" value="1" x-model="showOnSale"
                            class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <div>
                            <span class="text-xs font-bold text-gray-800 dark:text-gray-100">{{ __('pos.show_on_sale_screen') }}</span>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.show_on_sale_screen_hint') }}</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer p-2.5 rounded-lg border-2 transition"
                        :class="isActiveP ? 'border-green-300 bg-green-50 dark:border-green-700 dark:bg-green-900/20' : 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/10'">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" x-model="isActiveP"
                            class="mt-0.5 rounded border-gray-300 text-green-600 focus:ring-green-500">
                        <div>
                            <span class="text-xs font-bold" :class="isActiveP ? 'text-gray-800 dark:text-gray-100' : 'text-red-700 dark:text-red-300'"
                                x-text="isActiveP ? @js(__('pos.active_word')) : @js(__('pos.inactive_word'))"></span>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.fbr_pf_active_hint') }}</p>
                        </div>
                    </label>
                </div>
                @endif
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-5 pb-3 border-b border-gray-200 dark:border-gray-700">{{ __('pos.tax_configuration') }}</h3>

            {{-- Task 1262: Exempt first — it's the default and the most common choice. --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
                <label class="relative flex cursor-pointer rounded-xl border-2 p-4 transition"
                    :class="taxType === 'exempt' ? 'border-green-500 bg-green-50 dark:bg-green-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300'">
                    <input type="radio" name="tax_type" value="exempt" x-model="taxType" class="sr-only">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="w-6 h-6 rounded-full bg-green-100 text-green-700 flex items-center justify-center text-xs font-bold">0</span>
                            <span class="font-semibold text-gray-900 dark:text-white text-sm">{{ __('pos.exempt_word') }}</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.no_tax_food_medicine') }}</p>
                    </div>
                    <div class="absolute top-2 right-2" x-show="taxType === 'exempt'">
                        <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    </div>
                </label>

                <label class="relative flex cursor-pointer rounded-xl border-2 p-4 transition"
                    :class="taxType === 'taxable' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300'">
                    <input type="radio" name="tax_type" value="taxable" x-model="taxType" class="sr-only">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="w-6 h-6 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center text-xs font-bold">%</span>
                            <span class="font-semibold text-gray-900 dark:text-white text-sm">{{ __('pos.taxable_word') }}</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.standard_gst_applies') }}</p>
                    </div>
                    <div class="absolute top-2 right-2" x-show="taxType === 'taxable'">
                        <svg class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    </div>
                </label>

                <label class="relative flex cursor-pointer rounded-xl border-2 p-4 transition"
                    :class="taxType === 'custom' ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300'">
                    <input type="radio" name="tax_type" value="custom" x-model="taxType" class="sr-only">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="w-6 h-6 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center text-xs font-bold">C</span>
                            <span class="font-semibold text-gray-900 dark:text-white text-sm">{{ __('pos.custom_rate_word') }}</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.set_own_tax_rate') }}</p>
                    </div>
                    <div class="absolute top-2 right-2" x-show="taxType === 'custom'">
                        <svg class="w-5 h-5 text-purple-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    </div>
                </label>
            </div>

            <div x-show="taxType === 'custom'" x-transition class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.custom_tax_rate_pct') }} <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" min="0" max="100" x-model="taxRate"
                    class="w-full md:w-48 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                    placeholder="{{ __('pos.ph_eg_5') }}">
            </div>
            <input type="hidden" name="default_tax_rate" :value="taxType === 'taxable' ? 18 : (taxType === 'exempt' ? 0 : taxRate)">

            {{-- Third Schedule (SRO third-schedule items: tax already paid by manufacturer at retail price).
                 Matches the PRA products design: blue checkbox that auto-flips tax type to Exempt. --}}
            <label class="flex items-center gap-2 mb-4 cursor-pointer select-none">
                <input type="checkbox" name="is_third_schedule" value="1" x-model="thirdSchedule"
                       @change="if(thirdSchedule){ taxType = 'exempt'; }"
                       class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="text-xs font-bold uppercase tracking-wider"
                      :class="thirdSchedule ? 'text-blue-700 dark:text-blue-300' : 'text-gray-600 dark:text-gray-400'">
                    {{ __('pos.third_schedule_label') }}
                </span>
            </label>

            {{-- MRP / Retail Price (Task 1276) — prominent when Third Schedule is on:
                 FBR requires the manufacturer's retail price for third-schedule items.
                 Single mode only: multi mode collects a per-row MRP in the table. --}}
            <div x-show="mode === 'single'" class="mb-4 p-3 rounded-xl border-2 transition"
                 :class="thirdSchedule ? 'border-blue-400 bg-blue-50 dark:border-blue-600 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700'">
                <label class="block text-sm font-medium mb-1"
                       :class="thirdSchedule ? 'text-blue-800 dark:text-blue-200 font-bold' : 'text-gray-700 dark:text-gray-300'">
                    {{ __('pos.mrp_label') }}
                    <span x-show="thirdSchedule" class="text-red-500">*</span>
                    <span x-show="!thirdSchedule" class="text-gray-400 text-xs">{{ __('pos.paren_optional') }}</span>
                </label>
                <input type="number" step="0.01" min="0" name="mrp" value="{{ old('mrp', $isEdit ? $product->mrp : '') }}" :disabled="mode !== 'single'"
                    class="w-full md:w-64 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500"
                    placeholder="0.00">
                <p x-show="thirdSchedule" class="text-[11px] mt-1 text-blue-700 dark:text-blue-300">{{ __('pos.mrp_third_hint') }}</p>
                @error('mrp')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            @if($isEdit)
            {{-- Schedule Type / Tax Status (Task 1276 review) — model-backed field
                 shared with Digital Invoicing; optional here, validated server-side. --}}
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    {{ __('pos.schedule_type_label') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_optional') }}</span>
                </label>
                <select name="schedule_type"
                        class="w-full md:w-72 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="">—</option>
                    <option value="standard" {{ old('schedule_type', $product->schedule_type) === 'standard' ? 'selected' : '' }}>{{ __('pos.sched_opt_standard') }}</option>
                    <option value="reduced" {{ old('schedule_type', $product->schedule_type) === 'reduced' ? 'selected' : '' }}>{{ __('pos.sched_opt_reduced') }}</option>
                    {{-- Only valid together with the fiscal Third Schedule checkbox (server-enforced too) --}}
                    <option value="3rd_schedule" :disabled="!thirdSchedule" {{ old('schedule_type', $product->schedule_type) === '3rd_schedule' ? 'selected' : '' }}>{{ __('pos.sched_opt_third') }}</option>
                    <option value="exempt" {{ old('schedule_type', $product->schedule_type) === 'exempt' ? 'selected' : '' }}>{{ __('pos.sched_opt_exempt') }}</option>
                    <option value="zero_rated" {{ old('schedule_type', $product->schedule_type) === 'zero_rated' ? 'selected' : '' }}>{{ __('pos.sched_opt_zero') }}</option>
                </select>
                <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.schedule_type_hint') }}</p>
            </div>
            @endif

            <div x-show="mode === 'single'" class="p-4 rounded-xl border transition"
                :class="effectiveRate > 0 ? 'bg-blue-50 dark:bg-blue-900/10 border-blue-200 dark:border-blue-800' : 'bg-green-50 dark:bg-green-900/10 border-green-200 dark:border-green-800'">
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.price_col') }}</p>
                        <p class="text-sm font-bold text-gray-800 dark:text-gray-200">PKR <span x-text="formatNum(price)">0.00</span></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.tax_word') }}</p>
                        <p class="text-sm font-bold" :class="effectiveRate > 0 ? 'text-amber-600' : 'text-green-600'" x-text="effectiveRate + '%'">0%</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.total_word') }}</p>
                        <p class="text-sm font-bold text-blue-700 dark:text-blue-400">PKR <span x-text="formatNum(calcTotal())">0.00</span></p>
                    </div>
                </div>
            </div>
        </div>

        @if(!$isEdit && $inventoryAllowed)
        {{-- Supplier (Task 1261): pick existing or quick-add inline. With
             opening stock, the stock is recorded as a received purchase. --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-1">{{ __('pos.fbr_pf_supplier_heading') }}</h3>
            <p class="text-xs text-gray-400 mb-4">{{ __('pos.fbr_pf_supplier_hint') }}</p>
            <div x-show="!supNew" class="flex flex-col sm:flex-row gap-3 sm:items-end">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.stock_supplier_optional_lbl') }}</label>
                    <select name="supplier_id" :disabled="supNew"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        <option value="">{{ __('pos.stock_no_supplier_option') }}</option>
                        @foreach($suppliers as $s)
                        <option value="{{ $s->id }}" {{ $stickySupplierId === (string) $s->id ? 'selected' : '' }}>{{ $s->name }}{{ $s->city ? ' (' . $s->city . ')' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="button" @click="supNew = true"
                        class="shrink-0 px-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition whitespace-nowrap">
                    + {{ __('pos.fbr_pf_quick_add') }}
                </button>
            </div>
            <div x-show="supNew" x-cloak class="grid grid-cols-2 gap-2">
                <input type="text" name="new_supplier_name" value="{{ old('new_supplier_name') }}" maxlength="150" :disabled="!supNew" :required="supNew"
                       placeholder="{{ __('pos.stock_sup_name_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="col-span-2 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                <input type="text" name="new_supplier_phone" value="{{ old('new_supplier_phone') }}" maxlength="30" :disabled="!supNew"
                       placeholder="{{ __('pos.stock_sup_phone_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                <input type="text" name="new_supplier_city" value="{{ old('new_supplier_city') }}" maxlength="80" :disabled="!supNew"
                       placeholder="{{ __('pos.stock_sup_city_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                <div class="col-span-2">
                    <button type="button" @click="supNew = false" class="text-xs font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('pos.stock_sup_cancel') }}</button>
                </div>
            </div>
        </div>
        @endif

        @if($isEdit)
        {{-- ═══ STOCK ADJUSTMENT (Task 1276) — add stock as a purchase or correct
             the quantity via an adjustment movement; never a raw overwrite. ═══ --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4 pb-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('pos.fbr_pf_stock_heading') }}</h3>
                <div class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('pos.fbr_pf_stock_current') }}: <strong class="text-gray-900 dark:text-white">{{ $currentStockQty }} {{ $product->uom ?? 'U' }}</strong>
                    @if($pfBranchName)
                    {{-- Task 1365: stock is per-branch — say WHICH shop this number
                         is for, and where an adjustment saved here will land. --}}
                    <span class="ml-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 text-[11px] font-bold align-middle">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                        {{ $pfBranchName }}
                    </span>
                    @endif
                </div>
            </div>
            @if($pfAllBranches)
            {{-- Task 1365: an adjustment must name ONE shop's shelf. The number
                 above is the company total, so nothing here can be saved from
                 the all-branches view. --}}
            <div class="flex items-start gap-2 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
                <svg class="w-4 h-4 mt-0.5 shrink-0 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                <p class="text-xs font-semibold text-amber-800 dark:text-amber-200">{{ __('pos.stock_edit_pick_branch') }}</p>
            </div>
            @else
            <div class="flex flex-wrap gap-2 mb-4">
                <label class="cursor-pointer px-4 py-2 rounded-lg border-2 text-sm font-bold transition"
                       :class="stockAction === 'none' ? 'border-gray-500 bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-100' : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-gray-300'">
                    <input type="radio" name="stock_action" value="none" x-model="stockAction" class="sr-only">
                    {{ __('pos.fbr_pf_stock_action_none') }}
                </label>
                <label class="cursor-pointer px-4 py-2 rounded-lg border-2 text-sm font-bold transition"
                       :class="stockAction === 'add' ? 'border-green-500 bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-200' : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-gray-300'">
                    <input type="radio" name="stock_action" value="add" x-model="stockAction" class="sr-only">
                    + {{ __('pos.fbr_pf_stock_action_add') }}
                </label>
                <label class="cursor-pointer px-4 py-2 rounded-lg border-2 text-sm font-bold transition"
                       :class="stockAction === 'correct' ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-200' : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-gray-300'">
                    <input type="radio" name="stock_action" value="correct" x-model="stockAction" class="sr-only">
                    {{ __('pos.fbr_pf_stock_action_correct') }}
                </label>
            </div>

            <div x-show="stockAction === 'add'" x-cloak class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.fbr_pf_add_qty_label') }} <span class="text-red-500">*</span></label>
                    <input type="number" step="0.001" min="0.001" name="add_qty" value="{{ old('add_qty') }}" :disabled="stockAction !== 'add'" :required="stockAction === 'add'"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-green-500 focus:border-green-500"
                        placeholder="0">
                </div>
                @if($inventoryAllowed)
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.stock_kharid_rate_ph') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_optional') }}</span></label>
                    <input type="number" step="0.01" min="0" name="add_unit_cost" value="{{ old('add_unit_cost') }}" :disabled="stockAction !== 'add'"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-green-500 focus:border-green-500"
                        placeholder="0.00">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.stock_supplier_optional_lbl') }}</label>
                    <select name="supplier_id" :disabled="stockAction !== 'add'"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-green-500 focus:border-green-500">
                        <option value="">{{ __('pos.stock_no_supplier_option') }}</option>
                        @foreach($suppliers as $s)
                        <option value="{{ $s->id }}" {{ old('supplier_id') == $s->id ? 'selected' : '' }}>{{ $s->name }}{{ $s->city ? ' (' . $s->city . ')' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <p class="md:col-span-3 text-[11px] text-gray-400 -mt-2">{{ __('pos.fbr_pf_stock_add_hint') }}</p>
            </div>

            <div x-show="stockAction === 'correct'" x-cloak class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.fbr_pf_new_qty_label') }} <span class="text-red-500">*</span></label>
                    <input type="number" step="0.001" min="0" name="new_qty" value="{{ old('new_qty') }}" :disabled="stockAction !== 'correct'" :required="stockAction === 'correct'"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-amber-500 focus:border-amber-500"
                        placeholder="{{ $currentStockQty }}">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.fbr_pf_qty_reason_lbl') }} <span class="text-gray-400 text-xs">{{ __('pos.paren_optional') }}</span></label>
                    <input type="text" name="qty_reason" value="{{ old('qty_reason') }}" maxlength="200" :disabled="stockAction !== 'correct'"
                        autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-amber-500 focus:border-amber-500"
                        placeholder="{{ __('pos.fbr_pf_qty_reason_ph') }}">
                </div>
                <p class="md:col-span-3 text-[11px] text-gray-400 -mt-2">{{ __('pos.fbr_pf_stock_correct_hint') }}</p>
            </div>
            @endif
        </div>
        @endif

        <div class="flex flex-col-reverse sm:flex-row justify-end gap-3">
            <a href="{{ route('fbrpos.products') }}" class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-50 dark:hover:bg-gray-800 transition text-center">{{ __('pos.cancel') }}</a>
            @if($isEdit)
            <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition">
                {{ __('pos.update_product') }}
            </button>
            @else
            <button type="submit" @click="$refs.saveAction.value = 'list'"
                    class="px-6 py-2.5 border border-blue-300 dark:border-blue-700 text-blue-700 dark:text-blue-300 rounded-lg font-medium hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                {{ __('pos.fbr_pf_save_list') }}
            </button>
            <button type="submit" @click="$refs.saveAction.value = 'stay'" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition">
                <span x-show="mode === 'single'">{{ __('pos.fbr_pf_save_next') }}</span>
                <span x-show="mode === 'multi'" x-cloak>{{ __('pos.fbr_pf_save_all') }}</span>
            </button>
            @endif
        </div>
    </form>
</div>

<script>
function fbrProductForm() {
    return {
        mode: @js($initMode),
        taxType: @js((string) $initTaxType),
        taxRate: @js((string) $initTaxRate),
        price: @js((string) old('default_price', $product->default_price ?? 0)),
        isPriceEditable: {{ $initPriceEditable ? 'true' : 'false' }},
        thirdSchedule: {{ $initThird ? 'true' : 'false' }},
        supNew: {{ old('new_supplier_name') ? 'true' : 'false' }},
        // Full product edit (Task 1276)
        showOnSale: {{ old('show_on_sale', $isEdit ? (($product->show_on_sale ?? true) ? 1 : 0) : 1) ? 'true' : 'false' }},
        isActiveP: {{ old('is_active', $isEdit ? ($product->is_active ? 1 : 0) : 1) ? 'true' : 'false' }},
        stockAction: @js((string) old('stock_action', 'none')),
        rows: {!! $seedRowsJson !!},
        errRows: {!! $errRowIdxJson !!},
        rowSeq: 0,

        init() {
            this.rows.forEach((r, i) => { r._k = i; });
            this.rowSeq = this.rows.length;
        },

        addRow() {
            this.rows.push({ _k: ++this.rowSeq, name: '', default_price: '', mrp: '', barcode: '', opening_stock: '', unit_cost: '' });
        },

        removeRow(i) {
            this.rows.splice(i, 1);
            // Highlight indexes shift after a removal — drop them rather than
            // flag the wrong rows (the top error list keeps the row numbers).
            this.errRows = [];
        },

        get effectiveRate() {
            if (this.taxType === 'exempt') return 0;
            if (this.taxType === 'taxable') return 18;
            return parseFloat(this.taxRate) || 0;
        },

        calcTotal() {
            let p = parseFloat(this.price) || 0;
            return p + (p * this.effectiveRate / 100);
        },

        formatNum(n) {
            return Number(n).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    };
}
</script>
</x-fbr-pos-layout>
