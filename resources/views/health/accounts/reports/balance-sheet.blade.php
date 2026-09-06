@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_rep_bs') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.acc_as_at') }} {{ $asAt }}</p>
            </div>
            <a href="{{ route('health.accounts.reports') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
        </div>

        @include('health.accounts.reports.partials.filters', ['route' => 'health.accounts.reports.balance-sheet', 'mode' => 'as_at'])

        @unless($report['balanced'])
            <div class="rounded-2xl border border-rose-300 dark:border-rose-700 bg-rose-50 dark:bg-rose-900/20 p-4 text-sm font-bold text-rose-800 dark:text-rose-200">
                {{ __('health.acc_bs_unbalanced', ['amount' => $money($report['difference'])]) }}
            </div>
        @endunless

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-1.5">
                <h2 class="font-black text-sm uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.acc_type_asset') }}</h2>
                @foreach($report['assets'] as $line)
                    <div class="flex items-center justify-between text-sm">
                        <a href="{{ route('health.accounts.reports.ledger', ['account_id' => $line['id']]) }}"
                           class="text-teal-700 dark:text-teal-300">{{ $line['name'] }}</a>
                        <span>{{ $money($line['amount']) }}</span>
                    </div>
                @endforeach
                <div class="flex items-center justify-between pt-1.5 border-t border-gray-200 dark:border-gray-700 font-black">
                    <span>{{ __('health.total') }}</span>
                    <span>{{ $money($report['asset_total']) }}</span>
                </div>
            </div>

            <div class="space-y-4">
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-1.5">
                    <h2 class="font-black text-sm uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.acc_type_liability') }}</h2>
                    @foreach($report['liabilities'] as $line)
                        <div class="flex items-center justify-between text-sm">
                            <a href="{{ route('health.accounts.reports.ledger', ['account_id' => $line['id']]) }}"
                               class="text-teal-700 dark:text-teal-300">{{ $line['name'] }}</a>
                            <span>{{ $money($line['amount']) }}</span>
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between pt-1.5 border-t border-gray-200 dark:border-gray-700 font-black">
                        <span>{{ __('health.total') }}</span>
                        <span>{{ $money($report['liability_total']) }}</span>
                    </div>
                </div>

                {{-- This year's profit is shown as its own equity line rather than
                     folded silently into retained earnings. Mid-year the two are
                     different things, and an owner comparing this against the P&L
                     needs to see the figure they already recognise. --}}
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-1.5">
                    <h2 class="font-black text-sm uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.acc_type_equity') }}</h2>
                    @foreach($report['equity'] as $line)
                        <div class="flex items-center justify-between text-sm">
                            <span>{{ $line['name'] }}</span>
                            <span>{{ $money($line['amount']) }}</span>
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between text-sm">
                        <span>{{ __('health.acc_current_earnings') }}</span>
                        <span>{{ $money($report['current_earnings']) }}</span>
                    </div>
                    <div class="flex items-center justify-between pt-1.5 border-t border-gray-200 dark:border-gray-700 font-black">
                        <span>{{ __('health.total') }}</span>
                        <span>{{ $money($report['equity_total']) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-health-layout>
