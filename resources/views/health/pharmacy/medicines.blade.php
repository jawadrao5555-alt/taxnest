@php use App\Models\HealthMedicine; @endphp
{{--
    Medicine catalogue.

    A medicine here owns a shared platform `products` row underneath, which is
    what lets purchasing and inventory be reused instead of re-invented. The
    healthcare attributes on this screen — strength, form, manufacturer, pack
    conversion, substitutes and the sale restrictions — are the layer on top.
--}}
<x-health-layout>
    @php
        $medicineText = function ($value) {
            return mb_convert_encoding((string) ($value ?? ''), 'UTF-8', 'UTF-8');
        };
        $blank = [
            'id' => null, 'name' => '', 'generic_name' => '', 'strength' => '', 'form' => 'tablet',
            'manufacturer' => '', 'category' => '', 'code' => '', 'barcode' => '',
            'unit_uom' => 'tablet', 'pack_uom' => '', 'pack_size' => '', 'purchase_price' => '',
            'sale_price' => '', 'tax_rate' => '', 'hs_code' => '', 'uom_code' => '',
            'reorder_level' => '', 'max_level' => '', 'default_dosage' => '', 'notes' => '',
            'requires_prescription' => false, 'is_controlled' => false, 'is_narcotic' => false,
            'is_refrigerated' => false, 'substitutes' => [],
        ];
    @endphp

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{
            form: false,
            values: {{ \Illuminate\Support\Js::from($blank) }},
            blank: {{ \Illuminate\Support\Js::from($blank) }},
            openNew() { this.values = JSON.parse(JSON.stringify(this.blank)); this.form = true; window.scrollTo({ top: 0, behavior: 'smooth' }); },
            openEdit(payload) { this.values = Object.assign(JSON.parse(JSON.stringify(this.blank)), payload); this.form = true; window.scrollTo({ top: 0, behavior: 'smooth' }); },
            isSubstitute(id) { return (this.values.substitutes || []).includes(id); }
         }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ph_medicines_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.ph_medicines_subtitle') }}</p>
            </div>
            @if($canManage)
                <button type="button" @click="openNew()"
                        class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                    {{ __('health.ph_medicine_add') }}
                </button>
            @endif
        </div>

        {{-- ── Search ── --}}
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('health.ph_search_medicine') }}"
                   class="flex-1 min-w-[200px] rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
            <select name="status" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                <option value="active" @selected($status === 'active')>{{ __('health.ph_status_active') }}</option>
                <option value="inactive" @selected($status === 'inactive')>{{ __('health.ph_status_inactive') }}</option>
            </select>
            <button type="submit" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                {{ __('health.ph_search') }}
            </button>
        </form>

        {{-- ── Create / edit ── --}}
        @if($canManage)
            <div x-show="form" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST"
                      :action="values.id ? '{{ url('/health/pharmacy/medicines') }}/' + values.id : '{{ url('/health/pharmacy/medicines') }}'"
                      class="space-y-4">
                    @csrf
                    <template x-if="values.id"><input type="hidden" name="_method" value="PUT"></template>

                    <h2 class="text-base font-black" x-text="values.id ? '{{ __('health.ph_medicine_edit') }}' : '{{ __('health.ph_medicine_add') }}'"></h2>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div class="sm:col-span-2 lg:col-span-1">
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_name') }}</label>
                            <input type="text" name="name" x-model="values.name" required maxlength="190"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_generic') }}</label>
                            <input type="text" name="generic_name" x-model="values.generic_name" maxlength="190"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_strength') }}</label>
                            <input type="text" name="strength" x-model="values.strength" maxlength="64" placeholder="500mg"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_form') }}</label>
                            <select name="form" x-model="values.form"
                                    class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                @foreach($forms as $form)
                                    <option value="{{ $form }}">{{ __('health.form_' . $form) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_manufacturer') }}</label>
                            <input type="text" name="manufacturer" x-model="values.manufacturer" maxlength="190"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_category') }}</label>
                            <input type="text" name="category" x-model="values.category" maxlength="120"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_code') }}</label>
                            <input type="text" name="code" x-model="values.code" maxlength="64"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_barcode') }}</label>
                            <input type="text" name="barcode" x-model="values.barcode" maxlength="64"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>

                        {{-- Pack conversion: what a patient is given vs what the
                             supplier delivers. Stock is always counted in the
                             selling unit, so the pack size is documentation for
                             the person receiving the delivery. --}}
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_unit_uom') }}</label>
                            <input type="text" name="unit_uom" x-model="values.unit_uom" maxlength="24"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_pack_uom') }}</label>
                            <input type="text" name="pack_uom" x-model="values.pack_uom" maxlength="24" placeholder="box"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_pack_size') }}</label>
                            <input type="number" step="0.001" min="0.001" name="pack_size" x-model="values.pack_size"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_purchase_price') }}</label>
                            <input type="number" step="0.01" min="0" name="purchase_price" x-model="values.purchase_price"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_sale_price') }}</label>
                            <input type="number" step="0.01" min="0" name="sale_price" x-model="values.sale_price"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_tax_rate') }}</label>
                            <input type="number" step="0.01" min="0" max="100" name="tax_rate" x-model="values.tax_rate"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_reorder') }}</label>
                            <input type="number" step="0.001" min="0" name="reorder_level" x-model="values.reorder_level"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_max_level') }}</label>
                            <input type="number" step="0.001" min="0" name="max_level" x-model="values.max_level"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_dosage') }}</label>
                            <input type="text" name="default_dosage" x-model="values.default_dosage" maxlength="190" placeholder="1 x 3"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_hs_code') }}</label>
                            <input type="text" name="hs_code" x-model="values.hs_code" maxlength="32"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_uom_code') }}</label>
                            <input type="text" name="uom_code" x-model="values.uom_code" maxlength="32"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_notes') }}</label>
                            <input type="text" name="notes" x-model="values.notes" maxlength="500"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                    </div>

                    {{-- Sale restrictions. `requires_prescription` is not a label:
                         with the policy switch on, the counter refuses to sell it
                         without one. --}}
                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-2">
                        @foreach([
                            ['requires_prescription', 'health.ph_f_requires_rx'],
                            ['is_controlled', 'health.ph_f_controlled'],
                            ['is_narcotic', 'health.ph_f_narcotic'],
                            ['is_refrigerated', 'health.ph_f_refrigerated'],
                        ] as [$field, $label])
                            <label class="flex items-center gap-2 p-2.5 rounded-xl border border-gray-200 dark:border-gray-700 cursor-pointer text-sm font-semibold">
                                <input type="checkbox" name="{{ $field }}" value="1" x-model="values.{{ $field }}"
                                       class="rounded border-gray-300 dark:border-gray-600 text-teal-600 focus:ring-teal-500">
                                {{ __($label) }}
                            </label>
                        @endforeach
                    </div>

                    {{-- Substitutes are stored both ways, so picking A→B also makes
                         A show up when the pharmacist is short of B. --}}
                    @if($allMedicines->isNotEmpty())
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_substitutes') }}</label>
                            <div class="max-h-40 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-700 p-2 grid sm:grid-cols-2 lg:grid-cols-3 gap-1">
                                @foreach($allMedicines as $option)
                                    <label class="flex items-center gap-2 px-2 py-1 rounded-lg text-xs font-semibold hover:bg-gray-50 dark:hover:bg-gray-700/40 cursor-pointer"
                                           x-show="values.id !== {{ (int) $option->id }}">
                                        <input type="checkbox" name="substitutes[]" value="{{ $option->id }}"
                                               :checked="isSubstitute({{ (int) $option->id }})"
                                               class="rounded border-gray-300 dark:border-gray-600 text-teal-600 focus:ring-teal-500">
                                        <span class="truncate">{{ $option->name }} {{ $option->strength }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <input type="hidden" name="is_active" value="1">

                    <div class="flex items-center gap-2">
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                            {{ __('health.ph_save') }}
                        </button>
                        <button type="button" @click="form = false" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">
                            {{ __('health.cancel') }}
                        </button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ── List ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($medicines->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.ph_medicines_none') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($medicines as $medicine)
                        @php
                            // Built above the attribute on purpose: a multi-line
                            // payload inside a Blade directive attribute breaks
                            // bracket matching, and one malformed medicine name
                            // must not kill the whole Alpine component.
                            try {
                                $payload = \Illuminate\Support\Js::from([
                                    'id' => (int) $medicine->id,
                                    'name' => $medicineText($medicine->name),
                                    'generic_name' => $medicineText($medicine->generic_name),
                                    'strength' => $medicineText($medicine->strength),
                                    'form' => $medicineText($medicine->form ?: 'tablet'),
                                    'manufacturer' => $medicineText($medicine->manufacturer),
                                    'category' => $medicineText($medicine->category),
                                    'code' => $medicineText($medicine->code),
                                    'barcode' => $medicineText($medicine->barcode),
                                    'unit_uom' => $medicineText($medicine->unit_uom),
                                    'pack_uom' => $medicineText($medicine->pack_uom),
                                    'pack_size' => $medicine->pack_size !== null ? (float) $medicine->pack_size : '',
                                    'purchase_price' => (float) $medicine->purchase_price,
                                    'sale_price' => (float) $medicine->sale_price,
                                    'tax_rate' => $medicine->tax_rate !== null ? (float) $medicine->tax_rate : '',
                                    'hs_code' => $medicineText($medicine->hs_code),
                                    'uom_code' => $medicineText($medicine->uom_code),
                                    'reorder_level' => (float) $medicine->reorder_level,
                                    'max_level' => (float) $medicine->max_level,
                                    'default_dosage' => $medicineText($medicine->default_dosage),
                                    'notes' => $medicineText($medicine->notes),
                                    'requires_prescription' => (bool) $medicine->requires_prescription,
                                    'is_controlled' => (bool) $medicine->is_controlled,
                                    'is_narcotic' => (bool) $medicine->is_narcotic,
                                    'is_refrigerated' => (bool) $medicine->is_refrigerated,
                                    'substitutes' => ($substituteMap[$medicine->id] ?? collect())->pluck('substitute_id')->map(fn ($v) => (int) $v)->values()->all(),
                                ]);
                            } catch (\Throwable $e) {
                                $payload = \Illuminate\Support\Js::from(['id' => (int) $medicine->id]);
                            }
                            $qty = (float) ($available[$medicine->id] ?? 0);
                            $level = (float) $medicine->reorder_level;
                        @endphp
                        <div class="px-5 py-4 flex flex-wrap items-center gap-3">
                            <div class="flex-1 min-w-[220px]">
                                <p class="text-sm font-black">
                                    {{ $medicine->display_name }}
                                    @if($medicine->requires_prescription)
                                        <span class="ms-1.5 text-[10px] font-black px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200 uppercase">{{ __('health.ph_badge_rx') }}</span>
                                    @endif
                                    @if($medicine->is_controlled || $medicine->is_narcotic)
                                        <span class="ms-1 text-[10px] font-black px-1.5 py-0.5 rounded bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 uppercase">{{ __('health.ph_badge_controlled') }}</span>
                                    @endif
                                    @if($medicine->is_refrigerated)
                                        <span class="ms-1 text-[10px] font-black px-1.5 py-0.5 rounded bg-sky-100 dark:bg-sky-900/40 text-sky-800 dark:text-sky-200 uppercase">{{ __('health.ph_badge_cold') }}</span>
                                    @endif
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    {{ __('health.form_' . ($medicine->form ?: 'other')) }}
                                    @if($medicine->generic_name) &middot; {{ $medicine->generic_name }} @endif
                                    @if($medicine->manufacturer) &middot; {{ $medicine->manufacturer }} @endif
                                    @if($medicine->code) &middot; {{ $medicine->code }} @endif
                                </p>
                                @if(($substituteMap[$medicine->id] ?? collect())->isNotEmpty())
                                    <p class="text-[11px] text-teal-700 dark:text-teal-300 mt-0.5">
                                        {{ __('health.ph_f_substitutes') }}: {{ $substituteMap[$medicine->id]->pluck('name')->implode(', ') }}
                                    </p>
                                @endif
                            </div>

                            <div class="text-end">
                                <p class="text-sm font-black {{ $level > 0 && $qty <= $level ? 'text-amber-700 dark:text-amber-300' : '' }}">
                                    {{ rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') }}
                                </p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $medicine->unit_uom ?: __('health.ph_unit') }}</p>
                            </div>

                            <div class="text-end min-w-[80px]">
                                <p class="text-sm font-bold">{{ number_format((float) $medicine->sale_price, 2) }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.ph_f_sale_price') }}</p>
                            </div>

                            @if($canManage)
                                <button type="button" @click="openEdit({{ $payload }})"
                                        class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                    {{ __('health.edit') }}
                                </button>
                                <form method="POST" action="{{ url('/health/pharmacy/medicines/' . $medicine->id . '/toggle') }}"
                                      onsubmit="return confirm('{{ __('health.confirm') }}');">
                                    @csrf
                                    <button type="submit"
                                            class="px-3 py-1.5 rounded-lg border text-xs font-bold transition
                                                   {{ $medicine->is_active
                                                        ? 'border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-900/20'
                                                        : 'border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-900/20' }}">
                                        {{ $medicine->is_active ? __('health.ph_deactivate') : __('health.ph_reactivate') }}
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div>{{ $medicines->links() }}</div>
    </div>
</x-health-layout>
