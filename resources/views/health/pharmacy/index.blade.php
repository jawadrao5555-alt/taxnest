{{--
    Pharmacy hub — the counter's home screen.

    Deliberately alert-first: a pharmacy's real risk is medicine quietly dying
    on the shelf or running out mid-prescription, so expiry and low stock come
    BEFORE the money tiles.
--}}
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ph_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ $branchName ?? __('health.ph_branch_all') }} &middot; {{ __('health.ph_subtitle') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if(Route::has('health.pharmacy.counter'))
                    <a href="{{ route('health.pharmacy.counter') }}"
                       class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.ph_quick_counter') }}
                    </a>
                @endif
                <a href="{{ route('health.pharmacy.prescriptions') }}"
                   class="px-4 py-2.5 rounded-xl border border-teal-300 dark:border-teal-800 text-teal-700 dark:text-teal-300 text-sm font-bold hover:bg-teal-50 dark:hover:bg-teal-900/20 transition">
                    {{ __('health.ph_quick_rx') }}
                </a>
            </div>
        </div>

        {{-- ── Tiles ── --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @php
                $tiles = [
                    ['label' => 'health.ph_tile_today_sales', 'value' => number_format($summary['today_sales'], 0), 'sub' => __('health.ph_tile_bills', ['count' => $summary['today_bills']]), 'tone' => 'teal',   'href' => route('health.pharmacy.sales')],
                    ['label' => 'health.ph_tile_open_rx',     'value' => $summary['open_prescriptions'],            'sub' => __('health.ph_tile_open_rx_sub'), 'tone' => 'sky',    'href' => route('health.pharmacy.prescriptions')],
                    ['label' => 'health.ph_tile_low_stock',   'value' => $summary['low_stock'],                     'sub' => __('health.ph_tile_low_stock_sub'), 'tone' => 'amber', 'href' => route('health.pharmacy.reports', ['report' => 'low_stock'])],
                    ['label' => 'health.ph_tile_near_expiry', 'value' => $summary['near_expiry'],                   'sub' => __('health.ph_within_days', ['days' => $summary['near_expiry_days']]), 'tone' => 'orange', 'href' => route('health.pharmacy.stock', ['filter' => 'near_expiry'])],
                    ['label' => 'health.ph_tile_expired',     'value' => $summary['expired'],                       'sub' => __('health.ph_tile_expired_sub'), 'tone' => 'red',   'href' => route('health.pharmacy.stock', ['filter' => 'expired'])],
                    ['label' => 'health.ph_tile_quarantined', 'value' => $summary['quarantined'],                   'sub' => __('health.ph_tile_quarantined_sub'), 'tone' => 'purple', 'href' => route('health.pharmacy.stock', ['filter' => 'quarantined'])],
                    ['label' => 'health.ph_tile_medicines',   'value' => $summary['medicines'],                     'sub' => __('health.ph_tile_medicines_sub'), 'tone' => 'gray',  'href' => route('health.pharmacy.medicines')],
                    ['label' => 'health.ph_tile_stock_value', 'value' => number_format($summary['stock_value'], 0), 'sub' => __('health.ph_tile_stock_value_sub'), 'tone' => 'emerald', 'href' => route('health.pharmacy.reports', ['report' => 'valuation'])],
                ];
                $tones = [
                    'teal'    => 'text-teal-700 dark:text-teal-300',
                    'sky'     => 'text-sky-700 dark:text-sky-300',
                    'amber'   => 'text-amber-700 dark:text-amber-300',
                    'orange'  => 'text-orange-700 dark:text-orange-300',
                    'red'     => 'text-red-700 dark:text-red-300',
                    'purple'  => 'text-purple-700 dark:text-purple-300',
                    'emerald' => 'text-emerald-700 dark:text-emerald-300',
                    'gray'    => 'text-gray-700 dark:text-gray-300',
                ];
            @endphp

            @foreach($tiles as $tile)
                <a href="{{ $tile['href'] }}"
                   class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 hover:border-teal-400 dark:hover:border-teal-600 transition">
                    <p class="text-[11px] font-black uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __($tile['label']) }}</p>
                    <p class="mt-1.5 text-2xl font-black {{ $tones[$tile['tone']] }}">{{ $tile['value'] }}</p>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{{ $tile['sub'] }}</p>
                </a>
            @endforeach
        </div>

        {{-- ── Alerts ── --}}
        <div class="grid lg:grid-cols-3 gap-4">

            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h2 class="text-sm font-black">{{ __('health.ph_alerts_near_expiry') }}</h2>
                    <a href="{{ route('health.pharmacy.stock', ['filter' => 'near_expiry']) }}" class="text-[11px] font-bold text-teal-700 dark:text-teal-300 hover:underline">{{ __('health.ph_view_all') }}</a>
                </div>
                @if($nearExpiry->isEmpty())
                    <p class="px-4 py-6 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('health.ph_none') }}</p>
                @else
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($nearExpiry as $batch)
                            <li class="px-4 py-2.5 flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-xs font-bold truncate">{{ $batch->medicine?->display_name ?? '—' }}</p>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400 truncate">
                                        {{ $batch->batch_no ?: __('health.ph_no_batch') }} &middot; {{ rtrim(rtrim(number_format((float) $batch->quantity, 3, '.', ''), '0'), '.') }}
                                    </p>
                                </div>
                                <span class="text-[11px] font-black text-orange-700 dark:text-orange-300 whitespace-nowrap">
                                    {{ __('health.ph_days_left', ['days' => $batch->daysToExpiry()]) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h2 class="text-sm font-black">{{ __('health.ph_alerts_low_stock') }}</h2>
                    <a href="{{ route('health.pharmacy.reports', ['report' => 'low_stock']) }}" class="text-[11px] font-bold text-teal-700 dark:text-teal-300 hover:underline">{{ __('health.ph_view_all') }}</a>
                </div>
                @if(empty($lowStock))
                    <p class="px-4 py-6 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('health.ph_none') }}</p>
                @else
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($lowStock as $row)
                            <li class="px-4 py-2.5 flex items-center justify-between gap-2">
                                <p class="text-xs font-bold truncate">{{ $row['medicine']->display_name }}</p>
                                <span class="text-[11px] font-black text-amber-700 dark:text-amber-300 whitespace-nowrap">
                                    {{ rtrim(rtrim(number_format($row['available'], 3, '.', ''), '0'), '.') }} / {{ rtrim(rtrim(number_format($row['reorder_level'], 3, '.', ''), '0'), '.') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h2 class="text-sm font-black">{{ __('health.ph_alerts_expired') }}</h2>
                    <a href="{{ route('health.pharmacy.stock', ['filter' => 'expired']) }}" class="text-[11px] font-bold text-teal-700 dark:text-teal-300 hover:underline">{{ __('health.ph_view_all') }}</a>
                </div>
                @if($expired->isEmpty())
                    <p class="px-4 py-6 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('health.ph_none') }}</p>
                @else
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($expired as $batch)
                            <li class="px-4 py-2.5 flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-xs font-bold truncate">{{ $batch->medicine?->display_name ?? '—' }}</p>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400 truncate">{{ $batch->batch_no ?: __('health.ph_no_batch') }}</p>
                                </div>
                                <span class="text-[11px] font-black text-red-700 dark:text-red-300 whitespace-nowrap">
                                    {{ $batch->expiry_date?->format('d-m-Y') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        {{-- ── Where to go ── --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @php
                $links = [
                    ['health.pharmacy.medicines', 'health.ph_quick_medicines'],
                    ['health.pharmacy.purchases', 'health.ph_quick_purchase'],
                    ['health.pharmacy.stock', 'health.ph_quick_stock'],
                    ['health.pharmacy.movements', 'health.ph_quick_movements'],
                    ['health.pharmacy.reports', 'health.ph_quick_reports'],
                    ['health.pharmacy.settings', 'health.ph_quick_settings'],
                ];
            @endphp
            @foreach($links as [$route, $label])
                {{-- A link only appears when its route exists AND this person may
                     reach it; navigation is not an upsell surface. --}}
                @if(Route::has($route))
                    <a href="{{ route($route) }}"
                       class="rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-3 py-3 text-center text-xs font-bold hover:border-teal-400 dark:hover:border-teal-600 transition">
                        {{ __($label) }}
                    </a>
                @endif
            @endforeach
        </div>
    </div>
</x-health-layout>
