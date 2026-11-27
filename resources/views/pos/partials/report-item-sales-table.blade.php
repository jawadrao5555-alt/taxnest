<table class="item-sales-table w-full text-sm" data-pos-item-sales-rows>
    <thead>
        <tr class="border-b border-gray-200 text-gray-800 dark:border-gray-700 dark:text-gray-200">
            <th scope="col" class="p-2 text-left">{{ __('pos.receipt_item') }}</th>
            <th scope="col" class="p-2 text-right">{{ __('pos.receipt_qty') }}</th>
            <th scope="col" class="p-2 text-right">{{ __('pos.receipt_subtotal') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse($itemSales['rows'] as $line)
            <tr class="border-b border-gray-100 dark:border-gray-800">
                <td class="p-2">{{ $line->item_name }}</td>
                <td class="p-2 text-right">{{ rtrim(rtrim(number_format($line->total_qty, 2), '0'), '.') }}</td>
                <td class="p-2 text-right">PKR {{ number_format($line->total_revenue, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="p-3 text-center">{{ __('pos.no_data') }}</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr class="font-semibold">
            <th scope="row" class="p-2 text-left">{{ __('pos.receipt_total') }}</th>
            <td class="p-2 text-right">{{ rtrim(rtrim(number_format($itemSales['quantity'], 2), '0'), '.') }}</td>
            <td class="p-2 text-right">PKR {{ number_format($itemSales['subtotal'], 2) }}</td>
        </tr>
    </tfoot>
</table>
