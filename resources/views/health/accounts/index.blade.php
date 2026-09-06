@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.acc_subtitle') }}</p>
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ $from }} &rarr; {{ $to }}
            </div>
        </div>

        @include('health.accounts.partials.nav')

        {{-- WHAT IS WRONG comes first, on purpose. A finance dashboard that
             opens with the cash figure is a dashboard whose warnings nobody
             ever reads. --}}
        @if(($pending['total'] ?? 0) > 0 || !$trial['balanced'] || abs($suspense) > 0.005)
            <div class="rounded-2xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4 space-y-3">
                <h2 class="font-black text-amber-900 dark:text-amber-200">{{ __('health.acc_attention') }}</h2>
                <ul class="text-sm text-amber-900 dark:text-amber-200 space-y-1 list-disc list-inside">
                    @if(($pending['total'] ?? 0) > 0)
                        <li>{{ __('health.acc_pending_note', ['count' => $pending['total']]) }}</li>
                    @endif
                    @unless($trial['balanced'])
                        <li>{{ __('health.acc_unbalanced_note', ['amount' => $money($trial['difference'])]) }}</li>
                    @endunless
                    @if(abs($suspense) > 0.005)
                        <li>{{ __('health.acc_suspense_note', ['amount' => $money($suspense)]) }}</li>
                    @endif
                </ul>
                @if(($pending['total'] ?? 0) > 0)
                    <form method="POST" action="{{ route('health.accounts.sweep') }}">
                        @csrf
                        <button class="px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold">
                            {{ __('health.acc_post_pending') }}
                        </button>
                    </form>
                @endif
            </div>
        @endif

        {{-- Cash and bank, per pot. One pooled "Bank" figure would make every
             reconciliation impossible. --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach($funds as $fund)
                <a href="{{ route('health.accounts.reports.ledger', ['account_id' => $fund['account']->id]) }}"
                   class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 hover:border-teal-500 transition">
                    <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ $fund['account']->displayName() }}</div>
                    <div class="mt-1 text-lg font-black">{{ $money($fund['balance']) }}</div>
                </a>
            @endforeach
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach([
                ['health.acc_receivables', $receivable, 'health.accounts.reports.receivables'],
                ['health.acc_payables', $payable, 'health.accounts.reports.payables'],
                ['health.acc_advances', $advances, null],
                ['health.acc_doctor_payable', $doctor_payable, 'health.accounts.settlements'],
                ['health.acc_tax_payable', $tax_payable, null],
                ['health.acc_suspense', $suspense, null],
            ] as [$label, $value, $link])
                @php $card = 'rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4'; @endphp
                @if($link)
                    <a href="{{ route($link) }}" class="{{ $card }} hover:border-teal-500 transition">
                        <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __($label) }}</div>
                        <div class="mt-1 font-black">{{ $money($value) }}</div>
                    </a>
                @else
                    <div class="{{ $card }}">
                        <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __($label) }}</div>
                        <div class="mt-1 font-black">{{ $money($value) }}</div>
                    </div>
                @endif
            @endforeach
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="font-black">{{ __('health.acc_this_month') }}</h2>
                    <a href="{{ route('health.accounts.reports.profit-loss') }}" class="text-xs font-bold text-teal-700 dark:text-teal-300">{{ __('health.acc_open') }}</a>
                </div>
                @foreach([
                    ['health.acc_income', $pnl['income_total']],
                    ['health.acc_cost_of_sales', $pnl['cost_of_sales_total']],
                    ['health.acc_gross_profit', $pnl['gross_profit']],
                    ['health.acc_expenses', $pnl['expense_total']],
                ] as [$label, $value])
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500 dark:text-gray-400">{{ __($label) }}</span>
                        <span class="font-bold">{{ $money($value) }}</span>
                    </div>
                @endforeach
                <div class="flex items-center justify-between pt-2 border-t border-gray-200 dark:border-gray-700">
                    <span class="font-black">{{ __('health.acc_net_profit') }}</span>
                    <span class="font-black {{ $pnl['net_profit'] >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                        {{ $money($pnl['net_profit']) }}
                    </span>
                </div>
            </div>

            <div class="lg:col-span-2 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="font-black">{{ __('health.acc_recent_journals') }}</h2>
                    <a href="{{ route('health.accounts.journals') }}" class="text-xs font-bold text-teal-700 dark:text-teal-300">{{ __('health.acc_open') }}</a>
                </div>
                @if($recentJournals->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('health.acc_no_journals') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <tbody>
                                @foreach($recentJournals as $journal)
                                    <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                        <td class="py-2 pe-3 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ optional($journal->journal_date)->format('d M') }}</td>
                                        <td class="py-2 pe-3 font-bold">
                                            <a href="{{ route('health.accounts.journal', $journal->id) }}" class="text-teal-700 dark:text-teal-300">{{ $journal->journal_no }}</a>
                                        </td>
                                        <td class="py-2 pe-3 text-gray-600 dark:text-gray-300">{{ $journal->memo }}</td>
                                        <td class="py-2 text-end font-bold">{{ $money($journal->total_debit) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        @if($period)
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __('health.acc_open_period') }}</div>
                    <div class="font-black">{{ $period->name }}</div>
                </div>
                <a href="{{ route('health.accounts.periods') }}" class="px-4 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.acc_nav_periods') }}</a>
            </div>
        @endif
    </div>
</x-health-layout>
