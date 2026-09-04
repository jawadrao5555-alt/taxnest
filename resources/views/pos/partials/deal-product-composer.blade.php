{{-- Shared deal product composer. Parent scope provides products. --}}
@php
    $isBlueComposer = ($accent ?? 'emerald') === 'blue';
    $accentSoft = $isBlueComposer
        ? 'border-blue-100 bg-blue-50/60 dark:border-blue-800 dark:bg-blue-900/10'
        : 'border-emerald-100 bg-emerald-50/60 dark:border-emerald-800 dark:bg-emerald-900/10';
    $accentBadge = $isBlueComposer
        ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
        : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300';
    $accentButton = $isBlueComposer
        ? 'bg-blue-600 hover:bg-blue-700 focus:ring-blue-500'
        : 'bg-emerald-600 hover:bg-emerald-700 focus:ring-emerald-500';
    $accentOutline = $isBlueComposer
        ? 'border-blue-200 text-blue-700 hover:bg-blue-50 dark:border-blue-800 dark:text-blue-300 dark:hover:bg-blue-900/20'
        : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-900/20';
    $accentCheck = $isBlueComposer
        ? 'text-blue-600 focus:ring-blue-500'
        : 'text-emerald-600 focus:ring-emerald-500';
@endphp

<section class="mb-5 rounded-xl border border-gray-200 bg-gray-50/70 p-3 dark:border-gray-700 dark:bg-gray-800/30 sm:p-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h4 class="text-xs font-bold text-gray-800 dark:text-gray-100">{{ __('pos.deal_items_included') }}</h4>
            <p class="mt-1 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">{{ __('pos.deal_picker_fixed_help') }}</p>
        </div>
        <button type="button" @click="openProductPicker('fixed')"
                class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-bold transition {{ $accentOutline }}">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span x-text="labels.addProducts"></span>
        </button>
    </div>

    <div class="mt-3 flex items-center justify-between">
        <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('pos.deal_picker_included_products') }}</p>
        <span class="rounded-full px-2.5 py-1 text-[11px] font-bold {{ $accentBadge }}">
            <span x-text="rows.length"></span> {{ __('pos.deal_picker_selected') }}
        </span>
    </div>
    <div class="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-2">
        <template x-for="(row, idx) in rows" :key="row.product_id">
            <div class="flex min-w-0 items-center gap-2 rounded-lg border border-gray-200 bg-white p-2 dark:border-gray-700 dark:bg-gray-900">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-xs font-bold text-gray-800 dark:text-gray-100" x-text="productName(row.product_id)"></p>
                    <p class="truncate text-[10px] text-gray-500 dark:text-gray-400" x-text="productMeta(row.product_id)"></p>
                </div>
                <label class="flex items-center gap-1 text-[10px] font-semibold text-gray-500 dark:text-gray-400">
                    {{ __('pos.quantity_label') }}
                    <input type="number" :name="'items[' + idx + '][quantity]'" x-model.number="row.quantity" min="1" max="999"
                           class="w-16 rounded-md border-gray-300 py-1 text-center text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                </label>
                <input type="hidden" :name="'items[' + idx + '][product_id]'" :value="row.product_id">
                <button type="button" @click="rows.splice(idx, 1)"
                        class="rounded-md p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"
                        title="{{ __('pos.remove_item') }}">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </template>
    </div>
    <p x-show="rows.length === 0" x-cloak class="mt-2 rounded-lg border border-dashed border-gray-300 p-4 text-center text-xs text-gray-500 dark:border-gray-600 dark:text-gray-400">
        {{ __('pos.deal_picker_no_fixed') }}
    </p>
    <p x-show="!canSubmit()" x-cloak class="mt-3 text-xs font-semibold text-amber-700 dark:text-amber-300" x-text="compositionMessage()"></p>
</section>

@if($choiceTableOk ?? false)
<section class="mb-5 border-t border-dashed border-gray-200 pt-4 dark:border-gray-700">
    <div class="mb-3 flex items-start justify-between gap-3">
        <div>
            <h4 class="text-xs font-bold text-gray-800 dark:text-gray-100">{{ __('pos.deal_choice_groups') }}</h4>
            <p class="mt-1 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">{{ __('pos.deal_choice_groups_help') }}</p>
        </div>
        <button type="button" @click="addChoiceGroup()"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-bold transition {{ $accentOutline }}">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            {{ __('pos.add_deal_choice_group') }}
        </button>
    </div>

    <div class="space-y-3">
        <template x-for="(group, groupIdx) in choiceGroups" :key="group.key">
            <article class="rounded-xl border p-3 {{ $accentSoft }}">
                <div class="flex flex-col gap-2 sm:flex-row">
                    <input type="text" :name="'choice_groups[' + groupIdx + '][label]'" x-model="group.label" required maxlength="100"
                           placeholder="{{ __('pos.deal_choice_label_placeholder') }}"
                           class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    <div class="flex items-center gap-2">
                        <label class="flex flex-1 items-center gap-1.5 text-[11px] font-semibold text-gray-600 dark:text-gray-300">
                            {{ __('pos.quantity_label') }}
                            <input type="number" :name="'choice_groups[' + groupIdx + '][quantity]'" x-model.number="group.quantity" required min="1" max="99"
                                   class="w-16 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        </label>
                        <button type="button" @click="duplicateChoiceGroup(groupIdx)"
                                class="rounded-lg p-2 text-gray-500 hover:bg-white/70 dark:text-gray-300 dark:hover:bg-gray-800"
                                title="{{ __('pos.deal_picker_duplicate_group') }}">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V5a2 2 0 012-2h7a2 2 0 012 2v7a2 2 0 01-2 2h-2M5 8h7a2 2 0 012 2v7a2 2 0 01-2 2H5a2 2 0 01-2-2v-7a2 2 0 012-2z"/></svg>
                        </button>
                        <button type="button" @click="choiceGroups.splice(groupIdx, 1)"
                                class="rounded-lg p-2 text-red-500 hover:bg-red-100 dark:hover:bg-red-900/30"
                                title="{{ __('pos.remove_item') }}">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                    <template x-for="product in selectedProducts(group.product_ids).slice(0, 8)" :key="product.id">
                        <span class="inline-flex max-w-full items-center gap-1 rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-gray-700 shadow-sm dark:bg-gray-800 dark:text-gray-200">
                            <span class="max-w-44 truncate" x-text="product.name"></span>
                            <button type="button" @click="removeChoiceOption(group, product.id)" class="text-gray-400 hover:text-red-500" title="{{ __('pos.remove_item') }}">×</button>
                        </span>
                    </template>
                    <span x-show="group.product_ids.length > 8" class="text-[11px] font-bold text-gray-500 dark:text-gray-400"
                          x-text="labels.more.replace(':count', group.product_ids.length - 8)"></span>
                </div>

                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-[11px] font-bold text-gray-600 dark:text-gray-300">
                            <span x-text="group.product_ids.length"></span> {{ __('pos.deal_picker_options_selected') }}
                        </p>
                        <p x-show="group.product_ids.length === 0" x-cloak class="mt-0.5 text-[11px] font-semibold text-red-600 dark:text-red-400">
                            {{ __('pos.deal_picker_group_needs_options') }}
                        </p>
                    </div>
                    <button type="button" @click="openProductPicker('choice', groupIdx)"
                            class="rounded-lg px-3 py-2 text-xs font-bold text-white transition focus:outline-none focus:ring-2 focus:ring-offset-2 {{ $accentButton }}">
                        <span x-text="group.product_ids.length ? labels.editOptions : labels.chooseOptions"></span>
                    </button>
                </div>

                <template x-for="id in group.product_ids" :key="id">
                    <input type="hidden" :name="'choice_groups[' + groupIdx + '][product_ids][]'" :value="id">
                </template>
            </article>
        </template>
    </div>
</section>
@endif

<template x-if="picker.open">
    <div class="fixed inset-0 z-[150] flex items-end justify-center bg-gray-950/65 p-0 backdrop-blur-sm sm:items-center sm:p-5"
         @click.self="closeProductPicker()"
         @keydown.escape.window="closeProductPicker()">
        <div class="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl dark:bg-gray-900 sm:rounded-2xl">
            <header class="flex items-start justify-between gap-4 border-b border-gray-100 px-4 py-4 dark:border-gray-800 sm:px-6">
                <div>
                    <h3 class="text-base font-extrabold text-gray-900 dark:text-white" x-text="pickerTitle()"></h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="picker.draft.length"></span> {{ __('pos.deal_picker_selected') }}
                    </p>
                </div>
                <button type="button" @click="closeProductPicker()"
                        class="flex h-9 w-9 items-center justify-center rounded-full text-xl text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                        aria-label="{{ __('pos.close') }}">×</button>
            </header>

            <div class="border-b border-gray-100 p-3 dark:border-gray-800 sm:px-6">
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35m2.35-5.65a8 8 0 11-16 0 8 8 0 0116 0z"/></svg>
                    <input x-model.debounce.150ms="picker.search" @input="picker.limit = picker.step" x-ref="dealPickerSearch" type="search"
                           placeholder="{{ __('pos.deal_picker_search') }}"
                           class="w-full rounded-xl border-gray-300 bg-gray-50 py-2.5 pl-10 pr-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-3 sm:px-6">
                <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                    <template x-for="product in pickerResults()" :key="product.id">
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 p-2.5 transition hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                            <input type="checkbox" :value="String(product.id)" x-model="picker.draft"
                                   class="h-4 w-4 rounded border-gray-300 {{ $accentCheck }}">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-bold text-gray-800 dark:text-gray-100" x-text="product.name"></span>
                                <span class="block truncate text-[11px] text-gray-500 dark:text-gray-400" x-text="productMeta(product.id)"></span>
                            </span>
                        </label>
                    </template>
                </div>
                <p x-show="pickerResults().length === 0" class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ __('pos.deal_picker_no_matches') }}
                </p>
                <div x-show="matchingProductCount() > pickerResults().length" class="pt-3 text-center">
                    <p class="mb-2 text-[11px] text-gray-500 dark:text-gray-400"
                       x-text="labels.showing.replace(':shown', pickerResults().length).replace(':total', matchingProductCount())"></p>
                    <button type="button" @click="loadMoreProducts()"
                            class="rounded-lg border px-3 py-2 text-xs font-bold transition {{ $accentOutline }}">
                        {{ __('pos.deal_picker_load_more') }}
                    </button>
                </div>
            </div>

            <footer class="flex items-center gap-2 border-t border-gray-100 p-3 dark:border-gray-800 sm:px-6 sm:py-4">
                <button type="button" @click="picker.draft = []"
                        class="mr-auto rounded-lg px-3 py-2 text-xs font-bold text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                    {{ __('pos.deal_picker_clear') }}
                </button>
                <button type="button" @click="closeProductPicker()"
                        class="rounded-lg border border-gray-300 px-4 py-2.5 text-xs font-bold text-gray-600 dark:border-gray-600 dark:text-gray-300">
                    {{ __('pos.cancel_esc') }}
                </button>
                <button type="button" @click="applyProductPicker()"
                        class="rounded-lg px-4 py-2.5 text-xs font-bold text-white transition focus:outline-none focus:ring-2 focus:ring-offset-2 {{ $accentButton }}">
                    {{ __('pos.deal_picker_apply') }}
                </button>
            </footer>
        </div>
    </div>
</template>

@once
<script>
/* Deliberately a plain global factory, not Alpine.data() registered on
 * alpine:init. This partial can be rendered after Alpine has initialized
 * (navigation/cache restores), when an init listener would never run and the
 * Add products button would have no component scope. */
window.dealComposer = function (initialRows, initialGroups) {
        return {
            rows: (initialRows || []).map(function (row) {
                return { product_id: String(row.product_id), quantity: Number(row.quantity) || 1 };
            }),
            choiceGroups: (initialGroups || []).map(function (group) {
                return {
                    key: 'existing-' + Math.random().toString(36).slice(2),
                    label: group.label || '',
                    quantity: Number(group.quantity) || 1,
                    product_ids: (group.product_ids || []).map(String)
                };
            }),
            dealType: 'regular',
            // Search always examines the full local catalogue. Rendering starts
            // small so a broad search does not create a huge DOM; Load More
            // expands only the visible slice inside this bounded-scroll modal.
            picker: { open: false, mode: 'fixed', groupIndex: null, search: '', draft: [], limit: 60, step: 60 },
            labels: {
                addProducts: @js(__('pos.deal_picker_add_products')),
                editProducts: @js(__('pos.deal_picker_edit_products')),
                chooseOptions: @js(__('pos.deal_picker_choose_options')),
                editOptions: @js(__('pos.deal_picker_edit_options')),
                fixedTitle: @js(__('pos.deal_items_included')),
                choiceTitle: @js(__('pos.deal_choice_groups')),
                more: @js(__('pos.deal_picker_more', ['count' => ':count'])),
                showing: @js(__('pos.deal_picker_showing', ['shown' => ':shown', 'total' => ':total']))
            },
            normalizedSearch(product) {
                return [product.name, product.sku, product.barcode, product.category, product.price]
                    .filter(function (value) { return value !== null && value !== undefined && value !== ''; })
                    .join(' ')
                    .toLowerCase();
            },
            matchingProducts() {
                const query = String(this.picker.search || '').trim().toLowerCase();
                return (this.products || []).filter((product) => !query || this.normalizedSearch(product).includes(query));
            },
            matchingProductCount() {
                return this.matchingProducts().length;
            },
            pickerResults() {
                return this.matchingProducts().slice(0, this.picker.limit);
            },
            addChoiceGroup() {
                this.choiceGroups.push({
                    key: 'new-' + Date.now() + '-' + Math.random().toString(36).slice(2),
                    label: '',
                    quantity: 1,
                    product_ids: []
                });
            },
            duplicateChoiceGroup(index) {
                const source = this.choiceGroups[index];
                if (!source) return;
                this.choiceGroups.splice(index + 1, 0, {
                    key: 'copy-' + Date.now() + '-' + Math.random().toString(36).slice(2),
                    label: source.label,
                    quantity: Number(source.quantity) || 1,
                    product_ids: (source.product_ids || []).map(String)
                });
            },
            openProductPicker(mode, groupIndex) {
                this.picker.mode = mode;
                this.picker.groupIndex = mode === 'choice' ? Number(groupIndex) : null;
                this.picker.search = '';
                this.picker.limit = this.picker.step;
                this.picker.draft = mode === 'fixed'
                    ? this.rows.map(function (row) { return String(row.product_id); })
                    : ((this.choiceGroups[groupIndex] || {}).product_ids || []).map(String);
                this.picker.open = true;
                this.$nextTick(() => this.$refs.dealPickerSearch && this.$refs.dealPickerSearch.focus());
            },
            loadMoreProducts() {
                this.picker.limit += this.picker.step;
            },
            closeProductPicker() {
                this.picker.open = false;
            },
            applyProductPicker() {
                const selected = Array.from(new Set(this.picker.draft.map(String)));
                if (this.picker.mode === 'fixed') {
                    const quantities = Object.fromEntries(this.rows.map(function (row) {
                        return [String(row.product_id), Number(row.quantity) || 1];
                    }));
                    this.rows = selected.map(function (id) {
                        return { product_id: id, quantity: quantities[id] || 1 };
                    });
                } else if (this.choiceGroups[this.picker.groupIndex]) {
                    this.choiceGroups[this.picker.groupIndex].product_ids = selected;
                }
                this.closeProductPicker();
            },
            removeChoiceOption(group, productId) {
                group.product_ids = (group.product_ids || []).filter(function (id) {
                    return String(id) !== String(productId);
                });
            },
            findProduct(id) {
                return (this.products || []).find(function (product) {
                    return String(product.id) === String(id);
                });
            },
            selectedProducts(ids) {
                const selected = new Set((ids || []).map(String));
                return (this.products || []).filter(function (product) {
                    return selected.has(String(product.id));
                });
            },
            productName(id) {
                const found = this.findProduct(id);
                return found ? found.name : 'Product #' + id;
            },
            productMeta(id) {
                const found = this.findProduct(id);
                if (!found) return '';
                const identity = found.sku || found.barcode || found.category || '';
                return (identity ? identity + ' · ' : '') + 'Rs. ' + this.formatPrice(found.price);
            },
            pickerTitle() {
                if (this.picker.mode === 'fixed') return this.labels.fixedTitle;
                const group = this.choiceGroups[this.picker.groupIndex];
                return group && group.label ? group.label : this.labels.choiceTitle;
            },
            formatPrice(price) {
                return Number(price || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
            },
            canSubmit() {
                const groupsComplete = this.choiceGroups.every(function (group) {
                    return String(group.label || '').trim() !== '' && (group.product_ids || []).length > 0;
                });
                return groupsComplete && (this.rows.length > 0 || this.choiceGroups.length > 0);
            },
            compositionMessage() {
                if (!this.rows.length && !this.choiceGroups.length) {
                    return @js(__('pos.deal_picker_needs_composition'));
                }
                const incomplete = this.choiceGroups.find(function (group) {
                    return String(group.label || '').trim() === '' || !(group.product_ids || []).length;
                });
                return incomplete
                    ? @js(__('pos.deal_picker_complete_groups'))
                    : @js(__('pos.deal_picker_needs_composition'));
            }
        };
};
</script>
@endonce