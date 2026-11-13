<x-pos-layout>
<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    @php
        $title = match($metric) {
            'revenue' => __('pos.todays_revenue'),
            'customers' => __('pos.new_customers'),
            default => __('pos.orders_word'),
        };
        $isRevenue = $metric === 'revenue';
        $isCustomers = $metric === 'customers';
        $scopeLabel = __('pos.business_day_label') . ' · ' . $date;
        if ($cashierName) {
            $scopeLabel .= ' · ' . __('pos.showing_name', ['name' => $cashierName]);
        }
        if ($isRevenue) {
            $streams = array_filter([
                'pra' => $ledger['pra'],
                'local' => $ledger['local'],
                'exempt' => $ledger['exempt'],
            ]);
            $total = array_sum(array_column($streams, 'sale'));
        }
    @endphp
    <x-pos.report-header :title="$title" :subtitle="$scopeLabel" />
    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4" data-pos-report-summary>
        <x-pos.report-stat :label="$title" :value="$isRevenue ? 'PKR ' . number_format($total, 2) : number_format($rows->total())" />
    </div>
    <x-pos.report-panel class="overflow-x-auto" data-pos-report-results>
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                <tr class="text-start">
                    @if($isRevenue)
                        <th scope="col" class="p-3 text-start">{{ __('pos.reports_word') }}</th>
                        <th scope="col" class="p-3 text-end">{{ __('pos.th_bills') }}</th>
                        <th scope="col" class="p-3 text-end">{{ __('pos.amount_word') }}</th>
                    @else
                        <th scope="col" class="p-3 text-start">#</th>
                        <th scope="col" class="p-3 text-start">{{ $isCustomers ? __('pos.customer_name') : __('pos.order_number') }}</th>
                        @unless($isCustomers)
                            <th scope="col" class="p-3 text-start">{{ __('pos.th_status') }}</th>
                            <th scope="col" class="p-3 text-end">{{ __('pos.amount_word') }}</th>
                        @endunless
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @if($isRevenue)
                    @foreach($streams as $tab => $stream)
                        <tr>
                            <td class="p-3">
                                <a class="font-semibold text-teal-700 hover:underline dark:text-teal-300"
                                   href="{{ route('pos.reports', ['tab' => $tab, 'from' => $date, 'to' => $date, 'cashier' => $cashier ?? 'all']) }}">
                                    {{ __('pos.' . ['pra' => 'khata_pra_stream', 'local' => 'khata_local_stream', 'exempt' => 'exempt_invoices'][$tab]) }}
                                </a>
                            </td>
                            <td class="p-3 text-end tabular-nums">{{ number_format($stream['bills']) }}</td>
                            <td class="p-3 text-end tabular-nums">{{ number_format($stream['sale'], 2) }}</td>
                        </tr>
                    @endforeach
                @else
                    @forelse($rows as $row)
                        <tr>
                            <td class="p-3 tabular-nums">{{ $row->id }}</td>
                            <td class="p-3">{{ $isCustomers ? $row->name : ($row->order_number ?? $row->invoice_number) }}</td>
                            @unless($isCustomers)
                                <td class="p-3">{{ $row->status ?? '—' }}</td>
                                <td class="p-3 text-end tabular-nums">{{ number_format($row->total_amount, 2) }}</td>
                            @endunless
                        </tr>
                    @empty
                        <tr><td colspan="{{ $isCustomers ? 2 : 4 }}" class="p-4">{{ __('pos.no_data') }}</td></tr>
                    @endforelse
                @endif
            </tbody>
        </table>
    </x-pos.report-panel>
    @unless($isRevenue)
        <div class="mt-4">{{ $rows->links() }}</div>
    @endunless
</div>
</x-pos-layout>
