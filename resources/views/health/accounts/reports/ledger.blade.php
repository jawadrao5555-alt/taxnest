@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_rep_ledger') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ $report['account']?->displayName() }} · {{ $from }} &rarr; {{ $to }}
                </p>
            </div>
            <a href="{{ route('health.accounts.reports') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
        </div>

        @include('health.accounts.reports.partials.filters', ['route' => 'health.accounts.reports.ledger', 'mode' => 'range'])

        <form method="GET" action="{{ route('health.accounts.reports.ledger') }}" class="flex flex-wrap gap-2 items-end">
            <input type="hidden" name="from" value="{{ $from }}">
            <input type="hidden" name="to" value="{{ $to }}">
            <div class="flex-1 min-w-[240px]">
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_account') }}</label>
                <select name="account_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}" @selected($accountId === (int) $account->id)>{{ $account->code }} — {{ $account->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.apply') }}</button>
        </form>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach([
                ['health.acc_opening', $report['opening']],
                ['health.acc_debit', $report['debit']],
                ['health.acc_credit', $report['credit']],
                ['health.acc_closing', $report['closing']],
            ] as [$label, $value])
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __($label) }}</div>
                    <div class="mt-1 font-black">{{ $money($value) }}</div>
                </div>
            @endforeach
        </div>

        @if($report['truncated'] ?? false)
            <div class="rounded-2xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-3 text-xs text-amber-900 dark:text-amber-200">
                {{ __('health.acc_ledger_truncated') }}
            </div>
        @endif

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_date') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_journal_no') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_memo') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_source') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_debit') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_credit') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($report['rows'] as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                <td class="px-3 py-2.5 whitespace-nowrap">{{ $row['date'] }}</td>
                                <td class="px-3 py-2.5 font-bold">
                                    <a href="{{ route('health.accounts.journal', $row['journal_id']) }}" class="text-teal-700 dark:text-teal-300">{{ $row['journal_no'] }}</a>
                                </td>
                                <td class="px-3 py-2.5 text-gray-600 dark:text-gray-300">{{ $row['memo'] }}</td>
                                <td class="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row['source_type'] ? __('health.jrn_src_' . $row['source_type']) : '—' }}
                                    @if($row['source_reference'])
                                        <span class="block font-mono">{{ $row['source_reference'] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-end">{{ $row['debit'] > 0 ? $money($row['debit']) : '' }}</td>
                                <td class="px-3 py-2.5 text-end">{{ $row['credit'] > 0 ? $money($row['credit']) : '' }}</td>
                                <td class="px-3 py-2.5 text-end font-bold">{{ $money($row['balance']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.acc_no_activity') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-health-layout>
