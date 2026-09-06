@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_rep_pay') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.acc_as_at') }} {{ $asAt }}</p>
            </div>
            <a href="{{ route('health.accounts.reports') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
        </div>

        @include('health.accounts.reports.partials.filters', ['route' => 'health.accounts.reports.payables', 'mode' => 'as_at'])

        {{-- Two numbers, side by side, on purpose. The supplier list is built from
             purchases and payments; the control balance comes from the ledger. If
             they disagree something has been recorded on one side only, and the
             screen says so instead of quietly showing whichever is nicer. --}}
        @if($report['control_balance'] !== null && abs($report['control_balance'] - $report['total']) > 0.5)
            <div class="rounded-2xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4 text-sm text-amber-900 dark:text-amber-200">
                {{ __('health.acc_payable_mismatch', [
                    'ledger' => $money($report['control_balance']),
                    'list' => $money($report['total']),
                ]) }}
            </div>
        @endif

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            @foreach($report['buckets'] as $key => $value)
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __('health.acc_bucket_' . $key) }}</div>
                    <div class="mt-1 font-black">{{ $money($value) }}</div>
                </div>
            @endforeach
        </div>

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_supplier') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_advance') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_outstanding') }}</th>
                            <th class="px-3 py-2.5 text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($report['rows'] as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                <td class="px-3 py-2.5 font-bold">{{ $row['supplier'] }}</td>
                                <td class="px-3 py-2.5 text-end text-gray-500 dark:text-gray-400">{{ $money($row['advance']) }}</td>
                                <td class="px-3 py-2.5 text-end font-bold">{{ $money($row['outstanding']) }}</td>
                                <td class="px-3 py-2.5 text-end">
                                    <a href="{{ route('health.accounts.reports.supplier', $row['supplier_id']) }}"
                                       class="text-xs font-bold text-teal-700 dark:text-teal-300">{{ __('health.acc_statement') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.acc_nothing_owed') }}</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="bg-gray-50 dark:bg-gray-900/40 font-black">
                        <tr>
                            <td class="px-3 py-2.5" colspan="2">{{ __('health.total') }}</td>
                            <td class="px-3 py-2.5 text-end">{{ $money($report['total']) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</x-health-layout>
