@php
    use App\Models\HealthCashierShift;
    use App\Models\HealthPayment;

    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.shift_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.shift_subtitle') }}</p>
            </div>
            <a href="{{ route('health.billing') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.bill_counter_title') }}</a>
        </div>

        @if($open)
            {{-- The live drawer. Expected cash is computed here the same way it is
                 computed at close, so the number does not move when the button is
                 pressed. --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-black">{{ __('health.shift_open_now') }}</h2>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.shift_opened_at') }}: {{ optional($open->opened_at)->format('d M Y, h:i A') }}</span>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    @foreach([
                        ['health.shift_opening_float', $open->opening_float, ''],
                        ['health.shift_cash_in', $openTotals['cash_in'], 'text-emerald-700 dark:text-emerald-300'],
                        ['health.shift_cash_out', $openTotals['cash_out'], 'text-rose-700 dark:text-rose-300'],
                        ['health.shift_expected_cash', (float) $open->opening_float + $openTotals['cash_net'], 'text-teal-700 dark:text-teal-300'],
                        ['health.shift_receipts', $openTotals['count'], ''],
                    ] as $i => [$label, $value, $tone])
                        <div class="rounded-xl bg-gray-50 dark:bg-gray-900/40 p-3">
                            <div class="text-[11px] text-gray-500 dark:text-gray-400 font-bold uppercase tracking-wide">{{ __($label) }}</div>
                            <div class="mt-0.5 font-black {{ $tone }}">{{ $i === 4 ? (int) $value : $money($value) }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2 text-start">{{ __('health.pay_method') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('health.shift_in') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('health.shift_out') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('health.shift_net') }}</th>
                                <th class="px-3 py-2 text-end">{{ __('health.count') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($openTotals['by_method'] as $method => $row)
                                @if($row['count'] > 0)
                                    <tr>
                                        <td class="px-3 py-2 font-bold">{{ __(HealthPayment::methodLabelKey($method)) }}</td>
                                        <td class="px-3 py-2 text-end">{{ $money($row['in']) }}</td>
                                        <td class="px-3 py-2 text-end">{{ $money($row['out']) }}</td>
                                        <td class="px-3 py-2 text-end font-bold">{{ $money($row['net']) }}</td>
                                        <td class="px-3 py-2 text-end text-gray-500">{{ $row['count'] }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($mayCharge)
                    <form method="POST" action="{{ route('health.billing.shifts.close', $open->id) }}"
                          class="border-t border-gray-100 dark:border-gray-700 pt-4 grid grid-cols-1 md:grid-cols-3 gap-3">
                        @csrf
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.shift_counted_cash') }}</label>
                            <input type="number" step="0.01" min="0" name="counted_cash"
                                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            {{-- Blank is NOT zero: leaving it empty records that nobody
                                 counted, rather than a perfect reconciliation. --}}
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.shift_counted_hint') }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.note') }}</label>
                            <input type="text" name="note" maxlength="300"
                                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div class="flex items-end">
                            <button class="w-full px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.shift_close_action') }}</button>
                        </div>
                    </form>
                @endif
            </div>
        @elseif($mayCharge)
            <form method="POST" action="{{ route('health.billing.shifts.open') }}"
                  class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.shift_opening_float') }}</label>
                    <input type="number" step="0.01" min="0" name="opening_float" value="0"
                           class="w-40 px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                </div>
                <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.shift_open_action') }}</button>
            </form>
        @endif

        {{-- History --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2.5 text-start">{{ __('health.shift_cashier') }}</th>
                        <th class="px-4 py-2.5 text-start">{{ __('health.shift_opened_at') }}</th>
                        <th class="px-4 py-2.5 text-start">{{ __('health.shift_closed_at') }}</th>
                        <th class="px-4 py-2.5 text-end">{{ __('health.shift_expected_cash') }}</th>
                        <th class="px-4 py-2.5 text-end">{{ __('health.shift_counted_cash') }}</th>
                        <th class="px-4 py-2.5 text-end">{{ __('health.shift_variance') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($shifts as $s)
                        <tr>
                            <td class="px-4 py-2.5 font-bold">{{ $s->user->name ?? '—' }}</td>
                            <td class="px-4 py-2.5">{{ optional($s->opened_at)->format('d M Y, h:i A') }}</td>
                            <td class="px-4 py-2.5">
                                @if($s->closed_at)
                                    {{ $s->closed_at->format('d M Y, h:i A') }}
                                @else
                                    <span class="text-[11px] font-bold px-2 py-1 rounded-lg bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">{{ __('health.shift_still_open') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-end">{{ $s->expected_cash === null ? '—' : $money($s->expected_cash) }}</td>
                            <td class="px-4 py-2.5 text-end">
                                {{-- NULL prints as "not counted", never as 0.00. --}}
                                @if($s->wasCounted())
                                    {{ $money($s->counted_cash) }}
                                @else
                                    <span class="text-xs text-gray-400">{{ __('health.shift_not_counted') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-end font-bold {{ $s->variance === null ? 'text-gray-400' : ((float) $s->variance == 0.0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300') }}">
                                {{ $s->variance === null ? '—' : $money($s->variance) }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">{{ __('health.shift_none_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-health-layout>
