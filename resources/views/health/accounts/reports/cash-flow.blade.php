@php
    $money = fn ($v) => number_format((float) $v, 2);
    $sections = collect($report['rows'])->groupBy('section');
@endphp
<x-health-layout>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_rep_cash') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $from }} &rarr; {{ $to }}</p>
            </div>
            <a href="{{ route('health.accounts.reports') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
        </div>

        @include('health.accounts.reports.partials.filters', ['route' => 'health.accounts.reports.cash-flow', 'mode' => 'range'])

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach([
                ['health.acc_opening_cash', $report['opening']],
                ['health.acc_cash_in', $report['in']],
                ['health.acc_cash_out', $report['out']],
                ['health.acc_closing_cash', $report['closing']],
            ] as [$label, $value])
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __($label) }}</div>
                    <div class="mt-1 font-black">{{ $money($value) }}</div>
                </div>
            @endforeach
        </div>

        {{-- Direct method: what the money was actually FOR, taken from the other
             side of every cash journal. An indirect statement reconciled from
             profit is correct and tells a hospital owner nothing they can act on. --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700/60">
            @forelse($sections as $section => $rows)
                <div class="p-4 space-y-1.5">
                    <h2 class="font-black text-sm uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.acc_flow_' . $section) }}</h2>
                    @foreach($rows as $row)
                        <div class="flex items-center justify-between text-sm">
                            <a href="{{ route('health.accounts.reports.ledger', ['account_id' => $row['id'], 'from' => $from, 'to' => $to]) }}"
                               class="text-teal-700 dark:text-teal-300">{{ $row['name'] }}</a>
                            <span class="{{ $row['amount'] >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                                {{ $money($row['amount']) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @empty
                <p class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.acc_no_activity') }}</p>
            @endforelse

            <div class="p-4 flex items-center justify-between text-lg font-black">
                <span>{{ __('health.acc_net_cash') }}</span>
                <span class="{{ $report['net'] >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">{{ $money($report['net']) }}</span>
            </div>
        </div>
    </div>
</x-health-layout>
