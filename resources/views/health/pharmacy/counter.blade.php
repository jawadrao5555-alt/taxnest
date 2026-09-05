{{--
    Pharmacy counter.

    Walk-in and patient-linked sales settle through the same service a
    prescription fill uses, so stock, cost and the FBR-ready tax split can never
    diverge between the two doors.

    The screen shows what the shelf actually holds and how soon it dies, at the
    moment of picking — a warning after the money is taken is worthless.
--}}
<x-health-layout>
    @php
        $near = (int) $settings->near_expiry_days;
        $today = now()->startOfDay();

        $catalogue = $medicines->map(function ($medicine) use ($available, $earliestExpiry, $near, $today) {
            $expiry = $earliestExpiry[$medicine->id] ?? null;
            $days = null;
            if ($expiry) {
                $days = (int) $today->diffInDays(\Illuminate\Support\Carbon::parse($expiry)->startOfDay(), false);
            }

            return [
                'id' => (int) $medicine->id,
                'label' => mb_convert_encoding(trim($medicine->name . ' ' . ($medicine->strength ?? '')), 'UTF-8', 'UTF-8'),
                'search' => mb_convert_encoding(mb_strtolower(trim(
                    $medicine->name . ' ' . ($medicine->generic_name ?? '') . ' ' . ($medicine->code ?? '') . ' ' . ($medicine->barcode ?? '')
                )), 'UTF-8', 'UTF-8'),
                'price' => (float) $medicine->sale_price,
                'tax_rate' => $medicine->tax_rate !== null ? (float) $medicine->tax_rate : null,
                'available' => round((float) ($available[$medicine->id] ?? 0), 3),
                'rx' => (bool) $medicine->requires_prescription,
                'controlled' => (bool) ($medicine->is_controlled || $medicine->is_narcotic),
                'days' => $days,
                'short' => $days !== null && $days >= 0 && $days <= $near,
                'expired' => $days !== null && $days < 0,
            ];
        })->values();
    @endphp

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5"
         x-data="pharmacyCounter({{ \Illuminate\Support\Js::from($catalogue) }}, {{ (float) $settings->default_tax_rate }}, '{{ route('health.pharmacy.counter.batches', [], false) }}')">

        <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ph_counter_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.ph_counter_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($fbr['configured'])
                    <span class="px-3 py-1.5 rounded-full text-[11px] font-black uppercase bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200">
                        {{ __('health.ph_fbr_ready') }}
                    </span>
                @else
                    <span class="px-3 py-1.5 rounded-full text-[11px] font-black uppercase bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200"
                          title="{{ __('health.ph_fbr_not_ready_help') }}">
                        {{ __('health.ph_fbr_not_ready') }}
                    </span>
                @endif
                <a href="{{ route('health.pharmacy.sales') }}"
                   class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">{{ __('health.ph_sales_title') }}</a>
            </div>
        </div>

        <form method="POST" action="{{ route('health.pharmacy.counter.store') }}" class="grid lg:grid-cols-5 gap-4">
            @csrf

            {{-- ══════ Picker ══════ --}}
            <div class="lg:col-span-3 space-y-3">
                <input type="text" x-model="search" @keydown.enter.prevent="addFirst()"
                       placeholder="{{ __('health.ph_counter_search') }}"
                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">

                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 max-h-[380px] overflow-y-auto divide-y divide-gray-200 dark:divide-gray-700">
                    {{-- Capped on purpose: an unbounded x-for over a large
                         catalogue freezes the counter on boot. --}}
                    <template x-for="medicine in visible()" :key="medicine.id">
                        <button type="button" @click="add(medicine)"
                                class="w-full text-start px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700/40 transition flex items-center justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-bold truncate" x-text="medicine.label"></span>
                                <span class="block text-[11px] text-gray-500 dark:text-gray-400">
                                    <span x-show="medicine.rx" class="text-amber-700 dark:text-amber-300 font-bold">{{ __('health.ph_badge_rx') }} · </span>
                                    <span x-show="medicine.expired" class="text-red-700 dark:text-red-300 font-bold">{{ __('health.ph_badge_expired') }} · </span>
                                    <span x-show="medicine.short && !medicine.expired" class="text-orange-700 dark:text-orange-300 font-bold">{{ __('health.ph_badge_short_dated') }} · </span>
                                    <span x-text="'{{ __('health.ph_available') }}: ' + medicine.available"></span>
                                </span>
                            </span>
                            <span class="text-sm font-black whitespace-nowrap" x-text="medicine.price.toFixed(2)"></span>
                        </button>
                    </template>
                    <p x-show="!visible().length" class="px-4 py-8 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('health.ph_none') }}</p>
                </div>

                {{-- ══════ Cart ══════ --}}
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <p x-show="!lines.length" class="px-4 py-8 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('health.ph_cart_empty') }}</p>
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        <template x-for="(line, index) in lines" :key="line.key">
                            <div class="px-4 py-3">
                                <input type="hidden" :name="'lines[' + index + '][medicine_id]'" :value="line.medicine_id">
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="flex-1 min-w-[160px]">
                                        <p class="text-sm font-bold" x-text="line.label"></p>
                                        <p class="text-[11px]" :class="line.available < line.quantity ? 'text-red-700 dark:text-red-300 font-bold' : 'text-gray-500 dark:text-gray-400'"
                                           x-text="'{{ __('health.ph_available') }}: ' + line.available"></p>
                                    </div>
                                    <div class="w-20">
                                        <label class="block text-[10px] font-bold uppercase text-gray-500 dark:text-gray-400 mb-0.5">{{ __('health.ph_qty') }}</label>
                                        <input type="number" step="0.001" min="0.001" :name="'lines[' + index + '][quantity]'"
                                               x-model.number="line.quantity"
                                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                    </div>
                                    <div class="w-24">
                                        <label class="block text-[10px] font-bold uppercase text-gray-500 dark:text-gray-400 mb-0.5">{{ __('health.ph_unit_price') }}</label>
                                        <input type="number" step="0.01" min="0" :name="'lines[' + index + '][unit_price]'"
                                               x-model.number="line.unit_price"
                                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                    </div>
                                    <div class="w-24">
                                        <label class="block text-[10px] font-bold uppercase text-gray-500 dark:text-gray-400 mb-0.5">{{ __('health.ph_discount') }}</label>
                                        <input type="number" step="0.01" min="0" :name="'lines[' + index + '][discount_amount]'"
                                               x-model.number="line.discount_amount"
                                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                    </div>
                                    <div class="w-20 text-end">
                                        <p class="text-[10px] font-bold uppercase text-gray-500 dark:text-gray-400">{{ __('health.ph_line_total') }}</p>
                                        <p class="text-sm font-black" x-text="lineNet(line).toFixed(2)"></p>
                                    </div>
                                    <button type="button" @click="remove(index)"
                                            class="px-2.5 py-2 rounded-lg border border-red-200 dark:border-red-800 text-red-600 dark:text-red-300 text-xs font-bold">✕</button>
                                </div>

                                {{-- Pinning a lot is optional. Left alone the
                                     counter picks first-expiry-first, which is
                                     what a pharmacy should do by default. --}}
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <select :name="'lines[' + index + '][batch_id]'" x-model="line.batch_id"
                                            class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                        <option value="">{{ __('health.ph_batch_auto') }}</option>
                                        <template x-for="batch in (line.batches || [])" :key="batch.id">
                                            <option :value="batch.id"
                                                    x-text="(batch.batch_no || '{{ __('health.ph_no_batch') }}') + ' · ' + (batch.expiry_date || '{{ __('health.ph_no_expiry') }}') + ' · ' + batch.quantity"></option>
                                        </template>
                                    </select>
                                    <span x-show="line.warning" class="text-[11px] font-bold text-orange-700 dark:text-orange-300" x-text="line.warning"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- ══════ Payment ══════ --}}
            <div class="lg:col-span-2 space-y-3">
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                    @if($isMultiBranch)
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_branch') }}</label>
                            <select name="branch_id" @if($mustPickBranch) required @endif
                                    class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">{{ __('health.ph_pick_branch_option') }}</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected($viewBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    {{-- Naming a patient is what turns a walk-in into a
                         patient-linked sale; nothing else changes. --}}
                    <div class="grid grid-cols-2 gap-2">
                        <div class="col-span-2">
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_patient_name') }}</label>
                            <input type="text" name="patient_name" maxlength="190" value="{{ old('patient_name') }}"
                                   placeholder="{{ __('health.ph_walk_in') }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_patient_mr') }}</label>
                            <input type="text" name="patient_mr_no" maxlength="64" value="{{ old('patient_mr_no') }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_patient_phone') }}</label>
                            <input type="text" name="patient_phone" maxlength="32" value="{{ old('patient_phone') }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500 dark:text-gray-400 font-semibold">{{ __('health.ph_subtotal') }}</span>
                        <span class="font-bold" x-text="subtotal().toFixed(2)"></span>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_bill_discount') }}</label>
                            <input type="number" step="0.01" min="0" name="discount_amount" x-model.number="billDiscount"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_tax_rate') }}</label>
                            <input type="number" step="0.01" min="0" max="100" name="tax_rate" x-model.number="taxRate"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500 dark:text-gray-400 font-semibold">{{ __('health.ph_tax') }}</span>
                        <span class="font-bold" x-text="tax().toFixed(2)"></span>
                    </div>

                    <div class="flex items-center justify-between pt-2 border-t border-gray-200 dark:border-gray-700">
                        <span class="text-sm font-black">{{ __('health.ph_total') }}</span>
                        <span class="text-2xl font-black text-teal-700 dark:text-teal-300" x-text="total().toFixed(2)"></span>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_pay_method') }}</label>
                            <select name="payment_method" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach($paymentMethods as $method)
                                    <option value="{{ $method }}">{{ __('health.pay_' . $method) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_paid') }}</label>
                            <input type="number" step="0.01" min="0" name="paid_amount" x-model.number="paid"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-sm" x-show="paid > 0">
                        <span class="text-gray-500 dark:text-gray-400 font-semibold">{{ __('health.ph_change') }}</span>
                        <span class="font-bold" x-text="Math.max(0, paid - total()).toFixed(2)"></span>
                    </div>

                    <input type="text" name="notes" maxlength="500" placeholder="{{ __('health.ph_f_notes') }}"
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">

                    {{-- Expired stock never goes out silently. This switch is a
                         deliberate override, and the bill records that it was
                         used. --}}
                    @if(!$settings->block_expired_dispense)
                        <label class="flex items-center gap-2 text-xs font-semibold text-red-700 dark:text-red-300">
                            <input type="checkbox" name="allow_expired" value="1"
                                   class="rounded border-gray-300 dark:border-gray-600 text-red-600 focus:ring-red-500">
                            {{ __('health.ph_allow_expired') }}
                        </label>
                    @endif

                    <button type="submit" :disabled="!lines.length"
                            class="w-full px-5 py-3 rounded-xl bg-teal-700 hover:bg-teal-800 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-black transition">
                        {{ __('health.ph_complete_sale') }}
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        // Labels come through Js::from, never a bare translation echo: an
        // apostrophe inside a translation would close the JS string and
        // white-screen the whole counter, which sits behind x-cloak and would
        // simply render blank with nothing in the server log.
        const PH_TXT = {
            expired: {{ \Illuminate\Support\Js::from(__('health.ph_badge_expired')) }},
            shortDated: {{ \Illuminate\Support\Js::from(__('health.ph_badge_short_dated')) }},
        };

        function pharmacyCounter(catalogue, defaultTaxRate, batchUrl) {
            let counter = 0;

            return {
                catalogue: catalogue,
                search: '',
                lines: [],
                billDiscount: 0,
                taxRate: defaultTaxRate,
                paid: 0,

                // Capped: the picker is a search box, not a full catalogue dump.
                visible() {
                    const term = (this.search || '').trim().toLowerCase();
                    const list = term
                        ? this.catalogue.filter((m) => m.search.includes(term))
                        : this.catalogue;
                    return list.slice(0, 40);
                },

                addFirst() {
                    const first = this.visible()[0];
                    if (first) this.add(first);
                },

                add(medicine) {
                    const existing = this.lines.find((l) => l.medicine_id === medicine.id && !l.batch_id);
                    if (existing) {
                        existing.quantity = Number(existing.quantity || 0) + 1;
                        return;
                    }

                    const line = {
                        key: ++counter,
                        medicine_id: medicine.id,
                        label: medicine.label,
                        quantity: 1,
                        unit_price: medicine.price,
                        discount_amount: 0,
                        available: medicine.available,
                        batch_id: '',
                        batches: [],
                        warning: medicine.expired
                            ? PH_TXT.expired
                            : (medicine.short ? PH_TXT.shortDated : ''),
                    };

                    this.lines.push(line);
                    this.search = '';
                    this.loadBatches(line);
                },

                async loadBatches(line) {
                    try {
                        // route(..., [], false): a forced-https absolute URL
                        // never arrives on the plain-http dev bridge.
                        const r = await fetch(batchUrl + '?medicine_id=' + line.medicine_id, {
                            headers: { 'Accept': 'application/json' },
                        });
                        if (!r.ok) return;
                        const data = await r.json();
                        if (!data || data.ok !== true) return;
                        line.batches = data.batches || [];
                    } catch (e) {
                        // A failed lookup leaves the lot on automatic FEFO,
                        // which is the safe default — it does not block the sale.
                    }
                },

                remove(index) { this.lines.splice(index, 1); },

                lineNet(line) {
                    const gross = (Number(line.quantity) || 0) * (Number(line.unit_price) || 0);
                    return Math.max(0, gross - (Number(line.discount_amount) || 0));
                },

                subtotal() {
                    return this.lines.reduce((sum, l) => sum + this.lineNet(l), 0);
                },

                net() {
                    return Math.max(0, this.subtotal() - (Number(this.billDiscount) || 0));
                },

                tax() {
                    return this.net() * ((Number(this.taxRate) || 0) / 100);
                },

                total() {
                    return this.net() + this.tax();
                },
            };
        }
    </script>
</x-health-layout>
