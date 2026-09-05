@php use App\Models\HealthPharmacySale; @endphp
{{--
    One pharmacy bill.

    The lot that went out is printed on every line, because a recall starts
    here. Returns default to putting the goods back — a non-restock return is
    recorded as a restock immediately followed by a wastage deduct, so nothing
    ever vanishes silently from the ledger.
--}}
<x-health-layout>
    @php
        $returnRows = $sale->items->map(fn ($item) => [
            'id' => (int) $item->id,
            'label' => mb_convert_encoding((string) $item->item_name, 'UTF-8', 'UTF-8'),
            'returnable' => (float) $item->returnable_quantity,
            'rate' => (float) $item->quantity > 0 ? round((float) $item->line_total / (float) $item->quantity, 2) : 0.0,
        ])->values();
    @endphp

    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{
            returning: false,
            rows: {{ \Illuminate\Support\Js::from($returnRows) }},
            refund() { return this.rows.reduce((s, r) => s + (Number(r.quantity) || 0) * r.rate, 0); }
         }">

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                @php
                    $tone = match ($sale->status) {
                        HealthPharmacySale::STATUS_RETURNED => 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200',
                        HealthPharmacySale::STATUS_PARTIALLY_RETURNED => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200',
                        HealthPharmacySale::STATUS_VOID => 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200',
                        default => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200',
                    };
                @endphp
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">
                    {{ $sale->sale_number }}
                    <span class="ms-1.5 align-middle text-[10px] font-black px-2 py-0.5 rounded-full uppercase {{ $tone }}">
                        {{ __('health.sale_status_' . $sale->status) }}
                    </span>
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ $sale->created_at?->format('d-m-Y H:i') }}
                    &middot; {{ $sale->patient_name ?: __('health.ph_walk_in') }}
                    @if($sale->patient_mr_no) &middot; {{ $sale->patient_mr_no }} @endif
                    @if($sale->patient_phone) &middot; {{ $sale->patient_phone }} @endif
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ __('health.pay_' . $sale->payment_method) }}
                    @if($sale->branch) &middot; {{ $sale->branch->name }} @endif
                    @if($sale->creator) &middot; {{ $sale->creator->name }} @endif
                    @if($sale->prescription)
                        &middot; <a href="{{ route('health.pharmacy.prescriptions.show', $sale->prescription_id) }}"
                                    class="text-teal-700 dark:text-teal-300 font-bold hover:underline">{{ $sale->prescription->prescription_no }}</a>
                    @endif
                </p>
                @if($sale->notes)
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $sale->notes }}</p>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('health.pharmacy.sales.receipt', $sale->id) }}" target="_blank"
                   class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">{{ __('health.ph_print') }}</a>
                @if($canDispense && $sale->status !== HealthPharmacySale::STATUS_VOID && $sale->items->sum(fn ($i) => $i->returnable_quantity) > 0)
                    <button type="button" @click="returning = !returning"
                            class="px-4 py-2.5 rounded-xl border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm font-bold hover:bg-red-50 dark:hover:bg-red-900/20 transition">{{ __('health.ph_return') }}</button>
                @endif
                <a href="{{ route('health.pharmacy.sales') }}"
                   class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">{{ __('health.ph_back') }}</a>
            </div>
        </div>

        {{-- FBR readiness is a frozen snapshot: the tax split on this bill was
             fixed when it was rung up, whatever the settings say today. Filing
             itself belongs to the billing module, not here. --}}
        <div class="rounded-xl px-4 py-2.5 text-xs font-bold
                    {{ $sale->fbr_ready
                        ? 'bg-sky-50 dark:bg-sky-900/20 text-sky-800 dark:text-sky-200 border border-sky-200 dark:border-sky-800'
                        : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700' }}">
            {{ $sale->fbr_ready ? __('health.ph_fbr_ready_note') : __('health.ph_fbr_not_ready_note') }}
        </div>

        {{-- ── Lines ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2 text-start font-black">{{ __('health.ph_medicine') }}</th>
                            <th class="px-4 py-2 text-start font-black">{{ __('health.ph_batch_no') }}</th>
                            <th class="px-4 py-2 text-end font-black">{{ __('health.ph_qty') }}</th>
                            <th class="px-4 py-2 text-end font-black">{{ __('health.ph_unit_price') }}</th>
                            <th class="px-4 py-2 text-end font-black">{{ __('health.ph_discount') }}</th>
                            <th class="px-4 py-2 text-end font-black">{{ __('health.ph_tax') }}</th>
                            <th class="px-4 py-2 text-end font-black">{{ __('health.ph_line_total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($sale->items as $item)
                            <tr>
                                <td class="px-4 py-2.5">
                                    <p class="font-bold">{{ $item->item_name }}</p>
                                    @if($item->is_substitute)
                                        <span class="text-[10px] font-black px-1.5 py-0.5 rounded bg-purple-100 dark:bg-purple-900/40 text-purple-800 dark:text-purple-200 uppercase">{{ __('health.ph_substitute') }}</span>
                                    @endif
                                    @if($item->dosage_instructions)
                                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $item->dosage_instructions }}</p>
                                    @endif
                                    @if((float) $item->returned_quantity > 0)
                                        <p class="text-[11px] text-red-700 dark:text-red-300">
                                            {{ __('health.ph_returned') }}: {{ rtrim(rtrim(number_format((float) $item->returned_quantity, 3, '.', ''), '0'), '.') }}
                                        </p>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-xs">
                                    {{ $item->batch_no ?: __('health.ph_no_batch') }}
                                    @if($item->expiry_date)
                                        <span class="text-gray-500 dark:text-gray-400">&middot; {{ $item->expiry_date->format('m/Y') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-end">{{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}</td>
                                <td class="px-4 py-2.5 text-end">{{ number_format((float) $item->unit_price, 2) }}</td>
                                <td class="px-4 py-2.5 text-end">{{ number_format((float) $item->discount_amount, 2) }}</td>
                                <td class="px-4 py-2.5 text-end">{{ number_format((float) $item->tax_amount, 2) }}</td>
                                <td class="px-4 py-2.5 text-end font-bold">{{ number_format((float) $item->line_total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4 bg-gray-50 dark:bg-gray-900/40 border-t border-gray-200 dark:border-gray-700">
                <div class="ms-auto max-w-xs space-y-1 text-sm">
                    @foreach([
                        ['health.ph_subtotal', (float) $sale->subtotal],
                        ['health.ph_discount', -(float) $sale->discount_amount],
                        ['health.ph_tax', (float) $sale->tax_amount],
                    ] as [$label, $value])
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500 dark:text-gray-400 font-semibold">{{ __($label) }}</span>
                            <span class="font-bold">{{ number_format($value, 2) }}</span>
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between pt-1.5 border-t border-gray-200 dark:border-gray-700">
                        <span class="font-black">{{ __('health.ph_total') }}</span>
                        <span class="text-xl font-black text-teal-700 dark:text-teal-300">{{ number_format((float) $sale->total_amount, 2) }}</span>
                    </div>
                    @if((float) $sale->refunded_amount > 0)
                        <div class="flex items-center justify-between">
                            <span class="text-red-700 dark:text-red-300 font-semibold">{{ __('health.ph_refunded') }}</span>
                            <span class="font-bold text-red-700 dark:text-red-300">−{{ number_format((float) $sale->refunded_amount, 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="font-black">{{ __('health.ph_net') }}</span>
                            <span class="font-black">{{ number_format((float) $sale->net_amount, 2) }}</span>
                        </div>
                    @endif
                    <div class="flex items-center justify-between pt-1.5 border-t border-gray-200 dark:border-gray-700">
                        <span class="text-gray-500 dark:text-gray-400 font-semibold">{{ __('health.ph_paid') }}</span>
                        <span class="font-bold">{{ number_format((float) $sale->paid_amount, 2) }}</span>
                    </div>
                    @if((float) $sale->change_amount > 0)
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500 dark:text-gray-400 font-semibold">{{ __('health.ph_change') }}</span>
                            <span class="font-bold">{{ number_format((float) $sale->change_amount, 2) }}</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ── Return ── --}}
        @if($canDispense && $sale->status !== HealthPharmacySale::STATUS_VOID)
            <div x-show="returning" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-red-200 dark:border-red-800 p-5">
                <form method="POST" action="{{ url('/health/pharmacy/sales/' . $sale->id . '/return') }}" class="space-y-4">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.ph_return') }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.ph_return_help') }}</p>

                    <div class="space-y-2">
                        @foreach($sale->items as $index => $item)
                            @if($item->returnable_quantity > 0)
                                <div class="flex flex-wrap items-end gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-700">
                                    <input type="hidden" name="lines[{{ $index }}][sale_item_id]" value="{{ $item->id }}">
                                    <div class="flex-1 min-w-[180px]">
                                        <p class="text-sm font-bold">{{ $item->item_name }}</p>
                                        <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                            {{ __('health.ph_returnable') }}: {{ rtrim(rtrim(number_format((float) $item->returnable_quantity, 3, '.', ''), '0'), '.') }}
                                            &middot; {{ $item->batch_no ?: __('health.ph_no_batch') }}
                                        </p>
                                    </div>
                                    <div class="w-28">
                                        <label class="block text-[10px] font-bold uppercase text-gray-500 dark:text-gray-400 mb-0.5">{{ __('health.ph_qty') }}</label>
                                        <input type="number" step="0.001" min="0" max="{{ $item->returnable_quantity }}"
                                               name="lines[{{ $index }}][quantity]"
                                               x-model.number="rows[{{ $index }}].quantity"
                                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    {{-- Restock is the default. Unticking it does not make the
                         goods disappear — it books them as wastage, on the
                         record. --}}
                    <label class="flex items-center gap-2 text-sm font-semibold">
                        <input type="checkbox" name="restock" value="1" checked
                               class="rounded border-gray-300 dark:border-gray-600 text-teal-600 focus:ring-teal-500">
                        {{ __('health.ph_restock') }}
                    </label>

                    <input type="text" name="reason" maxlength="190" placeholder="{{ __('health.ph_reason') }}"
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-red-700 hover:bg-red-800 text-white text-sm font-black transition">{{ __('health.ph_return_save') }}</button>
                        <button type="button" @click="returning = false" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.cancel') }}</button>
                        <span class="ms-auto text-sm font-black text-red-700 dark:text-red-300">
                            {{ __('health.ph_refund_amount') }}: <span x-text="refund().toFixed(2)"></span>
                        </span>
                    </div>
                </form>
            </div>
        @endif

        {{-- ── Return history ── --}}
        @if($returns->isNotEmpty())
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-sm font-black">{{ __('health.ph_return_history') }}</h2>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($returns as $return)
                        <div class="px-4 py-3 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-bold">{{ $return->return_number }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ $return->created_at?->format('d-m-Y H:i') }}
                                    &middot; {{ $return->restock ? __('health.ph_restocked') : __('health.ph_wasted') }}
                                    &middot; {{ __('health.ph_rx_lines') }}: {{ $return->items->count() }}
                                    @if($return->reason) &middot; {{ $return->reason }} @endif
                                </p>
                            </div>
                            <p class="text-sm font-black text-red-700 dark:text-red-300">−{{ number_format((float) $return->refund_amount, 2) }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-health-layout>
