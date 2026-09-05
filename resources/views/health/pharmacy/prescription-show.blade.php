@php use App\Models\HealthPrescription; @endphp
{{--
    Dispensing screen.

    Everything the pharmacist needs to decide is on one page: what was written,
    what is still owed, whether the shelf can cover it, which lot would go out
    (FEFO), and what may be substituted. A partial fill is normal — the quantity
    box defaults to what is left, and lowering it is not an error.
--}}
<x-health-layout>
    @php
        $rows = [];
        foreach ($prescription->items as $item) {
            $suggestion = $suggestions[$item->id] ?? null;
            $options = [];

            if ($item->medicine_id) {
                $options[] = [
                    'id' => (int) $item->medicine_id,
                    'label' => mb_convert_encoding((string) ($item->medicine?->display_name ?? $item->medicine_name), 'UTF-8', 'UTF-8'),
                    'price' => (float) ($item->medicine?->sale_price ?? 0),
                    'substitute' => false,
                ];

                foreach (($substituteMap[$item->medicine_id] ?? collect()) as $substitute) {
                    $options[] = [
                        'id' => (int) $substitute->substitute_id,
                        'label' => mb_convert_encoding(trim($substitute->name . ' ' . ($substitute->strength ?? '')), 'UTF-8', 'UTF-8'),
                        'price' => (float) $substitute->sale_price,
                        'substitute' => true,
                    ];
                }
            } else {
                // Written in words by the doctor: the dispenser picks the shelf
                // item themselves. Nothing is preselected — guessing which
                // medicine a name meant is not the software's call.
                $options[] = ['id' => '', 'label' => __('health.ph_rx_pick_medicine'), 'price' => 0, 'substitute' => false];

                foreach (($catalogueMatches[$item->id] ?? collect()) as $candidate) {
                    $options[] = [
                        'id' => (int) $candidate->id,
                        'label' => mb_convert_encoding(trim($candidate->name . ' ' . ($candidate->strength ?? '')), 'UTF-8', 'UTF-8'),
                        'price' => (float) $candidate->sale_price,
                        'substitute' => false,
                    ];
                }
            }

            $rows[] = [
                'id' => (int) $item->id,
                'name' => mb_convert_encoding((string) $item->medicine_name, 'UTF-8', 'UTF-8'),
                'medicine_id' => $item->medicine_id ? (int) $item->medicine_id : '',
                'remaining' => (float) $item->remaining_quantity,
                'price' => (float) ($item->medicine?->sale_price ?? 0),
                'options' => $options,
                'shortfall' => (float) ($suggestion['shortfall'] ?? 0),
            ];
        }
    @endphp

    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="pharmacyDispense({{ \Illuminate\Support\Js::from($rows) }})">

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">
                    {{ $prescription->prescription_no }}
                    @php
                        $tone = match ($prescription->dispense_status) {
                            HealthPrescription::DISPENSE_DISPENSED => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200',
                            HealthPrescription::DISPENSE_PARTIAL => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200',
                            HealthPrescription::DISPENSE_CANCELLED => 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200',
                            default => 'bg-sky-100 dark:bg-sky-900/40 text-sky-800 dark:text-sky-200',
                        };
                    @endphp
                    <span class="ms-1.5 align-middle text-[10px] font-black px-2 py-0.5 rounded-full uppercase {{ $tone }}">
                        {{ __('health.rx_status_' . $prescription->dispense_status) }}
                    </span>
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ $prescription->patient_display_name }}
                    @if($prescription->patient_display_mr) &middot; {{ $prescription->patient_display_mr }} @endif
                    @if($prescription->patient_phone) &middot; {{ $prescription->patient_phone }} @endif
                    @if($prescription->patient_age) &middot; {{ $prescription->patient_age }} @endif
                    @if($prescription->doctor_display_name) &middot; {{ __('health.ph_doctor') }}: {{ $prescription->doctor_display_name }} @endif
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ ($prescription->prescribed_on ?? $prescription->issued_at) ? \Illuminate\Support\Carbon::parse($prescription->prescribed_on ?? $prescription->issued_at)->format('d-m-Y') : '' }}
                    @if($prescription->department) &middot; {{ $prescription->department->name }} @endif
                    @if($prescription->branch) &middot; {{ $prescription->branch->name }} @endif
                    @if($prescription->general_instructions) &middot; {{ $prescription->general_instructions }} @endif
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('health.pharmacy.prescriptions') }}"
                   class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">{{ __('health.ph_back') }}</a>
                @if($canDispense)
                    @if($prescription->dispense_status === HealthPrescription::DISPENSE_CANCELLED)
                        <form method="POST" action="{{ url('/health/pharmacy/prescriptions/' . $prescription->id . '/reopen') }}">
                            @csrf
                            <button type="submit" class="px-4 py-2.5 rounded-xl border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 text-sm font-bold hover:bg-emerald-50 dark:hover:bg-emerald-900/20 transition">{{ __('health.ph_rx_reopen') }}</button>
                        </form>
                    @else
                        <form method="POST" action="{{ url('/health/pharmacy/prescriptions/' . $prescription->id . '/cancel') }}"
                              onsubmit="return confirm('{{ __('health.confirm') }}');">
                            @csrf
                            <button type="submit" class="px-4 py-2.5 rounded-xl border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm font-bold hover:bg-red-50 dark:hover:bg-red-900/20 transition">{{ __('health.ph_rx_cancel') }}</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>

        {{-- ── Lines & dispensing ── --}}
        <form method="POST" action="{{ url('/health/pharmacy/prescriptions/' . $prescription->id . '/dispense') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @csrf

            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach($prescription->items as $index => $item)
                    @php
                        $suggestion = $suggestions[$item->id] ?? null;
                        $onHand = (float) ($available[$item->medicine_id] ?? 0);
                        $remaining = (float) $item->remaining_quantity;
                        $done = $remaining <= 0;
                    @endphp
                    <div class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex-1 min-w-[220px]">
                                <p class="text-sm font-black">
                                    {{ $item->medicine_name }}
                                    @if($done)
                                        <span class="ms-1.5 text-[10px] font-black px-1.5 py-0.5 rounded bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200 uppercase">{{ __('health.ph_rx_done') }}</span>
                                    @endif
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    @if($item->frequency) {{ $item->frequency }} @endif
                                    @if($item->duration_label) &middot; {{ $item->duration_label }} @endif
                                    @if($item->instructions) &middot; {{ $item->instructions }} @endif
                                </p>
                                @if(!$item->medicine_id)
                                    <p class="text-[11px] text-amber-700 dark:text-amber-300 mt-0.5">{{ __('health.ph_rx_not_in_catalogue') }}</p>
                                @endif
                            </div>

                            <div class="text-end">
                                <p class="text-sm font-black">
                                    {{ rtrim(rtrim(number_format((float) $item->dispensed_quantity, 3, '.', ''), '0'), '.') }}
                                    / {{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}
                                </p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.ph_rx_dispensed_of') }}</p>
                            </div>

                            <div class="text-end min-w-[90px]">
                                <p class="text-sm font-bold {{ $item->medicine_id && $onHand < $remaining ? 'text-red-700 dark:text-red-300' : '' }}">
                                    {{ $item->medicine_id ? rtrim(rtrim(number_format($onHand, 3, '.', ''), '0'), '.') : '—' }}
                                </p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.ph_on_hand') }}</p>
                            </div>
                        </div>

                        {{-- The FEFO pick, shown before anything moves so a
                             short-dated lot can still be refused. --}}
                        @if($suggestion && !empty($suggestion['batches']))
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach($suggestion['batches'] as $allocation)
                                    <span class="text-[11px] px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">
                                        {{ $allocation['batch_no'] ?: __('health.ph_no_batch') }}
                                        @if($allocation['expiry']) &middot; {{ \Illuminate\Support\Carbon::parse($allocation['expiry'])->format('m/Y') }} @endif
                                        &middot; {{ rtrim(rtrim(number_format((float) $allocation['quantity'], 3, '.', ''), '0'), '.') }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        @if($suggestion && !empty($suggestion['warnings']))
                            @foreach($suggestion['warnings'] as $warning)
                                <p class="mt-1.5 text-[11px] font-bold text-orange-700 dark:text-orange-300">
                                    @if(($warning['type'] ?? null) === 'short_dated')
                                        {{ __('health.ph_warn_short_dated', ['batch' => $warning['batch'] ?? '—', 'days' => $warning['days'] ?? 0]) }}
                                    @elseif(($warning['type'] ?? null) === 'expired')
                                        {{ __('health.ph_warn_expired', ['batch' => $warning['batch'] ?? '—']) }}
                                    @else
                                        {{ __('health.ph_warn_generic') }}
                                    @endif
                                </p>
                            @endforeach
                        @endif

                        @if($suggestion && ($suggestion['shortfall'] ?? 0) > 0)
                            <p class="mt-1.5 text-[11px] font-bold text-red-700 dark:text-red-300">
                                {{ __('health.ph_warn_shortfall', ['qty' => rtrim(rtrim(number_format((float) $suggestion['shortfall'], 3, '.', ''), '0'), '.')]) }}
                            </p>
                        @endif

                        @if($canDispense && $prescription->dispense_status !== HealthPrescription::DISPENSE_CANCELLED && !$done)
                            <div class="mt-3 grid sm:grid-cols-3 gap-2 items-end" x-data>
                                <input type="hidden" name="lines[{{ $index }}][prescription_item_id]" value="{{ $item->id }}">
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_dispense_now') }}</label>
                                    <input type="number" step="0.001" min="0" max="{{ $remaining }}"
                                           name="lines[{{ $index }}][quantity]"
                                           x-model.number="rows[{{ $index }}].quantity" @input="recalc()"
                                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_give_medicine') }}</label>
                                    <select name="lines[{{ $index }}][medicine_id]"
                                            x-model.number="rows[{{ $index }}].medicine_id" @change="applyPrice({{ $index }})"
                                            class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                        <template x-for="option in rows[{{ $index }}].options" :key="option.id">
                                            <option :value="option.id" x-text="option.substitute ? option.label + ' — {{ __('health.ph_substitute') }}' : option.label"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_unit_price') }}</label>
                                    <input type="number" step="0.01" min="0"
                                           name="lines[{{ $index }}][unit_price]"
                                           x-model.number="rows[{{ $index }}].price" @input="recalc()"
                                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            @if($canDispense && $prescription->dispense_status !== HealthPrescription::DISPENSE_CANCELLED && $prescription->isOpen())
                <div class="px-5 py-4 bg-gray-50 dark:bg-gray-900/40 border-t border-gray-200 dark:border-gray-700">
                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_pay_method') }}</label>
                            <select name="payment_method" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm">
                                @foreach(\App\Models\HealthPharmacySale::PAYMENT_METHODS as $method)
                                    <option value="{{ $method }}">{{ __('health.pay_' . $method) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_discount') }}</label>
                            <input type="number" step="0.01" min="0" name="discount_amount"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_paid') }}</label>
                            <input type="number" step="0.01" min="0" name="paid_amount"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm">
                        </div>
                        <div class="text-end">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.ph_line_total') }}</p>
                            <p class="text-2xl font-black text-teal-700 dark:text-teal-300" x-text="gross().toFixed(2)"></p>
                        </div>
                    </div>

                    <button type="submit" class="mt-3 px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                        {{ __('health.ph_dispense_save') }}
                    </button>
                </div>
            @endif
        </form>

        {{-- ── What already went out ── --}}
        @if($sales->isNotEmpty())
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-sm font-black">{{ __('health.ph_rx_history') }}</h2>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($sales as $sale)
                        <a href="{{ route('health.pharmacy.sales.show', $sale->id) }}"
                           class="px-4 py-3 flex flex-wrap items-center justify-between gap-2 hover:bg-gray-50 dark:hover:bg-gray-700/40 transition">
                            <div>
                                <p class="text-sm font-bold">{{ $sale->sale_number }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ $sale->created_at?->format('d-m-Y H:i') }} &middot;
                                    {{ $sale->items->pluck('item_name')->implode(', ') }}
                                </p>
                            </div>
                            <p class="text-sm font-black">{{ number_format((float) $sale->total_amount, 2) }}</p>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <script>
        function pharmacyDispense(rows) {
            return {
                // Default: fill what is still owed. Lowering it is a partial
                // fill, which is a normal outcome — not an error.
                rows: rows.map((row) => Object.assign({}, row, {
                    quantity: row.remaining > 0 ? row.remaining : 0,
                })),
                applyPrice(index) {
                    const row = this.rows[index];
                    const option = (row.options || []).find((o) => o.id === Number(row.medicine_id));
                    if (option) row.price = option.price;
                },
                recalc() {},
                gross() {
                    return this.rows.reduce((sum, r) => sum + (Number(r.quantity) || 0) * (Number(r.price) || 0), 0);
                },
            };
        }
    </script>
</x-health-layout>
