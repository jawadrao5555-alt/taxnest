@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_rep_pnl') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $from }} &rarr; {{ $to }}</p>
            </div>
            <a href="{{ route('health.accounts.reports') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
        </div>

        @include('health.accounts.reports.partials.filters', ['route' => 'health.accounts.reports.profit-loss', 'mode' => 'range'])

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700/60">
            @foreach([
                ['health.acc_income', $report['income'], $report['income_total']],
                ['health.acc_cost_of_sales', $report['cost_of_sales'], $report['cost_of_sales_total']],
                ['health.acc_expenses', $report['expenses'], $report['expense_total']],
            ] as [$heading, $lines, $subtotal])
                <div class="p-4 space-y-1.5">
                    <h2 class="font-black text-sm uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __($heading) }}</h2>
                    @forelse($lines as $line)
                        <div class="flex items-center justify-between text-sm">
                            <a href="{{ route('health.accounts.reports.ledger', ['account_id' => $line['id'], 'from' => $from, 'to' => $to]) }}"
                               class="text-teal-700 dark:text-teal-300">{{ $line['name'] }}</a>
                            <span>{{ $money($line['amount']) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400">{{ __('health.acc_no_activity') }}</p>
                    @endforelse
                    <div class="flex items-center justify-between pt-1.5 border-t border-gray-200 dark:border-gray-700 font-black text-sm">
                        <span>{{ __('health.total') }}</span>
                        <span>{{ $money($subtotal) }}</span>
                    </div>
                </div>
            @endforeach

            <div class="p-4 space-y-2">
                <div class="flex items-center justify-between text-sm font-bold">
                    <span>{{ __('health.acc_gross_profit') }}</span>
                    <span>{{ $money($report['gross_profit']) }}</span>
                </div>
                <div class="flex items-center justify-between text-lg font-black">
                    <span>{{ __('health.acc_net_profit') }}</span>
                    <span class="{{ $report['net_profit'] >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                        {{ $money($report['net_profit']) }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</x-health-layout>
