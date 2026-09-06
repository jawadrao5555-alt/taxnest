@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_rep_recv') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.acc_as_at') }} {{ $asAt }}</p>
            </div>
            <a href="{{ route('health.accounts.reports') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
        </div>

        @include('health.accounts.reports.partials.filters', ['route' => 'health.accounts.reports.receivables', 'mode' => 'as_at'])

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
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_date') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.bill_no') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.patient') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.mrn') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_days') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_outstanding') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($report['rows'] as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                <td class="px-3 py-2.5 whitespace-nowrap">{{ $row['date'] }}</td>
                                <td class="px-3 py-2.5 font-bold">
                                    <a href="{{ route('health.billing.bill', $row['bill_id']) }}" class="text-teal-700 dark:text-teal-300">{{ $row['bill_no'] }}</a>
                                </td>
                                <td class="px-3 py-2.5">{{ $row['patient'] }}</td>
                                <td class="px-3 py-2.5 font-mono text-xs">{{ $row['mrn'] }}</td>
                                <td class="px-3 py-2.5 text-end {{ $row['days'] > 90 ? 'text-rose-700 dark:text-rose-300 font-bold' : '' }}">{{ $row['days'] }}</td>
                                <td class="px-3 py-2.5 text-end font-bold">{{ $money($row['amount']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.acc_nothing_owed') }}</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="bg-gray-50 dark:bg-gray-900/40 font-black">
                        <tr>
                            <td class="px-3 py-2.5" colspan="5">{{ __('health.total') }}</td>
                            <td class="px-3 py-2.5 text-end">{{ $money($report['total']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</x-health-layout>
