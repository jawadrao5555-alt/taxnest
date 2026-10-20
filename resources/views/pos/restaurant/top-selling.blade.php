<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 py-6" data-restaurant-top-selling="1">
    <a href="{{ route('pos.restaurant.dashboard') }}" class="text-purple-600 dark:text-purple-400 underline">{{ __('pos.back') }}</a>
    <h1 class="text-xl font-bold mt-3 text-gray-900 dark:text-white">{{ __('pos.top_selling_items') }}</h1>
    <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">{{ __('pos.restaurant_top_selling_scope') }}</p>
    <form method="GET" class="flex flex-wrap items-end gap-3 mb-5">
        <label class="text-sm text-gray-700 dark:text-gray-200">{{ __('pos.from') }}<input type="date" name="from" value="{{ $from }}" required class="block rounded-lg dark:bg-gray-800 dark:text-white"></label>
        <label class="text-sm text-gray-700 dark:text-gray-200">{{ __('pos.to') }}<input type="date" name="to" value="{{ $to }}" required class="block rounded-lg dark:bg-gray-800 dark:text-white"></label>
        <button class="px-4 py-2 bg-purple-600 text-white rounded-lg">{{ __('pos.apply') }}</button>
    </form>
    <div class="overflow-x-auto rounded-xl bg-white dark:bg-gray-900 p-4">
        <table class="w-full text-sm text-gray-800 dark:text-gray-100" data-top-selling-summary="1">
            <thead><tr><th class="text-left p-2">#</th><th class="text-left p-2">{{ __('pos.receipt_item') }}</th><th class="text-right p-2">{{ __('pos.receipt_qty') }}</th><th class="text-right p-2">{{ __('pos.kpi_revenue') }}</th><th class="text-right p-2">{{ __('pos.orders_count_label') }}</th></tr></thead>
            <tbody>@forelse($items as $item)<tr class="border-t border-gray-100 dark:border-gray-700">
                <td class="p-2">{{ $items->firstItem() + $loop->index }}</td>
                <td class="p-2"><a class="underline text-purple-700 dark:text-purple-300" href="{{ route('pos.restaurant.top-selling', ['from' => $from, 'to' => $to, 'item' => $item->item_name, 'page' => $items->currentPage()]) }}#item-details">{{ $item->item_name }}</a></td>
                <td class="p-2 text-right">{{ rtrim(rtrim(number_format($item->total_qty, 2), '0'), '.') }}</td><td class="p-2 text-right whitespace-nowrap">PKR {{ number_format($item->total_revenue, 2) }}</td><td class="p-2 text-right">{{ $item->total_orders }}</td>
            </tr>@empty<tr><td colspan="5" class="p-5">{{ __('pos.no_data') }}</td></tr>@endforelse</tbody>
        </table>
        <div class="mt-4">{{ $items->links() }}</div>
    </div>
    @if($lines !== null)
    <section id="item-details" class="mt-6 rounded-xl bg-white dark:bg-gray-900 p-4 text-gray-800 dark:text-gray-100" data-top-selling-details="1">
        <h2 class="font-bold mb-3">{{ $selectedItem }}</h2>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th class="p-2 text-left">{{ __('pos.order_hash') }}</th><th class="p-2 text-left">{{ __('pos.th_date') }}</th><th class="p-2 text-right">{{ __('pos.receipt_qty') }}</th><th class="p-2 text-right">{{ __('pos.amount_word') }}</th></tr></thead><tbody>
        @forelse($lines as $line)<tr class="border-t border-gray-100 dark:border-gray-700"><td class="p-2">
            @if($line->order->posTransaction && (int) $line->order->posTransaction->company_id === (int) app('currentCompanyId'))
                <a class="underline text-purple-700 dark:text-purple-300" href="{{ route('pos.transaction.show', $line->order->posTransaction->id) }}">{{ $line->order->posTransaction->invoice_number }}</a>
            @else {{ $line->order->order_number }} @endif
        </td><td class="p-2 whitespace-nowrap">{{ $line->order->created_at->format('d M Y H:i') }}</td><td class="p-2 text-right">{{ rtrim(rtrim(number_format($line->quantity, 2), '0'), '.') }}</td><td class="p-2 text-right whitespace-nowrap">PKR {{ number_format($line->subtotal, 2) }}</td></tr>
        @empty<tr><td colspan="4" class="p-5">{{ __('pos.no_data') }}</td></tr>@endforelse
        </tbody></table></div><div class="mt-4">{{ $lines->links() }}</div>
    </section>
    @endif
</div>
</x-pos-layout>
