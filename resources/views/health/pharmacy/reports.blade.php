{{--
    Pharmacy reports.

    Every figure here comes from the same service the hub tiles read, so a
    report can never contradict the alert that sent the pharmacist to it.
--}}
<x-health-layout>
    @php
        $tabs = [
            'low_stock' => 'health.ph_report_low_stock',
            'near_expiry' => 'health.ph_report_near_expiry',
            'expired' => 'health.ph_report_expired',
            'valuation' => 'health.ph_report_valuation',
            'margin' => 'health.ph_report_margin',
            'purchases' => 'health.ph_report_purchases',
            'suppliers' => 'health.ph_report_suppliers',
        ];
        $dated = in_array($report, ['margin', 'purchases'], true);
        $num = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    @endphp

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ph_reports_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.ph_reports_subtitle') }}</p>
        </div>

        {{-- Headline numbers stay on screen whichever report is open, so the
             pharmacist never loses the alert count while drilling in. --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @php
                $cards = [
                    ['health.ph_tile_stock_value', number_format((float) $summary['stock_value'], 2), 'text-teal-700 dark:text-teal-300'],
                    ['health.ph_tile_low_stock', $summary['low_stock'], 'text-amber-700 dark:text-amber-300'],
                    ['health.ph_tile_near_expiry', $summary['near_expiry'], 'text-orange-700 dark:text-orange-300'],
                    ['health.ph_tile_expired', $summary['expired'], 'text-red-700 dark:text-red-300'],
                ];
            @endphp
            @foreach($cards as [$label, $value, $tone])
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-[11px] font-black uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __($label) }}</p>
                    <p class="mt-1 text-xl font-black {{ $tone }}">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap gap-1.5">
            @foreach($tabs as $key => $label)
                <a href="{{ route('health.pharmacy.reports') }}?report={{ $key }}@if($dated && in_array($key, ['margin', 'purchases'], true))&from={{ $from }}&to={{ $to }}@endif"
                   class="px-3.5 py-2 rounded-xl text-xs font-black transition
                          {{ $report === $key
                              ? 'bg-teal-700 text-white'
                              : 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700' }}">
                    {{ __($label) }}
                </a>
            @endforeach
        </div>

        @if($dated)
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <input type="hidden" name="report" value="{{ $report }}">
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_from') }}</label>
                    <input type="date" name="from" value="{{ $from }}" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_to') }}</label>
                    <input type="date" name="to" value="{{ $to }}" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                </div>
                <button type="submit" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    {{ __('health.ph_apply') }}
                </button>
            </form>
        @endif

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($rows->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.ph_report_none') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        @switch($report)

                            {{-- ── Low stock ── --}}
                            @case('low_stock')
                                <thead class="bg-gray-50 dark:bg-gray-900/40 text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    <tr>
                                        <th class="px-4 py-2 text-start font-black">{{ __('health.ph_medicine') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_available') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_f_reorder_level') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_shortfall') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($rows as $row)
                                        <tr>
                                            <td class="px-4 py-2.5 font-bold">{{ $row['medicine']->display_name }}</td>
                                            <td class="px-4 py-2.5 text-end {{ $row['available'] <= 0 ? 'text-red-700 dark:text-red-300 font-black' : '' }}">{{ $num($row['available']) }}</td>
                                            <td class="px-4 py-2.5 text-end text-gray-500 dark:text-gray-400">{{ $num($row['reorder_level']) }}</td>
                                            <td class="px-4 py-2.5 text-end font-bold text-amber-700 dark:text-amber-300">{{ $num($row['shortfall']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @break

                            {{-- ── Near expiry / expired ── --}}
                            @case('near_expiry')
                            @case('expired')
                                <thead class="bg-gray-50 dark:bg-gray-900/40 text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    <tr>
                                        <th class="px-4 py-2 text-start font-black">{{ __('health.ph_medicine') }}</th>
                                        <th class="px-4 py-2 text-start font-black">{{ __('health.ph_batch_no') }}</th>
                                        <th class="px-4 py-2 text-start font-black">{{ __('health.ph_expiry') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_qty') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_cost_value') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($rows as $batch)
                                        @php $days = $batch->expiry_date ? (int) now()->startOfDay()->diffInDays($batch->expiry_date->startOfDay(), false) : null; @endphp
                                        <tr>
                                            <td class="px-4 py-2.5 font-bold">{{ $batch->medicine?->display_name ?? '—' }}</td>
                                            <td class="px-4 py-2.5 text-xs">{{ $batch->batch_no ?: __('health.ph_no_batch') }}</td>
                                            <td class="px-4 py-2.5 text-xs">
                                                {{ $batch->expiry_date?->format('d-m-Y') }}
                                                @if($days !== null)
                                                    <span class="ms-1 font-bold {{ $days < 0 ? 'text-red-700 dark:text-red-300' : 'text-orange-700 dark:text-orange-300' }}">
                                                        {{ $days < 0 ? __('health.ph_expired_ago', ['days' => abs($days)]) : __('health.ph_days_left', ['days' => $days]) }}
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-2.5 text-end">{{ $num($batch->quantity) }}</td>
                                            <td class="px-4 py-2.5 text-end">{{ number_format((float) $batch->quantity * (float) $batch->cost_price, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @break

                            {{-- ── Valuation ── --}}
                            @case('valuation')
                                <thead class="bg-gray-50 dark:bg-gray-900/40 text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    <tr>
                                        <th class="px-4 py-2 text-start font-black">{{ __('health.ph_medicine') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_units') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_cost_value') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_retail_value') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($rows as $row)
                                        <tr>
                                            <td class="px-4 py-2.5 font-bold">
                                                {{ trim($row->name . ' ' . ($row->strength ?? '')) }}
                                                @if($row->unit_uom)<span class="text-[11px] text-gray-500 dark:text-gray-400"> / {{ $row->unit_uom }}</span>@endif
                                            </td>
                                            <td class="px-4 py-2.5 text-end">{{ $num($row->units) }}</td>
                                            <td class="px-4 py-2.5 text-end">{{ number_format((float) $row->cost_value, 2) }}</td>
                                            <td class="px-4 py-2.5 text-end">{{ number_format((float) $row->retail_value, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-gray-50 dark:bg-gray-900/40 font-black">
                                    <tr>
                                        <td class="px-4 py-2.5">{{ __('health.ph_total') }}</td>
                                        <td class="px-4 py-2.5 text-end">{{ $num($totals['units']) }}</td>
                                        <td class="px-4 py-2.5 text-end">{{ number_format((float) $totals['cost_value'], 2) }}</td>
                                        <td class="px-4 py-2.5 text-end">{{ number_format((float) $totals['retail_value'], 2) }}</td>
                                    </tr>
                                </tfoot>
                                @break

                            {{-- ── Margin ── --}}
                            @case('margin')
                                <thead class="bg-gray-50 dark:bg-gray-900/40 text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    <tr>
                                        <th class="px-4 py-2 text-start font-black">{{ __('health.ph_medicine') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_units') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_revenue') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_cost') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_profit') }}</th>
                                        <th class="px-4 py-2 text-end font-black">%</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($rows as $row)
                                        <tr>
                                            <td class="px-4 py-2.5 font-bold">{{ $row->item_name }}</td>
                                            <td class="px-4 py-2.5 text-end">{{ $num($row->units) }}</td>
                                            <td class="px-4 py-2.5 text-end">{{ number_format((float) $row->revenue, 2) }}</td>
                                            <td class="px-4 py-2.5 text-end">{{ number_format((float) $row->cost, 2) }}</td>
                                            <td class="px-4 py-2.5 text-end font-bold {{ $row->profit < 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">
                                                {{ number_format((float) $row->profit, 2) }}
                                            </td>
                                            <td class="px-4 py-2.5 text-end text-gray-500 dark:text-gray-400">{{ $row->margin_pct }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-gray-50 dark:bg-gray-900/40 font-black">
                                    <tr>
                                        <td class="px-4 py-2.5">{{ __('health.ph_total') }}</td>
                                        <td class="px-4 py-2.5"></td>
                                        <td class="px-4 py-2.5 text-end">{{ number_format((float) $rows->sum('revenue'), 2) }}</td>
                                        <td class="px-4 py-2.5 text-end">{{ number_format((float) $rows->sum('cost'), 2) }}</td>
                                        <td class="px-4 py-2.5 text-end">{{ number_format((float) $rows->sum('profit'), 2) }}</td>
                                        <td class="px-4 py-2.5"></td>
                                    </tr>
                                </tfoot>
                                @break

                            {{-- ── Purchases ── --}}
                            @case('purchases')
                                <thead class="bg-gray-50 dark:bg-gray-900/40 text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    <tr>
                                        <th class="px-4 py-2 text-start font-black">{{ __('health.ph_purchase_date') }}</th>
                                        <th class="px-4 py-2 text-start font-black">{{ __('health.ph_sup_name') }}</th>
                                        <th class="px-4 py-2 text-start font-black">{{ __('health.ph_invoice_ref') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_purchase_total') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($rows as $order)
                                        <tr>
                                            <td class="px-4 py-2.5 text-xs">{{ $order->order_date ? \Illuminate\Support\Carbon::parse($order->order_date)->format('d-m-Y') : '' }}</td>
                                            <td class="px-4 py-2.5 font-bold">{{ $order->supplier?->name ?? __('health.ph_no_supplier') }}</td>
                                            <td class="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400">{{ $order->reference_number ?: $order->order_number }}</td>
                                            <td class="px-4 py-2.5 text-end font-bold">{{ number_format((float) $order->total_amount, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-gray-50 dark:bg-gray-900/40 font-black">
                                    <tr>
                                        <td class="px-4 py-2.5" colspan="3">{{ __('health.ph_total') }}</td>
                                        <td class="px-4 py-2.5 text-end">{{ number_format((float) $rows->sum('total_amount'), 2) }}</td>
                                    </tr>
                                </tfoot>
                                @break

                            {{-- ── Supplier balances ── --}}
                            @case('suppliers')
                                <thead class="bg-gray-50 dark:bg-gray-900/40 text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    <tr>
                                        <th class="px-4 py-2 text-start font-black">{{ __('health.ph_sup_name') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_billed') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_paid') }}</th>
                                        <th class="px-4 py-2 text-end font-black">{{ __('health.ph_balance') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($rows as $row)
                                        <tr>
                                            <td class="px-4 py-2.5">
                                                <p class="font-bold">{{ $row->name }}</p>
                                                @if($row->phone)<p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $row->phone }}</p>@endif
                                            </td>
                                            <td class="px-4 py-2.5 text-end">{{ number_format((float) $row->billed, 2) }}</td>
                                            <td class="px-4 py-2.5 text-end">{{ number_format((float) $row->paid, 2) }}</td>
                                            <td class="px-4 py-2.5 text-end font-black {{ $row->balance > 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">
                                                {{ number_format((float) $row->balance, 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-gray-50 dark:bg-gray-900/40 font-black">
                                    <tr>
                                        <td class="px-4 py-2.5">{{ __('health.ph_total') }}</td>
                                        <td class="px-4 py-2.5 text-end">{{ number_format((float) $rows->sum('billed'), 2) }}</td>
                                        <td class="px-4 py-2.5 text-end">{{ number_format((float) $rows->sum('paid'), 2) }}</td>
                                        <td class="px-4 py-2.5 text-end">{{ number_format((float) $rows->sum('balance'), 2) }}</td>
                                    </tr>
                                </tfoot>
                                @break
                        @endswitch
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-health-layout>
