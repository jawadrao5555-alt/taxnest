<div class="overflow-x-auto">
    <table data-pos-customer-ranking-table class="w-full text-sm table-cards">
        <thead>
            <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                <th scope="col" class="pb-2">#</th>
                <th scope="col" class="pb-2">{{ __('pos.customer_word') }}</th>
                <th scope="col" class="pb-2 text-right">{{ __('pos.th_visits') }}</th>
                <th scope="col" class="pb-2 text-right">{{ __('pos.th_spent') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rankings as $customer)
                <tr class="border-b border-gray-50 dark:border-gray-800">
                    <td class="py-2.5 text-gray-400" data-label="#">{{ $firstRank + $loop->index }}</td>
                    <td class="py-2.5 font-medium text-gray-900 dark:text-white" data-label="{{ __('pos.customer_word') }}">{{ $customer->name }}</td>
                    <td class="py-2.5 text-right text-gray-700 dark:text-gray-300" data-label="{{ __('pos.th_visits') }}">{{ $customer->count }}</td>
                    <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white" data-label="{{ __('pos.th_spent') }}">PKR {{ number_format($customer->revenue) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('pos.walk_in_only_no_named') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
