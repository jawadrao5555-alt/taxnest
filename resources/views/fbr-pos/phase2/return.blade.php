<x-fbr-pos-layout>
<div class="max-w-4xl mx-auto">
    <div class="flex items-center justify-between mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.return_refund') }}</h1>
            <p class="text-sm text-gray-500">{{ __('pos.original_invoice_colon') }} <strong>#{{ $original->invoice_number }}</strong> ({{ $original->created_at->format('d M Y') }})</p>
        </div>
        <a href="{{ route('fbrpos.show', $original->id) }}" class="text-sm text-blue-600 hover:underline">← {{ __('pos.cancel') }}</a>
    </div>

    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">{{ session('error') }}</div>@endif

    <form method="POST" action="{{ route('fbrpos.phase2.return.process', $original->id) }}" class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
        @csrf
        <h3 class="font-bold mb-3 dark:text-white">{{ __('pos.select_items_to_return') }}</h3>
        <table class="w-full text-sm mb-5 table-cards">
            <thead class="bg-gray-50 dark:bg-gray-700 text-left">
                <tr><th class="px-3 py-2">{{ __('pos.th_item') }}</th><th>{{ __('pos.th_sold_qty') }}</th><th>{{ __('pos.th_returned') }}</th><th>{{ __('pos.th_unit_price') }}</th><th>{{ __('pos.th_return_qty') }}</th></tr>
            </thead>
            <tbody>
                @foreach($original->items as $it)
                    @php $remaining = (float)$it->quantity - (float)$it->returned_quantity; @endphp
                    <tr class="border-t dark:border-gray-700 {{ $remaining <= 0 ? 'opacity-50' : '' }}">
                        <td class="px-3 py-2 font-semibold dark:text-white">{{ $it->item_name }}</td>
                        <td class="dark:text-gray-300">{{ rtrim(rtrim(number_format($it->quantity, 3), '0'), '.') }} {{ $it->uom }}</td>
                        <td class="dark:text-gray-300">{{ rtrim(rtrim(number_format($it->returned_quantity, 3), '0'), '.') }}</td>
                        <td class="dark:text-gray-300">Rs {{ number_format($it->unit_price, 2) }}</td>
                        <td>
                            <input type="hidden" name="items[{{ $loop->index }}][item_id]" value="{{ $it->id }}">
                            <input type="number" name="items[{{ $loop->index }}][return_qty]" value="0" min="0" max="{{ $remaining }}" step="0.001" {{ $remaining <= 0 ? 'disabled' : '' }} class="w-24 border rounded px-2 py-1 text-sm dark:bg-gray-700 dark:text-white">
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="grid sm:grid-cols-2 gap-4 border-t pt-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.refund_method_req') }}</label>
                <select name="refund_method" required class="w-full border rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white">
                    <option value="cash">{{ __('pos.cash_refund') }}</option>
                    <option value="card">{{ __('pos.card_bank_refund') }}</option>
                    <option value="store_credit">{{ __('pos.store_credit') }}</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" onclick="return confirm(@js(__('pos.process_refund_q')))" class="bg-red-600 text-white rounded-lg px-5 py-2.5 font-semibold hover:bg-red-700 w-full">{{ __('pos.process_refund') }}</button>
            </div>
        </div>
    </form>
</div>
</x-fbr-pos-layout>
