<x-fbr-pos-layout>
<div class="max-w-6xl mx-auto" x-data="stockPage()">
    @include('fbr-pos.partials.back-link')
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.stock_page_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.stock_page_sub') }}</p>
        </div>
        <div class="flex items-center gap-3">
            {{-- Stock tracking toggle --}}
            <form method="POST" action="{{ route('fbrpos.stock.toggle') }}">
                @csrf
                <input type="hidden" name="enabled" value="{{ $stockEnabled ? 0 : 1 }}">
                <button type="submit"
                        onclick="return confirm(@js($stockEnabled ? __('pos.stock_toggle_off_confirm') : __('pos.stock_toggle_on_confirm')))"
                        class="flex items-center gap-2 px-4 py-2 rounded-xl border-2 text-sm font-bold transition {{ $stockEnabled ? 'bg-green-50 border-green-400 text-green-700 dark:bg-green-900/20 dark:text-green-400' : 'bg-gray-50 border-gray-300 text-gray-500 dark:bg-gray-800 dark:border-gray-600' }}">
                    <span class="w-2.5 h-2.5 rounded-full {{ $stockEnabled ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                    {{ $stockEnabled ? __('pos.stock_tracking_on') : __('pos.stock_tracking_off') }}
                </button>
            </form>
            <a href="{{ route('fbrpos.create') }}" class="text-sm text-blue-600 hover:underline">← {{ __('pos.stock_back_sale_screen') }}</a>
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
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">{{ __('pos.stock_tile_products') }}</p>
            <p class="text-2xl font-extrabold text-gray-900 dark:text-white mt-1">{{ $rows->count() }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border-l-4 {{ $lowStock->count() > 0 ? 'border-amber-500' : 'border-gray-200 dark:border-gray-700' }}">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">{{ __('pos.stock_tile_low') }}</p>
            <p class="text-2xl font-extrabold {{ $lowStock->count() > 0 ? 'text-amber-600' : 'text-gray-900 dark:text-white' }} mt-1">{{ $lowStock->count() }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border-l-4 {{ $negative->count() > 0 ? 'border-red-500' : 'border-gray-200 dark:border-gray-700' }}">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">{{ __('pos.stock_tile_minus') }}</p>
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
                <span class="text-gray-400 text-xs">({{ __('pos.stock_min_prefix') }} {{ rtrim(rtrim(number_format($r->min_stock_level, 3), '0'), '.') }})</span>
            </span>
            @endforeach
        </div>
    </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        {{-- Purchase entry --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5">
            <h3 class="font-bold text-gray-900 dark:text-white mb-3">{{ __('pos.stock_purchase_heading') }}</h3>
            <form method="POST" action="{{ route('fbrpos.stock.purchase') }}" @submit="return purchaseRows.length > 0">
                @csrf
                <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.stock_supplier_optional_lbl') }}</label>
                <select name="supplier_id" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600 mb-3">
                    <option value="">{{ __('pos.stock_no_supplier_option') }}</option>
                    @foreach($suppliers as $s)
                    @if($s->is_active)
                    <option value="{{ $s->id }}">{{ $s->name }}{{ $s->city ? ' (' . $s->city . ')' : '' }}</option>
                    @endif
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
                               :placeholder="@js(__('pos.stock_qty_ph')) + ' (' + row.uom + ')'" class="w-24 border rounded px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        <input type="number" :name="`items[${i}][unit_price]`" x-model="row.unit_price" step="0.01" min="0" required
                               placeholder="{{ __('pos.stock_kharid_rate_ph') }}" class="w-28 border rounded px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        <button type="button" @click="purchaseRows.splice(i, 1)" class="text-red-500 hover:text-red-700 font-bold px-1">&times;</button>
                    </div>
                </template>

                <input type="text" name="notes" maxlength="300" placeholder="{{ __('pos.stock_note_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600 mt-2 mb-3">

                <button type="submit" :disabled="purchaseRows.length === 0"
                        class="w-full py-2.5 rounded-lg bg-blue-600 text-white font-bold hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed">
                    {{ __('pos.stock_receive_btn') }}
                </button>
            </form>
        </div>

        {{-- Suppliers --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5">
            <h3 class="font-bold text-gray-900 dark:text-white mb-3">{{ __('pos.stock_suppliers_heading') }}</h3>
            <form method="POST" action="{{ route('fbrpos.stock.supplier') }}" class="grid grid-cols-2 gap-2 mb-4">
                @csrf
                <input type="text" name="name" required maxlength="150" placeholder="{{ __('pos.stock_sup_name_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="col-span-2 border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                <input type="text" name="phone" maxlength="30" placeholder="{{ __('pos.stock_sup_phone_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                <input type="text" name="city" maxlength="80" placeholder="{{ __('pos.stock_sup_city_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                <button type="submit" class="col-span-2 py-2 rounded-lg bg-gray-800 dark:bg-gray-600 text-white text-sm font-bold hover:bg-gray-900">{{ __('pos.stock_sup_add_btn') }}</button>
            </form>
            @if($suppliers->isEmpty())
                <p class="text-sm text-gray-400 text-center py-4">{{ __('pos.stock_no_suppliers') }}</p>
            @else
            <div class="max-h-64 overflow-y-auto divide-y dark:divide-gray-700">
                @foreach($suppliers as $s)
                <div class="py-2 text-sm" x-data="{ editSup: false }">
                    <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1" x-show="!editSup">
                        <div class="min-w-0 {{ $s->is_active ? '' : 'opacity-60' }}">
                            <p class="font-semibold text-gray-900 dark:text-white truncate">
                                {{ $s->name }}
                                @unless($s->is_active)
                                <span class="ml-1 align-middle text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-gray-200 dark:bg-gray-600 text-gray-600 dark:text-gray-300">{{ __('pos.stock_sup_inactive') }}</span>
                                @endunless
                            </p>
                            <p class="text-xs text-gray-400">{{ $s->phone ?: '' }}{{ $s->city ? ($s->phone ? ' · ' : '') . $s->city : '' }}</p>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            @if($s->is_active)
                            <button type="button" @click="editSup = true"
                                    class="px-2.5 py-1.5 rounded-lg text-xs font-bold text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-gray-700">{{ __('pos.stock_sup_edit') }}</button>
                            @if($s->purchase_orders_count > 0)
                            <form method="POST" action="{{ route('fbrpos.stock.supplier.delete', $s->id) }}"
                                  onsubmit="return confirm(@js(__('pos.stock_sup_deact_confirm')))">
                                @csrf
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-bold text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-gray-700">{{ __('pos.stock_sup_deactivate') }}</button>
                            </form>
                            @else
                            <form method="POST" action="{{ route('fbrpos.stock.supplier.delete', $s->id) }}"
                                  onsubmit="return confirm(@js(__('pos.stock_sup_del_confirm')))">
                                @csrf
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-gray-700">{{ __('pos.stock_sup_delete') }}</button>
                            </form>
                            @endif
                            @else
                            <form method="POST" action="{{ route('fbrpos.stock.supplier.reactivate', $s->id) }}">
                                @csrf
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-bold text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-gray-700">{{ __('pos.stock_sup_reactivate') }}</button>
                            </form>
                            @endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('fbrpos.stock.supplier.update', $s->id) }}" x-show="editSup" x-cloak class="grid grid-cols-2 gap-2 mt-1">
                        @csrf
                        <input type="text" name="name" value="{{ $s->name }}" required maxlength="150" placeholder="{{ __('pos.stock_sup_name_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                               class="col-span-2 border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        <input type="text" name="phone" value="{{ $s->phone }}" maxlength="30" placeholder="{{ __('pos.stock_sup_phone_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                               class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        <input type="text" name="city" value="{{ $s->city }}" maxlength="80" placeholder="{{ __('pos.stock_sup_city_ph') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                               class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        <div class="col-span-2 flex gap-2">
                            <button type="submit" class="flex-1 py-2 rounded-lg bg-blue-600 text-white text-xs font-bold hover:bg-blue-700">{{ __('pos.stock_sup_save') }}</button>
                            <button type="button" @click="editSup = false" class="flex-1 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-xs font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">{{ __('pos.stock_sup_cancel') }}</button>
                        </div>
                    </form>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    {{-- Stock list --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-2 flex-wrap">
            <h3 class="font-bold text-gray-900 dark:text-white">{{ __('pos.stock_list_heading') }}</h3>
            <div class="relative w-full sm:w-64">
                <input type="text" x-model.debounce.200ms="stockFilter" placeholder="{{ __('pos.stock_list_search_ph') }}" autocomplete="off" name="stock_list_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="border rounded-lg px-3 py-1.5 pr-8 text-sm w-full dark:bg-gray-700 dark:text-white dark:border-gray-600">
                <button type="button" x-show="stockFilter !== ''" x-cloak @click="stockFilter = ''; $el.previousElementSibling.value = ''"
                        class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 font-bold px-1 leading-none" aria-label="Clear">&times;</button>
            </div>
        </div>
        <div class="max-h-[480px] overflow-y-auto">
            <table class="w-full text-sm table-cards">
                <thead class="bg-gray-50 dark:bg-gray-700 text-left sticky top-0">
                    <tr>
                        <th class="px-4 py-2">{{ __('pos.stock_col_product') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('pos.stock_col_stock') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('pos.stock_col_min_level') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('pos.stock_col_last_kharid') }}</th>
                        <th class="px-4 py-2 text-right"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                    <tr class="border-t dark:border-gray-700"
                        x-show="stockRowMatch('{{ strtolower(addslashes($r->name . ' ' . ($r->sku ?? '') . ' ' . ($r->barcode ?? ''))) }}')">
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
                        <td class="px-4 py-2 text-right">
                            <span class="inline-flex items-center gap-1">
                                <button type="button" @click="openMov({{ $r->product_id }})"
                                        class="px-2.5 py-1 rounded-lg border border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-300 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700">
                                    {{ __('pos.stock_mov_btn') }}
                                </button>
                                <button type="button" @click="openEdit({{ $r->product_id }})"
                                        class="px-2.5 py-1 rounded-lg border border-blue-200 dark:border-blue-800 text-blue-600 dark:text-blue-400 text-xs font-bold hover:bg-blue-50 dark:hover:bg-blue-900/20">
                                    {{ __('pos.stock_edit_btn') }}
                                </button>
                            </span>
                        </td>
                    </tr>
                    @endforeach
                    <tr x-show="stockNoMatch()" x-cloak>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-400">{{ __('pos.stock_no_match') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Stock item quick-edit modal (Task 416). Plain form POST → redirect
         back with flash, so the row, stat tiles and low-stock alerts all
         refresh from fresh server data after save. --}}
    <div x-show="editOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="editOpen = false">
        <div class="absolute inset-0 bg-black/50" @click="editOpen = false"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
            <form method="POST" action="{{ route('fbrpos.stock.item') }}" @submit="return confirmEditSubmit()">
                @csrf
                <input type="hidden" name="product_id" :value="edit.id">
                <input type="hidden" name="kharid_rate_orig" :value="edit.kharid_orig">
                <input type="hidden" name="quantity_orig" :value="edit.qty_orig">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-2">
                    <div class="min-w-0">
                        <h3 class="font-bold text-gray-900 dark:text-white truncate" x-text="edit.name"></h3>
                        <p class="text-xs text-gray-400" x-text="edit.sku ? 'SKU: ' + edit.sku : ''"></p>
                    </div>
                    <button type="button" @click="editOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-2xl leading-none font-bold px-1">&times;</button>
                </div>
                <div class="p-5 space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.stock_edit_sale_price') }}</label>
                            <input type="number" name="default_price" x-model="edit.price" step="0.01" min="0" required
                                   autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                                   class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.uom_label') }}</label>
                            @php
                                $quickUomList = ['U','PCS','KG','GM','LTR','ML','MTR','SQM','FT','IN','YDS','PKT','DOZ','BOX','CTN','BAG','BTL','TIN','CAN','BUN','ROL','SET'];
                            @endphp
                            <select name="uom" x-model="edit.uom"
                                    class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                                @foreach($quickUomList as $code)
                                    <option value="{{ $code }}">{{ $code }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.stock_edit_kharid') }}</label>
                        <input type="number" name="kharid_rate" x-model="edit.kharid" step="0.01" min="0"
                               autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                               class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">{{ __('pos.stock_edit_kharid_note') }}</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-700/50 p-3">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{ __('pos.stock_edit_current_qty') }}:
                            <strong class="text-gray-900 dark:text-white" x-text="edit.qty_orig + ' ' + edit.uom"></strong></p>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.stock_edit_correct_qty') }}</label>
                        <input type="number" name="new_quantity" x-model="edit.qty" step="0.001"
                               autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                               class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600 mb-2">
                        <input type="text" name="qty_reason" maxlength="200" placeholder="{{ __('pos.stock_edit_qty_reason') }}"
                               autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                               class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        <p class="text-xs text-gray-400 mt-1">{{ __('pos.stock_edit_qty_note') }}</p>
                    </div>
                    <a :href="'{{ url('/fbr-pos/products') }}/' + edit.id + '/edit'" class="inline-block text-xs text-blue-600 dark:text-blue-400 hover:underline">{{ __('pos.stock_edit_full_link') }} →</a>
                </div>
                <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-700 flex gap-2 justify-end">
                    <button type="button" @click="editOpen = false"
                            class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">{{ __('pos.stock_edit_cancel') }}</button>
                    <button type="submit"
                            class="px-5 py-2 rounded-lg bg-blue-600 text-white text-sm font-bold hover:bg-blue-700">{{ __('pos.stock_edit_save') }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Per-product stock movement history modal (Task 425). Read-only audit
         trail: purchases, sales, corrections (adjustment in/out) with the
         reason note, running balance and who did it. --}}
    <div x-show="movOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="movOpen = false">
        <div class="absolute inset-0 bg-black/50" @click="movOpen = false"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-2">
                <div class="min-w-0">
                    <h3 class="font-bold text-gray-900 dark:text-white truncate">{{ __('pos.stock_mov_title') }} — <span x-text="movName"></span></h3>
                </div>
                <button type="button" @click="movOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-2xl leading-none font-bold px-1">&times;</button>
            </div>
            <div class="overflow-y-auto flex-1">
                <p x-show="movLoading && movRows.length === 0" class="text-sm text-gray-400 text-center py-8">{{ __('pos.stock_mov_loading') }}</p>
                <p x-show="!movLoading && movRows.length === 0" x-cloak class="text-sm text-gray-400 text-center py-8 px-4">{{ __('pos.stock_mov_empty') }}</p>
                <table class="w-full text-sm" x-show="movRows.length > 0" x-cloak>
                    <thead class="bg-gray-50 dark:bg-gray-700 text-left sticky top-0">
                        <tr>
                            <th class="px-4 py-2">{{ __('pos.stock_mov_col_date') }}</th>
                            <th class="px-4 py-2">{{ __('pos.stock_mov_col_type') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('pos.stock_mov_col_qty') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('pos.stock_mov_col_balance') }}</th>
                            <th class="px-4 py-2">{{ __('pos.stock_mov_col_note') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="m in movRows" :key="m.id">
                            <tr class="border-t dark:border-gray-700 align-top">
                                <td class="px-4 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">
                                    <span x-text="m.date"></span>
                                    <span class="block text-xs text-gray-400" x-text="m.time"></span>
                                </td>
                                <td class="px-4 py-2">
                                    <span class="inline-block px-2 py-0.5 rounded text-xs font-bold"
                                          :class="m.in ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400' : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400'"
                                          x-text="movTypeLabel(m.type)"></span>
                                    <span class="block text-xs text-gray-400 mt-0.5" x-show="m.ref" x-text="m.ref"></span>
                                </td>
                                <td class="px-4 py-2 text-right font-bold whitespace-nowrap"
                                    :class="m.in ? 'text-green-600' : 'text-red-600'"
                                    x-text="(m.in ? '+' : '\u2212') + m.qty"></td>
                                <td class="px-4 py-2 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap" x-text="m.balance !== null ? m.balance : '\u2014'"></td>
                                <td class="px-4 py-2 text-gray-600 dark:text-gray-300">
                                    <span x-text="m.notes || ''"></span>
                                    <span class="block text-xs text-gray-400" x-show="m.by" x-text="m.by"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-700 text-center" x-show="movHasMore" x-cloak>
                <button type="button" @click="loadMoreMovements()" :disabled="movLoading"
                        class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50">
                    <span x-show="!movLoading">{{ __('pos.stock_mov_load_more') }}</span>
                    <span x-show="movLoading" x-cloak>{{ __('pos.stock_mov_loading') }}</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Recent purchases — Alpine-rendered: server-side search + load-more over the full history --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-bold text-gray-900 dark:text-white">{{ __('pos.stock_recent_purchases') }}</h3>
            <input type="search" x-model="purchQ" @input.debounce.400ms="searchPurchases()"
                   placeholder="{{ __('pos.stock_purch_search_ph') }}" autocomplete="off" name="purch_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                   class="border rounded-lg px-3 py-1.5 text-sm w-full sm:w-72 dark:bg-gray-700 dark:text-white dark:border-gray-600">
        </div>
        <p x-show="purchases.length === 0 && purchQ.trim() === ''" x-cloak class="text-sm text-gray-400 text-center py-6">{{ __('pos.stock_no_purchases') }}</p>
        <p x-show="purchases.length === 0 && purchQ.trim() !== ''" x-cloak class="text-sm text-gray-400 text-center py-6">{{ __('pos.stock_purch_no_results') }}</p>
        <table class="w-full text-sm table-cards" x-show="purchases.length > 0">
            <thead class="bg-gray-50 dark:bg-gray-700 text-left">
                <tr>
                    <th class="px-4 py-2">{{ __('pos.stock_purch_col_number') }}</th>
                    <th class="px-4 py-2">{{ __('pos.stock_purch_col_date') }}</th>
                    <th class="px-4 py-2">{{ __('pos.stock_purch_col_supplier') }}</th>
                    <th class="px-4 py-2">{{ __('pos.stock_purch_items_col') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('pos.stock_purch_col_total') }}</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="po in purchases" :key="po.id">
                    <tr class="border-t dark:border-gray-700 align-top">
                        <td data-label="{{ __('pos.stock_purch_col_number') }}" class="px-4 py-2 font-semibold text-gray-900 dark:text-white" x-text="po.po_number"></td>
                        <td data-label="{{ __('pos.stock_purch_col_date') }}" class="px-4 py-2 text-gray-500" x-text="po.date"></td>
                        <td data-label="{{ __('pos.stock_purch_col_supplier') }}" class="px-4 py-2 text-gray-500" x-text="po.supplier || '—'"></td>
                        <td data-label="{{ __('pos.stock_purch_items_col') }}" class="px-4 py-2 text-gray-600 dark:text-gray-300">
                            <span class="inline-flex flex-wrap gap-x-1 gap-y-0.5 justify-end sm:justify-start">
                                <template x-for="(it, ix) in visiblePurchItems(po)" :key="po.id + '-' + ix">
                                    <span class="whitespace-nowrap"><span x-text="it.name"></span><span class="text-gray-400 font-semibold" x-text="'\u00d7' + it.qty"></span><span x-show="ix < visiblePurchItems(po).length - 1">,</span></span>
                                </template>
                                <button type="button" x-show="po.items.length > 3 && !purchExpanded.includes(po.id)"
                                        @click="purchExpanded.push(po.id)"
                                        class="text-blue-600 dark:text-blue-400 text-xs font-bold hover:underline whitespace-nowrap"
                                        x-text="purchMoreLabel(po.items.length - 3)"></button>
                                <button type="button" x-show="po.items.length > 3 && purchExpanded.includes(po.id)"
                                        @click="purchExpanded = purchExpanded.filter(id => id !== po.id)"
                                        class="text-blue-600 dark:text-blue-400 text-xs font-bold hover:underline whitespace-nowrap">{{ __('pos.stock_purch_less') }}</button>
                            </span>
                        </td>
                        <td data-label="{{ __('pos.stock_purch_col_total') }}" class="px-4 py-2 text-right font-bold text-gray-900 dark:text-white" x-text="'Rs ' + po.total"></td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-700 text-center" x-show="purchHasMore" x-cloak>
            <button type="button" @click="loadMorePurchases()" :disabled="purchLoading"
                    class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50">
                <span x-show="!purchLoading">{{ __('pos.stock_purch_load_more') }}</span>
                <span x-show="purchLoading" x-cloak>{{ __('pos.stock_purch_loading') }}</span>
            </button>
        </div>
    </div>
</div>

<script>
function stockPage() {
    return {
        stockFilter: '',
        prodSearch: '',
        prodResults: [],
        purchaseRows: [],
        editOpen: false,
        edit: { id: 0, name: '', sku: '', uom: 'U', price: '', kharid: '', kharid_orig: '', qty: '', qty_orig: '' },
        // Baked product list for instant client-side search (name/sku/barcode)
        // AND the quick-edit modal prefill (price/qty/kharid per row).
        // NOTE: complex expressions inside the json Blade directive break its
        // paren matcher (nested fn arrows) — the compiled view got truncated.
        // So: build the collection in a php block, UTF-8-safe encode + fallback.
        @php
            $trimQty3 = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
            $bakedStockProducts = $rows->map(fn ($r) => [
                'id' => $r->product_id,
                'name' => $r->name,
                'sku' => $r->sku,
                'barcode' => $r->barcode,
                'uom' => $r->uom,
                'price' => number_format((float) $r->default_price, 2, '.', ''),
                'qty' => $trimQty3($r->quantity),
                'kharid' => $r->last_purchase_price > 0 ? number_format((float) $r->last_purchase_price, 2, '.', '') : '',
            ])->values();
            $bakedStockJson = json_encode($bakedStockProducts, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]';
            $bakedPurchJson = json_encode($recentPurchasesData, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]';
            $purchMoreTpl = json_encode(__('pos.stock_purch_more_n'), JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '"+:n"';
        @endphp
        allProducts: {!! $bakedStockJson !!},
        // ── Recent Purchases: server-side search + load-more ──
        purchases: {!! $bakedPurchJson !!},
        purchHasMore: {{ $purchasesHasMore ? 'true' : 'false' }},
        purchQ: '',
        purchPage: 1,
        purchLoading: false,
        purchSeq: 0,
        purchExpanded: [],
        purchMoreTpl: {!! $purchMoreTpl !!},
        purchMoreLabel(n) { return this.purchMoreTpl.replace(':n', n); },
        visiblePurchItems(po) { return this.purchExpanded.includes(po.id) ? po.items : po.items.slice(0, 3); },
        searchPurchases() { this.fetchPurchases(true); },
        loadMorePurchases() { this.fetchPurchases(false); },
        async fetchPurchases(reset) {
            const seq = ++this.purchSeq;
            this.purchLoading = true;
            const page = reset ? 1 : this.purchPage + 1;
            try {
                const params = new URLSearchParams({ q: this.purchQ.trim(), page: page });
                const res = await fetch(`{{ route('fbrpos.stock.purchases', [], false) }}?` + params.toString(), {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok || seq !== this.purchSeq) return;
                const data = await res.json();
                if (seq !== this.purchSeq) return; // stale response — a newer search superseded it
                if (reset) { this.purchases = data.purchases; this.purchExpanded = []; }
                else { this.purchases = this.purchases.concat(data.purchases); }
                this.purchPage = page;
                this.purchHasMore = data.has_more;
            } catch (e) {
            } finally {
                if (seq === this.purchSeq) this.purchLoading = false;
            }
        },
        searchProducts() {
            const q = this.prodSearch.trim().toLowerCase();
            if (q.length < 1) { this.prodResults = []; return; }
            this.prodResults = this.allProducts.filter(p =>
                (p.name || '').toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q) || (p.barcode || '').toLowerCase().includes(q)
            ).slice(0, 12);
        },
        // ── Stock List filter (name / SKU / barcode) ──
        stockRowMatch(hay) {
            const q = this.stockFilter.trim().toLowerCase();
            return q === '' || hay.includes(q);
        },
        stockNoMatch() {
            const q = this.stockFilter.trim().toLowerCase();
            if (q === '') return false;
            return !this.allProducts.some(p =>
                ((p.name || '') + ' ' + (p.sku || '') + ' ' + (p.barcode || '')).toLowerCase().includes(q)
            );
        },
        // ── Quick-edit modal ──
        openEdit(id) {
            const p = this.allProducts.find(x => x.id === id);
            if (!p) return;
            this.edit = {
                id: p.id, name: p.name, sku: p.sku || '', uom: p.uom || 'U',
                price: p.price, kharid: p.kharid, kharid_orig: p.kharid,
                qty: p.qty, qty_orig: p.qty,
            };
            this.editOpen = true;
        },
        confirmEditSubmit() {
            const from = parseFloat(this.edit.qty_orig || '0') || 0;
            const to = parseFloat(this.edit.qty === '' ? this.edit.qty_orig : this.edit.qty) || 0;
            if (Math.abs(to - from) > 0.0005) {
                return confirm(@js(__('pos.stock_edit_qty_confirm')).replace(':from', from).replace(':to', to));
            }
            return true;
        },
        // ── Per-product movement history modal (Task 425) ──
        movOpen: false,
        movName: '',
        movProductId: 0,
        movRows: [],
        movHasMore: false,
        movPage: 1,
        movLoading: false,
        movSeq: 0,
        movTypeLabels: @php echo json_encode([
            'purchase' => __('pos.stock_mov_type_purchase'),
            'sale' => __('pos.stock_mov_type_sale'),
            'adjustment_in' => __('pos.stock_mov_type_adjustment_in'),
            'adjustment_out' => __('pos.stock_mov_type_adjustment_out'),
            'return_in' => __('pos.stock_mov_type_return_in'),
            'return_out' => __('pos.stock_mov_type_return_out'),
            'transfer_in' => __('pos.stock_mov_type_transfer_in'),
            'transfer_out' => __('pos.stock_mov_type_transfer_out'),
            'opening' => __('pos.stock_mov_type_opening'),
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '{}'; @endphp,
        movTypeLabel(t) { return this.movTypeLabels[t] || t; },
        openMov(id) {
            const p = this.allProducts.find(x => x.id === id);
            this.movProductId = id;
            this.movName = p ? p.name : '';
            this.movRows = [];
            this.movHasMore = false;
            this.movPage = 1;
            this.movOpen = true;
            this.fetchMovements(true);
        },
        loadMoreMovements() { this.fetchMovements(false); },
        async fetchMovements(reset) {
            const seq = ++this.movSeq;
            this.movLoading = true;
            const page = reset ? 1 : this.movPage + 1;
            try {
                const params = new URLSearchParams({ product_id: this.movProductId, page: page });
                const res = await fetch(`{{ route('fbrpos.stock.movements', [], false) }}?` + params.toString(), {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok || seq !== this.movSeq) return;
                const data = await res.json();
                if (seq !== this.movSeq) return; // stale — another product was opened
                if (reset) { this.movRows = data.movements; }
                else { this.movRows = this.movRows.concat(data.movements); }
                this.movPage = page;
                this.movHasMore = data.has_more;
            } catch (e) {
            } finally {
                if (seq === this.movSeq) this.movLoading = false;
            }
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
