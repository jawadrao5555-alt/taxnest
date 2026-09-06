@php
    $money = fn ($v) => number_format((float) $v, 2);

    /* Purchases and payments interleaved by date, the way a supplier reads
       their own ledger. Anything else forces a manual match. */
    $rows = collect();
    foreach ($purchases as $p) {
        $rows->push([
            'date' => $p->received_date ?: $p->order_date,
            'kind' => 'purchase',
            'reference' => $p->po_number,
            'debit' => (float) $p->total_amount,
            'credit' => 0.0,
        ]);
    }
    foreach ($payments as $p) {
        $rows->push([
            'date' => $p->paid_on,
            'kind' => 'payment',
            'reference' => $p->reference ?? '',
            'debit' => 0.0,
            'credit' => (float) $p->amount,
        ]);
    }
    $rows = $rows->sortBy('date')->values();
@endphp
<x-health-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ $supplier->name }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $from }} &rarr; {{ $to }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('health.accounts.reports.supplier', array_merge([$supplier->id], request()->query(), ['export' => 'csv'])) }}"
                   class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.export_csv') }}</a>
                <a href="{{ route('health.accounts.reports.payables') }}"
                   class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach([
                ['health.acc_purchased', $billed],
                ['health.acc_paid', $paid],
                ['health.acc_returned', $returns],
                ['health.acc_balance_due', $balance],
            ] as [$label, $value])
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __($label) }}</div>
                    <div class="mt-1 font-black">{{ $money($value) }}</div>
                </div>
            @endforeach
        </div>

        {{-- The balance is lifetime, the rows are the window. Said out loud so
             nobody reads the two as the same arithmetic. --}}
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.acc_statement_scope_note') }}</p>

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_date') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_kind') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_reference') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_debit') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_credit') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                <td class="px-3 py-2.5 whitespace-nowrap">{{ $row['date'] }}</td>
                                <td class="px-3 py-2.5 text-xs">{{ __('health.acc_' . $row['kind']) }}</td>
                                <td class="px-3 py-2.5 font-mono text-xs">{{ $row['reference'] }}</td>
                                <td class="px-3 py-2.5 text-end">{{ $row['debit'] > 0 ? $money($row['debit']) : '' }}</td>
                                <td class="px-3 py-2.5 text-end">{{ $row['credit'] > 0 ? $money($row['credit']) : '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.acc_no_activity') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-health-layout>
