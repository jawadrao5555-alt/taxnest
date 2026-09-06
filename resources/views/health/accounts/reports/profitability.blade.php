@php
    $money = fn ($v) => number_format((float) $v, 2);
    $unallocated = $report['unallocated'];
@endphp
<x-health-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_rep_profit') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $from }} &rarr; {{ $to }}</p>
            </div>
            <a href="{{ route('health.accounts.reports') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
        </div>

        <form method="GET" action="{{ route('health.accounts.reports.profitability') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.from') }}</label>
                <input type="date" name="from" value="{{ $from }}" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.to') }}</label>
                <input type="date" name="to" value="{{ $to }}" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_group_by') }}</label>
                <select name="dimension" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    @foreach(['department', 'branch', 'doctor'] as $dim)
                        <option value="{{ $dim }}" @selected($dimension === $dim)>{{ __('health.acc_dimension_' . $dim) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button class="flex-1 px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.apply') }}</button>
                <a href="{{ route('health.accounts.reports.profitability', array_merge(request()->query(), ['export' => 'csv'])) }}"
                   class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.export_csv') }}</a>
            </div>
        </form>

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_dimension_' . $dimension) }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_income') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_cost') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_profit') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_margin') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($report['rows'] as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                <td class="px-3 py-2.5 font-bold">{{ $row['name'] }}</td>
                                <td class="px-3 py-2.5 text-end">{{ $money($row['income']) }}</td>
                                <td class="px-3 py-2.5 text-end">{{ $money($row['cost']) }}</td>
                                <td class="px-3 py-2.5 text-end font-bold {{ $row['profit'] >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                                    {{ $money($row['profit']) }}
                                </td>
                                <td class="px-3 py-2.5 text-end text-gray-500 dark:text-gray-400">{{ $row['margin'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.acc_no_activity') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Reported, never apportioned. A hospital told radiology made 3.1
             million must be able to trust it; a spread guess dressed as a fact
             is worse than an honest gap. --}}
        @if(abs($unallocated['income']) > 0.005 || abs($unallocated['cost']) > 0.005)
            <div class="rounded-2xl bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 p-4 text-sm">
                <div class="font-black mb-1">{{ __('health.acc_unallocated') }}</div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{ __('health.acc_unallocated_note') }}</p>
                <div class="flex gap-6">
                    <span>{{ __('health.acc_income') }}: <strong>{{ $money($unallocated['income']) }}</strong></span>
                    <span>{{ __('health.acc_cost') }}: <strong>{{ $money($unallocated['cost']) }}</strong></span>
                </div>
            </div>
        @endif
    </div>
</x-health-layout>
