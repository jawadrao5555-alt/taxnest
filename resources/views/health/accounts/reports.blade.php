@php
    [$from, $to] = $window;

    /**
     * The report index.
     *
     * Every report opens on the same window the accountant last thought about,
     * so moving between them does not silently reset the dates and hand them a
     * different answer to the same question.
     */
    $reports = [
        ['health.accounts.reports.trial-balance', 'health.acc_rep_trial', 'health.acc_rep_trial_note'],
        ['health.accounts.reports.ledger', 'health.acc_rep_ledger', 'health.acc_rep_ledger_note'],
        ['health.accounts.reports.profit-loss', 'health.acc_rep_pnl', 'health.acc_rep_pnl_note'],
        ['health.accounts.reports.balance-sheet', 'health.acc_rep_bs', 'health.acc_rep_bs_note'],
        ['health.accounts.reports.cash-flow', 'health.acc_rep_cash', 'health.acc_rep_cash_note'],
        ['health.accounts.reports.receivables', 'health.acc_rep_recv', 'health.acc_rep_recv_note'],
        ['health.accounts.reports.payables', 'health.acc_rep_pay', 'health.acc_rep_pay_note'],
        ['health.accounts.reports.profitability', 'health.acc_rep_profit', 'health.acc_rep_profit_note'],
    ];
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_reports_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.acc_reports_subtitle') }}</p>
        </div>

        @include('health.accounts.partials.nav')

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach($reports as [$name, $label, $note])
                <a href="{{ route($name, ['from' => $from, 'to' => $to]) }}"
                   class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 hover:border-teal-500 transition">
                    <div class="font-black">{{ __($label) }}</div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __($note) }}</p>
                </a>
            @endforeach
        </div>
    </div>
</x-health-layout>
