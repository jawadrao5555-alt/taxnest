@php
    use App\Models\HealthCharge;
    use App\Models\HealthPayment;
    use App\Models\HealthTaxCategory;

    $money = fn ($v) => number_format((float) $v, 2);
    $s = $summary;
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.dayclose_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.dayclose_subtitle') }}</p>
            </div>
            <div class="flex gap-2">
                <button type="button" onclick="window.print()" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.print') }}</button>
                <a href="{{ route('health.billing') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.bill_counter_title') }}</a>
            </div>
        </div>

        <form method="GET" action="{{ route('health.billing.day-close') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 grid grid-cols-1 md:grid-cols-5 gap-3">
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.date') }}</label>
                <input type="date" name="date" value="{{ $date }}"
                       class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.from') }}</label>
                <input type="date" name="from" value="{{ $from }}"
                       class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.to') }}</label>
                <input type="date" name="to" value="{{ $to }}"
                       class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.branch') }}</label>
                <select name="branch_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">{{ __('health.all') }}</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($branchId === (int) $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <button class="w-full px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.apply') }}</button>
            </div>
        </form>

        {{-- The three questions a day-close answers: what was billed, what was
             collected, what is still owed. All from the same rows. --}}
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
            @foreach([
                ['health.dayclose_bills', $s['bill_count'], true],
                ['health.dayclose_billed', $s['billed'], false],
                ['health.dayclose_collected', $s['payments']['in'], false],
                ['health.dayclose_refunded', $s['payments']['out'], false],
                ['health.dayclose_third_party', $s['third_party'], false],
                ['health.dayclose_outstanding', $s['outstanding'], false],
            ] as [$label, $value, $isCount])
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-[11px] text-gray-500 dark:text-gray-400 font-bold uppercase tracking-wide">{{ __($label) }}</div>
                    <div class="mt-1 text-base font-black">{{ $isCount ? (int) $value : $money($value) }}</div>
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {{-- Payment methods --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <h2 class="px-4 py-3 font-black border-b border-gray-100 dark:border-gray-700">{{ __('health.dayclose_by_method') }}</h2>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ __('health.pay_method') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('health.shift_in') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('health.shift_out') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('health.shift_net') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($s['payments']['by_method'] as $method => $row)
                            @if($row['count'] > 0)
                                <tr>
                                    <td class="px-4 py-2 font-bold">{{ __(HealthPayment::methodLabelKey($method)) }}</td>
                                    <td class="px-4 py-2 text-end">{{ $money($row['in']) }}</td>
                                    <td class="px-4 py-2 text-end">{{ $money($row['out']) }}</td>
                                    <td class="px-4 py-2 text-end font-bold">{{ $money($row['net']) }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50 dark:bg-gray-900/40 font-black">
                        <tr>
                            <td class="px-4 py-2">{{ __('health.total') }}</td>
                            <td class="px-4 py-2 text-end">{{ $money($s['payments']['in']) }}</td>
                            <td class="px-4 py-2 text-end">{{ $money($s['payments']['out']) }}</td>
                            <td class="px-4 py-2 text-end">{{ $money($s['payments']['net']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Regulatory split + filing state --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-4">
                <div>
                    <h2 class="font-black mb-2">{{ __('health.bill_treatment_split') }}</h2>
                    <div class="grid grid-cols-3 gap-3 text-sm">
                        @foreach(HealthTaxCategory::TREATMENTS as $t)
                            <div class="rounded-xl bg-gray-50 dark:bg-gray-900/40 p-3">
                                <div class="text-[11px] text-gray-500 dark:text-gray-400 font-bold uppercase tracking-wide">{{ __(HealthTaxCategory::treatmentLabelKey($t)) }}</div>
                                <div class="mt-0.5 font-black">{{ $money($s['treatment'][$t] ?? 0) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div>
                    <h2 class="font-black mb-2">{{ __('health.fbr') }}</h2>
                    <div class="grid grid-cols-4 gap-3 text-sm">
                        @foreach([
                            ['health.fbr_st_submitted', $s['fbr']['filed'], 'text-emerald-700 dark:text-emerald-300'],
                            ['health.fbr_eligible_count', $s['fbr']['eligible'], ''],
                            ['health.fbr_st_pending', $s['fbr']['pending'], 'text-amber-700 dark:text-amber-300'],
                            ['health.fbr_st_failed', $s['fbr']['failed'], 'text-rose-700 dark:text-rose-300'],
                        ] as [$label, $value, $tone])
                            <div class="rounded-xl bg-gray-50 dark:bg-gray-900/40 p-3">
                                <div class="text-[11px] text-gray-500 dark:text-gray-400 font-bold uppercase tracking-wide">{{ __($label) }}</div>
                                <div class="mt-0.5 font-black {{ $tone }}">{{ (int) $value }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Department + category, over the chosen range, off frozen lines. --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <h2 class="px-4 py-3 font-black border-b border-gray-100 dark:border-gray-700">{{ __('health.dayclose_by_department') }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2 text-start">{{ __('health.department') }}</th>
                                <th class="px-4 py-2 text-end">{{ __('health.led_net') }}</th>
                                <th class="px-4 py-2 text-end">{{ __('health.tax') }}</th>
                                <th class="px-4 py-2 text-end">{{ __('health.total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($departments as $row)
                                <tr>
                                    <td class="px-4 py-2 font-bold">{{ $row['department_name'] ?: __('health.dayclose_no_department') }}</td>
                                    <td class="px-4 py-2 text-end">{{ $money($row['net']) }}</td>
                                    <td class="px-4 py-2 text-end">{{ $money($row['tax']) }}</td>
                                    <td class="px-4 py-2 text-end font-bold">{{ $money($row['total']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">{{ __('health.dayclose_nothing') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <h2 class="px-4 py-3 font-black border-b border-gray-100 dark:border-gray-700">{{ __('health.dayclose_by_category') }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2 text-start">{{ __('health.led_category') }}</th>
                                <th class="px-4 py-2 text-start">{{ __('health.tax_treatment') }}</th>
                                <th class="px-4 py-2 text-end">{{ __('health.total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($categories as $row)
                                <tr>
                                    <td class="px-4 py-2 font-bold">{{ __(HealthCharge::categoryLabelKey($row['category'])) }}</td>
                                    <td class="px-4 py-2 text-xs">{{ __(HealthTaxCategory::treatmentLabelKey($row['treatment'])) }}</td>
                                    <td class="px-4 py-2 text-end font-bold">{{ $money($row['total']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">{{ __('health.dayclose_nothing') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Shifts that ran on the chosen day --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <h2 class="px-4 py-3 font-black border-b border-gray-100 dark:border-gray-700">{{ __('health.dayclose_shifts') }}</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ __('health.shift_cashier') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('health.status') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('health.shift_expected_cash') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('health.shift_counted_cash') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('health.shift_variance') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($s['shifts'] as $shift)
                            <tr>
                                <td class="px-4 py-2 font-bold">{{ $shift->user->name ?? '—' }}</td>
                                <td class="px-4 py-2">{{ __($shift->isOpen() ? 'health.shift_still_open' : 'health.shift_closed') }}</td>
                                <td class="px-4 py-2 text-end">{{ $shift->expected_cash === null ? '—' : $money($shift->expected_cash) }}</td>
                                <td class="px-4 py-2 text-end">{{ $shift->wasCounted() ? $money($shift->counted_cash) : __('health.shift_not_counted') }}</td>
                                <td class="px-4 py-2 text-end font-bold">{{ $shift->variance === null ? '—' : $money($shift->variance) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">{{ __('health.shift_none_yet') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-health-layout>
