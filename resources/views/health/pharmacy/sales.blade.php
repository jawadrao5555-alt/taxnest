@php use App\Models\HealthPharmacySale; @endphp
{{--
    Pharmacy bills.

    Refunds are shown against the gross rather than netted into it, because a
    day with heavy returns and a quiet day are not the same day.
--}}
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ph_sales_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.ph_sales_subtitle') }}</p>
            </div>
            @if(Route::has('health.pharmacy.counter'))
                <a href="{{ route('health.pharmacy.counter') }}"
                   class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">{{ __('health.ph_quick_counter') }}</a>
            @endif
        </div>

        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_from') }}</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_to') }}</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('health.ph_search_sale') }}"
                   class="flex-1 min-w-[160px] rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            <button type="submit" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                {{ __('health.ph_apply') }}
            </button>
        </form>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @php
                $gross = (float) ($totals->gross ?? 0);
                $cost = (float) ($totals->cost ?? 0);
                $cards = [
                    ['health.ph_tile_bills_label', (int) ($totals->bills ?? 0), 'text-gray-900 dark:text-gray-100'],
                    ['health.ph_gross', number_format($gross, 2), 'text-teal-700 dark:text-teal-300'],
                    ['health.ph_refunded', number_format((float) ($totals->refunded ?? 0), 2), 'text-red-700 dark:text-red-300'],
                    ['health.ph_margin', number_format($gross - $cost, 2), 'text-emerald-700 dark:text-emerald-300'],
                ];
            @endphp
            @foreach($cards as [$label, $value, $tone])
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-[11px] font-black uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __($label) }}</p>
                    <p class="mt-1 text-xl font-black {{ $tone }}">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($sales->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.ph_sales_none') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($sales as $sale)
                        @php
                            $tone = match ($sale->status) {
                                HealthPharmacySale::STATUS_RETURNED => 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200',
                                HealthPharmacySale::STATUS_PARTIALLY_RETURNED => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200',
                                HealthPharmacySale::STATUS_VOID => 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200',
                                default => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200',
                            };
                        @endphp
                        <a href="{{ route('health.pharmacy.sales.show', $sale->id) }}"
                           class="px-5 py-4 flex flex-wrap items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700/40 transition">
                            <div class="flex-1 min-w-[220px]">
                                <p class="text-sm font-black">
                                    {{ $sale->sale_number }}
                                    <span class="ms-1.5 text-[10px] font-black px-2 py-0.5 rounded-full uppercase {{ $tone }}">
                                        {{ __('health.sale_status_' . $sale->status) }}
                                    </span>
                                    @if($sale->fbr_ready)
                                        <span class="ms-1 text-[10px] font-black px-1.5 py-0.5 rounded bg-sky-100 dark:bg-sky-900/40 text-sky-800 dark:text-sky-200 uppercase">{{ __('health.ph_fbr_ready') }}</span>
                                    @endif
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    {{ $sale->created_at?->format('d-m-Y H:i') }}
                                    &middot; {{ $sale->patient_name ?: __('health.ph_walk_in') }}
                                    &middot; {{ __('health.ph_rx_lines') }}: {{ $sale->items_count }}
                                    &middot; {{ __('health.pay_' . $sale->payment_method) }}
                                    @if($sale->creator) &middot; {{ $sale->creator->name }} @endif
                                </p>
                            </div>
                            <div class="text-end">
                                <p class="text-sm font-black">{{ number_format((float) $sale->total_amount, 2) }}</p>
                                @if((float) $sale->refunded_amount > 0)
                                    <p class="text-[11px] text-red-700 dark:text-red-300">−{{ number_format((float) $sale->refunded_amount, 2) }}</p>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <div>{{ $sales->links() }}</div>
    </div>
</x-health-layout>
