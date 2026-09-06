@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_rep_trial') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $from }} &rarr; {{ $to }}</p>
            </div>
            <a href="{{ route('health.accounts.reports') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
        </div>

        @include('health.accounts.reports.partials.filters', ['route' => 'health.accounts.reports.trial-balance', 'mode' => 'range'])

        {{-- If this ever says "out of balance" the books are not usable and no
             other report on the workspace can be trusted either. It says so
             loudly rather than printing a tidy table with a wrong total. --}}
        @unless($report['balanced'])
            <div class="rounded-2xl border border-rose-300 dark:border-rose-700 bg-rose-50 dark:bg-rose-900/20 p-4 text-sm font-bold text-rose-800 dark:text-rose-200">
                {{ __('health.acc_unbalanced_note', ['amount' => $money($report['difference'])]) }}
            </div>
        @endunless

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_code') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_account') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_opening') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_debit') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_credit') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_closing') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($report['rows'] as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                <td class="px-3 py-2.5 font-mono text-xs">{{ $row['code'] }}</td>
                                <td class="px-3 py-2.5 font-bold">
                                    <a href="{{ route('health.accounts.reports.ledger', array_merge(request()->query(), ['account_id' => $row['account_id'], 'export' => null])) }}"
                                       class="text-teal-700 dark:text-teal-300">{{ $row['name'] }}</a>
                                </td>
                                <td class="px-3 py-2.5 text-end text-gray-500 dark:text-gray-400">{{ $money($row['opening']) }}</td>
                                <td class="px-3 py-2.5 text-end">{{ $money($row['debit']) }}</td>
                                <td class="px-3 py-2.5 text-end">{{ $money($row['credit']) }}</td>
                                <td class="px-3 py-2.5 text-end font-bold">{{ $money($row['closing']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.acc_no_activity') }}</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="bg-gray-50 dark:bg-gray-900/40 font-black">
                        <tr>
                            <td class="px-3 py-2.5" colspan="3">{{ __('health.total') }}</td>
                            <td class="px-3 py-2.5 text-end">{{ $money($report['totals']['debit']) }}</td>
                            <td class="px-3 py-2.5 text-end">{{ $money($report['totals']['credit']) }}</td>
                            <td class="px-3 py-2.5"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</x-health-layout>
