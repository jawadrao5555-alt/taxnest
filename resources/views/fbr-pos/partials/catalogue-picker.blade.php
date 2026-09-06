{{--
    💊 "Add from medicine catalogue" (Task 1579) — pharmacy-mode products page.

    Type a brand or salt → catalogue hits (brand · composition · manufacturer ·
    pack · MRP) → tick → one POST creates linked company products at MRP.
    Already-linked rows show "already added" instead of a checkbox.

    Included INSIDE the page's x-data (fbrProductBulk) so it sits in a nested
    x-data of its own; no window.TXT (i18n baker ignores includes), every
    string is baked via @js(__()) right here.

    Expects: $phCatalogueSearchUrl, $phCatalogueAddUrl.
--}}
<div x-data="fbrCataloguePicker()" class="mb-6 bg-white dark:bg-gray-900 rounded-2xl border border-emerald-200 dark:border-emerald-900/50 shadow-sm overflow-hidden" id="catalogue-picker">
    <div class="px-4 py-3 bg-emerald-50 dark:bg-emerald-900/20 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 class="text-sm font-bold text-emerald-900 dark:text-emerald-200 flex items-center gap-1.5">
                💊 {{ __('pos.ph_cat_title') }} <x-new-badge feature="fbr_pharmacy_catalogue" />
            </h2>
            <p class="text-xs text-emerald-700 dark:text-emerald-300/80">{{ __('pos.ph_cat_sub') }}</p>
        </div>
        <button type="button" @click="open = !open" class="text-xs font-semibold text-emerald-800 dark:text-emerald-200 underline" x-text="open ? @js(__('pos.ph_cat_hide')) : @js(__('pos.ph_cat_show'))"></button>
    </div>

    <div x-show="open">
        <div class="p-4">
            <div class="flex flex-col sm:flex-row gap-2">
                <div class="relative flex-1">
                    <input type="search" x-model="q" @input.debounce.350ms="search()" @keydown.enter.prevent="search(true)"
                           placeholder="{{ __('pos.ph_cat_search_ph') }}" autocomplete="off"
                           class="w-full rounded-lg bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-700 dark:text-white shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-sm pr-9">
                    <svg x-show="loading" class="absolute right-2.5 top-2.5 w-4 h-4 animate-spin text-emerald-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                </div>
                <button type="button" @click="search(true)" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700">{{ __('pos.search_word') }}</button>
            </div>

            <div class="mt-2 flex flex-wrap gap-1.5">
                <template x-for="s in samples" :key="s">
                    <button type="button" @click="q = s; search(true)" class="px-2 py-0.5 rounded-full text-[11px] bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-emerald-100" x-text="s"></button>
                </template>
            </div>

            <p x-show="error" class="mt-3 text-sm text-red-600" x-text="error"></p>
            <p x-show="done" class="mt-3 text-sm text-emerald-700 dark:text-emerald-300 font-semibold" x-text="done"></p>

            <template x-if="searched && !loading && items.length === 0 && !error">
                <p class="mt-4 text-sm text-gray-500">{{ __('pos.ph_cat_no_hits') }}</p>
            </template>

            <div x-show="items.length > 0" class="mt-4">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <label class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                        <input type="checkbox" class="rounded border-gray-300 text-emerald-600" :checked="allPickableChecked" @change="toggleAll($event.target.checked)">
                        {{ __('pos.ph_cat_select_all_hits') }}
                    </label>
                    <span class="text-xs text-gray-500" x-text="items.length + ' ' + @js(__('pos.ph_cat_hits_word')) + (items.length >= limit ? ' ' + @js(__('pos.ph_cat_refine')) : '')"></span>
                </div>
                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-800">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800 text-[11px] uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2 w-8"></th>
                                <th class="px-3 py-2 text-left">{{ __('pos.ph_cat_col_brand') }}</th>
                                <th class="px-3 py-2 text-left">{{ __('pos.ph_cat_col_composition') }}</th>
                                <th class="px-3 py-2 text-left">{{ __('pos.ph_cat_col_maker') }}</th>
                                <th class="px-3 py-2 text-left">{{ __('pos.ph_cat_col_pack') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('pos.ph_cat_col_mrp') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <template x-for="it in items" :key="it.id">
                                <tr :class="it.linked_product_id ? 'bg-gray-50/60 dark:bg-gray-800/40' : (picked.includes(it.id) ? 'bg-emerald-50/60 dark:bg-emerald-900/10' : '')" class="cursor-pointer" @click="toggle(it)">
                                    <td class="px-3 py-2 align-top">
                                        <template x-if="!it.linked_product_id">
                                            <input type="checkbox" class="rounded border-gray-300 text-emerald-600" :checked="picked.includes(it.id)" @click.stop @change="toggle(it)">
                                        </template>
                                        <template x-if="it.linked_product_id">
                                            <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        </template>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <div class="font-semibold text-gray-900 dark:text-white" x-text="it.brand_name"></div>
                                        <div class="text-[11px] text-gray-400 flex flex-wrap gap-x-2">
                                            <span x-show="it.drap_reg_no" x-text="'DRAP ' + it.drap_reg_no"></span>
                                            <span x-show="it.category !== 'normal'" class="text-emerald-600 dark:text-emerald-400" x-text="it.category_label"></span>
                                            <template x-if="it.linked_product_id">
                                                <a :href="editBase.replace('/0/', '/' + it.linked_product_id + '/')" @click.stop class="text-blue-600 underline">{{ __('pos.ph_cat_already_added') }}</a>
                                            </template>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 align-top text-gray-700 dark:text-gray-300 max-w-xs">
                                        <div class="truncate" :title="it.composition" x-text="it.composition"></div>
                                        <div class="text-[11px] text-gray-400" x-text="[it.strength, it.dosage_form].filter(Boolean).join(' · ')"></div>
                                    </td>
                                    <td class="px-3 py-2 align-top text-xs text-gray-600 dark:text-gray-300" x-text="it.manufacturer"></td>
                                    <td class="px-3 py-2 align-top text-xs text-gray-600 dark:text-gray-300 whitespace-nowrap" x-text="it.pack_size"></td>
                                    <td class="px-3 py-2 align-top text-right font-bold text-gray-900 dark:text-white whitespace-nowrap" x-text="it.mrp === null ? '—' : 'Rs ' + Number(it.mrp).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-gray-500">{{ __('pos.ph_cat_defaults_note') }}</p>
                    <button type="button" @click="addPicked()" :disabled="picked.length === 0 || adding"
                            class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-bold hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg x-show="adding" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        <span x-text="@js(__('pos.ph_cat_add_btn')) + (picked.length ? ' (' + picked.length + ')' : '')"></span>
                    </button>
                </div>
            </div>
        </div>
        <p class="px-4 pb-3 text-[11px] text-gray-400">{{ __('pos.ph_cat_source_note') }}</p>
    </div>
</div>

<script>
    function fbrCataloguePicker() {
        return {
            open: true,
            q: '',
            items: [],
            picked: [],
            loading: false,
            adding: false,
            searched: false,
            error: '',
            done: '',
            limit: {{ (int) \App\Http\Controllers\FbrPosCatalogueController::SEARCH_LIMIT }},
            samples: ['Panadol', 'Augmentin', 'Brufen', 'Amoxicillin', 'Paracetamol'],
            searchUrl: @js($phCatalogueSearchUrl),
            addUrl: @js($phCatalogueAddUrl),
            editBase: @js(route('fbrpos.products.edit', ['id' => 0], false)),
            csrf: @js(csrf_token()),
            get pickable() { return this.items.filter(i => !i.linked_product_id); },
            get allPickableChecked() { return this.pickable.length > 0 && this.pickable.every(i => this.picked.includes(i.id)); },
            async search(force) {
                const q = this.q.trim();
                this.error = ''; this.done = '';
                if (q.length < 2) { this.items = []; this.searched = false; return; }
                this.loading = true;
                try {
                    const r = await fetch(this.searchUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                    const j = await r.json().catch(() => null);
                    if (!r.ok || !j || j.success === false) {
                        this.error = (j && j.message) ? j.message : @js(__('pos.ph_cat_err_generic'));
                        this.items = [];
                    } else {
                        this.items = j.items || [];
                        const ids = this.items.map(i => i.id);
                        this.picked = this.picked.filter(id => ids.includes(id));
                    }
                    this.searched = true;
                } catch (e) {
                    this.error = @js(__('pos.ph_cat_err_network'));
                } finally {
                    this.loading = false;
                }
            },
            toggle(it) {
                if (it.linked_product_id) return;
                const i = this.picked.indexOf(it.id);
                if (i >= 0) this.picked.splice(i, 1); else this.picked.push(it.id);
            },
            toggleAll(on) {
                this.picked = on ? this.pickable.map(i => i.id) : [];
            },
            async addPicked() {
                if (!this.picked.length || this.adding) return;
                this.adding = true; this.error = ''; this.done = '';
                try {
                    const r = await fetch(this.addUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                        credentials: 'same-origin',
                        body: JSON.stringify({ ids: this.picked }),
                    });
                    const j = await r.json().catch(() => null);
                    if (!r.ok || !j || j.success === false) {
                        this.error = (j && j.message) ? j.message : (r.status === 419 ? @js(__('pos.ph_cat_err_session')) : @js(__('pos.ph_cat_err_generic')));
                        return;
                    }
                    const createdIds = {};
                    (j.created || []).forEach(c => { createdIds[c.id] = c; });
                    (j.skipped || []).forEach(s => { if (s.reason === 'already' && s.product_id) createdIds[s.id] = s; });
                    this.items = this.items.map(it => createdIds[it.id] ? Object.assign({}, it, { linked_product_id: createdIds[it.id].product_id, linked_product_name: createdIds[it.id].name }) : it);
                    this.picked = [];
                    this.done = j.message || '';
                    if ((j.created || []).length > 0) {
                        // Products list below is server-rendered — reload once so the new rows show.
                        setTimeout(() => { window.location.href = window.location.pathname + '?search=' + encodeURIComponent(this.q.trim()) + '#catalogue-picker'; }, 900);
                    }
                } catch (e) {
                    this.error = @js(__('pos.ph_cat_err_network'));
                } finally {
                    this.adding = false;
                }
            }
        };
    }
</script>
